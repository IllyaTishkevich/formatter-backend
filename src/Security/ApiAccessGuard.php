<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared source-of-truth for which callers may hit any /api/* endpoint: same-machine
 * callers (127.0.0.1 - the local Symfony server during frontend dev, or manual curl
 * testing on the server itself) plus browser requests whose Origin is the real frontend.
 *
 * Note this only stops casual/browser-based abuse: a non-browser client can still fake
 * an Origin header freely, since nothing here is a secret the caller must prove
 * knowledge of. Treat it as a courtesy gate, not real authentication.
 */
class ApiAccessGuard
{
    /**
     * @param string[] $allowedOrigins
     * @param string[] $allowedClientIps
     */
    public function __construct(
        private readonly array $allowedOrigins,
        private readonly array $allowedClientIps,
    ) {
    }

    public function isRequestAllowed(Request $request): bool
    {
        $clientIp = $request->getClientIp();

        if ($clientIp !== null && in_array($clientIp, $this->allowedClientIps, true)) {
            return true;
        }

        return $this->matchedOrigin($request) !== null;
    }

    public function matchedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return null;
        }

        $origin = rtrim($origin, '/');

        return in_array($origin, $this->allowedOrigins, true) ? $origin : null;
    }

    public function withCors(Request $request, Response $response, string $allowedMethods = 'GET, POST, OPTIONS'): Response
    {
        $origin = $this->matchedOrigin($request);

        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', $allowedMethods);
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
