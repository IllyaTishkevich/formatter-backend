<?php

namespace App\Service;

use App\Security\SsrfGuard;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Forwards a client-described HTTP request to an arbitrary target on their behalf,
 * after the target has been through SsrfGuard.
 */
class ProxyRequestService
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;
    private const MAX_REQUEST_BODY_BYTES = 2 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 15.0;
    private const FORBIDDEN_REQUEST_HEADERS = ['host', 'content-length', 'connection'];

    private const REASON_PHRASES = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function forward(array $payload): ProxyResult
    {
        $method = strtoupper((string) ($payload['method'] ?? 'GET'));
        $url = trim((string) ($payload['url'] ?? ''));
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $body = $payload['body'] ?? null;

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return ProxyResult::failure("Unsupported method: {$method}", 400);
        }

        if ($body !== null && strlen((string) $body) > self::MAX_REQUEST_BODY_BYTES) {
            return ProxyResult::failure('Request body too large', 413);
        }

        $target = $this->ssrfGuard->resolveTarget($url);

        if (!$target->isAllowed()) {
            return ProxyResult::failure($target->error, 400);
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
            // SsrfGuard yet and could point straight at an internal address
            'max_redirects' => 0,
        ];

        // pin the connection to the exact IP we just validated, so a second DNS lookup
        // performed by the HTTP client itself (DNS rebinding) can't bypass that check
        if (!filter_var($target->host, FILTER_VALIDATE_IP)) {
            $options['resolve'] = [$target->host => $target->ip];
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
                return ProxyResult::failure('Response body too large', 502);
            }

            $content = $response->getContent(false);

            if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                return ProxyResult::failure('Response body too large', 502);
            }

            $time = (int) round((microtime(true) - $start) * 1000);

            return ProxyResult::success([
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'statusText' => $this->extractStatusText($response),
                'headers' => $responseHeaders,
                'body' => $content,
                'time' => $time,
            ]);
        } catch (TransportExceptionInterface $e) {
            return ProxyResult::failure('Request failed: ' . $e->getMessage(), 502);
        } catch (\Throwable $e) {
            return ProxyResult::failure('Request failed: ' . $e->getMessage(), 502);
        }
    }

    private function extractStatusText(ResponseInterface $response): string
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
}
