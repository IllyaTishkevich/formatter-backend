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

        $resolution = $this->resolveTarget($url);

        if ($resolution['error'] !== null) {
            return $this->withCors($this->error($resolution['error'], 400));
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
            // redirects are not auto-followed: a redirect target hasn't been through
            // resolveTarget() yet and could point straight at an internal address
            'max_redirects' => 0,
        ];

        // pin the connection to the exact IP we just validated, so a second DNS lookup
        // performed by the HTTP client itself (DNS rebinding) can't bypass that check
        if (!filter_var($resolution['host'], FILTER_VALIDATE_IP)) {
            $options['resolve'] = [$resolution['host'] => $resolution['ip']];
        }

        if ($body !== null && !in_array($method, ['GET', 'HEAD'], true)) {
            $options['body'] = (string) $body;
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request($method, $url, $options);

            // getStatusCode()/getHeaders(false) never throw, regardless of a 4xx/5xx status -
            // this proxy must hand back error responses too, not just successful ones
            $status = $response->getStatusCode();

            $responseHeaders = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }

            $declaredLength = (int) ($responseHeaders['content-length'] ?? 0);

            if ($declaredLength > self::MAX_RESPONSE_BYTES) {
                return $this->withCors($this->error('Response body too large', 502));
            }

            $content = $response->getContent(false);

            if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                return $this->withCors($this->error('Response body too large', 502));
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

            // "HTTP/1.1 404 Not Found" -> ["HTTP/1.1", "404", "Not Found"]; limit 3 keeps a
            // multi-word phrase intact. HTTP/2 and HTTP/3 responses carry no reason phrase at
            // all, so this simply won't have a 3rd part and falls through to the lookup below.
            $parts = preg_split('/\s+/', $statusLine, 3);

            if (isset($parts[2]) && $parts[2] !== '') {
                return $parts[2];
            }
        } catch (\Throwable $e) {
            // ignore - statusText is a cosmetic extra, never worth failing the request over
        }

        return self::REASON_PHRASES[$response->getStatusCode()] ?? '';
    }

    /**
     * @return array{error: ?string, host: ?string, ip: ?string}
     */
    private function resolveTarget(string $url): array
    {
        if ($url === '') {
            return ['error' => 'URL is required', 'host' => null, 'ip' => null];
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['error' => 'Invalid URL', 'host' => null, 'ip' => null];
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return ['error' => 'Only http and https URLs are allowed', 'host' => null, 'ip' => null];
        }

        $host = $parts['host'];

        if (strtolower($host) === 'localhost') {
            return ['error' => 'Requests to local addresses are not allowed', 'host' => null, 'ip' => null];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = gethostbynamel($host);

            if ($resolved === false || $resolved === []) {
                return ['error' => 'Could not resolve host', 'host' => null, 'ip' => null];
            }

            $ips = $resolved;
        }

        $publicIp = null;

        foreach ($ips as $ip) {
            if (!$this->isForbiddenIp($ip)) {
                $publicIp = $ip;
                break;
            }
        }

        if ($publicIp === null) {
            return ['error' => 'Requests to private or internal addresses are not allowed', 'host' => null, 'ip' => null];
        }

        return ['error' => null, 'host' => $host, 'ip' => $publicIp];
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
