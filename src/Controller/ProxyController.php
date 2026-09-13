<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProxyController extends AbstractController
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;
    private const MAX_REQUEST_BODY_BYTES = 2 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 15.0;
    private const FORBIDDEN_REQUEST_HEADERS = ['host', 'content-length', 'connection'];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    #[Route('/api/request', name: 'api_request', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCors(new Response('', Response::HTTP_NO_CONTENT));
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->withCors($this->error('Invalid JSON payload', 400));
        }

        $method = strtoupper((string) ($payload['method'] ?? 'GET'));
        $url = trim((string) ($payload['url'] ?? ''));
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $body = $payload['body'] ?? null;

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->withCors($this->error("Unsupported method: {$method}", 400));
        }

        if ($body !== null && strlen((string) $body) > self::MAX_REQUEST_BODY_BYTES) {
            return $this->withCors($this->error('Request body too large', 413));
        }

        $validationError = $this->validateTargetUrl($url);

        if ($validationError !== null) {
            return $this->withCors($this->error($validationError, 400));
        }

        $outgoingHeaders = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || in_array(strtolower($name), self::FORBIDDEN_REQUEST_HEADERS, true)) {
                continue;
            }

            $outgoingHeaders[$name] = (string) $value;
        }

        $options = [
            'headers' => $outgoingHeaders,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_redirects' => 5,
        ];

        if ($body !== null && !in_array($method, ['GET', 'HEAD'], true)) {
            $options['body'] = (string) $body;
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request($method, $url, $options);

            $content = '';

            foreach ($this->httpClient->stream($response, self::TIMEOUT_SECONDS) as $chunk) {
                $content .= $chunk->getContent();

                if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                    throw new \RuntimeException('Response body exceeds the ' . self::MAX_RESPONSE_BYTES . ' byte limit');
                }
            }

            $status = $response->getStatusCode();

            $responseHeaders = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }

            $time = (int) round((microtime(true) - $start) * 1000);

            return $this->withCors($this->json([
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'statusText' => $this->extractStatusText($response),
                'headers' => $responseHeaders,
                'body' => $content,
                'time' => $time,
            ]));
        } catch (TransportExceptionInterface $e) {
            return $this->withCors($this->error('Request failed: ' . $e->getMessage(), 502));
        } catch (\Throwable $e) {
            return $this->withCors($this->error('Request failed: ' . $e->getMessage(), 502));
        }
    }

    private const REASON_PHRASES = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    private function extractStatusText(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        try {
            $rawHeaders = $response->getInfo('response_headers') ?? [];
            $statusLine = trim($rawHeaders[0] ?? '');

            // HTTP/2 and HTTP/3 responses carry no reason phrase - fall back to a standard lookup
            if (preg_match('#^HTTP/\S+\s+\d+\s*(.+)$#i', $statusLine, $matches)) {
                return trim($matches[1]);
            }
        } catch (\Throwable $e) {
            // ignore - statusText is a cosmetic extra, never worth failing the request over
        }

        return self::REASON_PHRASES[$response->getStatusCode()] ?? '';
    }

    private function validateTargetUrl(string $url): ?string
    {
        if ($url === '') {
            return 'URL is required';
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Invalid URL';
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Only http and https URLs are allowed';
        }

        $host = $parts['host'];

        if (strtolower($host) === 'localhost') {
            return 'Requests to local addresses are not allowed';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = gethostbynamel($host);

            if ($resolved === false) {
                return 'Could not resolve host';
            }

            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if ($this->isForbiddenIp($ip)) {
                return 'Requests to private or internal addresses are not allowed';
            }
        }

        return null;
    }

    private function isForbiddenIp(string $ip): bool
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    private function withCors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
