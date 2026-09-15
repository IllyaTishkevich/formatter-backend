<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the diagnostic payload returned by /api/ip: everything the caller's own
 * request reveals about it (ip, headers, client hints, best-effort UA parsing).
 */
class RequestInspector
{
    // never reflect these back, even to the same client that sent them
    private const EXCLUDED_HEADERS = ['cookie', 'authorization', 'host', 'referer'];

    /**
     * @return array<string, mixed>
     */
    public function inspect(Request $request): array
    {
        $headers = $request->headers;
        $userAgent = (string) $headers->get('User-Agent', '');

        $clientHints = array_filter([
            'brands' => $headers->get('Sec-CH-UA'),
            'mobile' => $headers->get('Sec-CH-UA-Mobile'),
            'platform' => $headers->get('Sec-CH-UA-Platform'),
            'platformVersion' => $headers->get('Sec-CH-UA-Platform-Version'),
            'fullVersionList' => $headers->get('Sec-CH-UA-Full-Version-List'),
            'model' => $headers->get('Sec-CH-UA-Model'),
        ], static fn ($value) => $value !== null);

        $rawHeaders = [];
        foreach ($headers->all() as $name => $values) {
            if (in_array(strtolower($name), self::EXCLUDED_HEADERS, true)) {
                continue;
            }

            $rawHeaders[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return [
            'ip' => $request->getClientIp(),
            'ips' => $request->getClientIps(),
            'forwardedFor' => $headers->get('X-Forwarded-For'),
            'userAgent' => $userAgent,
            'browser' => $this->parseUserAgent($userAgent),
            'clientHints' => $clientHints,
            'accept' => $headers->get('Accept'),
            'acceptLanguage' => $headers->get('Accept-Language'),
            'preferredLanguages' => $request->getLanguages(),
            'acceptEncoding' => $headers->get('Accept-Encoding'),
            'dnt' => $headers->get('DNT'),
            'port' => $request->getPort(),
            'scheme' => $request->getScheme(),
            'secure' => $request->isSecure(),
            'protocolVersion' => $request->server->get('SERVER_PROTOCOL'),
            'method' => $request->getMethod(),
            'requestTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'headers' => $rawHeaders,
        ];
    }

    /**
     * Best-effort User-Agent parsing. Order matters: browsers like Edge, Opera and
     * Samsung Internet include "Chrome" and "Safari" tokens in their UA string too,
     * so their patterns must be checked first. Client hints (Sec-CH-UA-*, above) are
     * the more reliable source when the browser sends them.
     *
     * @return array{name: ?string, version: ?string}
     */
    private function parseUserAgent(string $userAgent): array
    {
        $patterns = [
            'Edge' => '/Edg(?:A|iOS)?\/([\d.]+)/',
            'Opera' => '/(?:OPR|Opera)\/([\d.]+)/',
            'Samsung Internet' => '/SamsungBrowser\/([\d.]+)/',
            'Firefox' => '/Firefox\/([\d.]+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/',
            'Safari' => '/Version\/([\d.]+).*Safari/',
            'Internet Explorer' => '/(?:MSIE |Trident.*rv:)([\d.]+)/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return ['name' => $name, 'version' => $matches[1]];
            }
        }

        return ['name' => null, 'version' => null];
    }
}
