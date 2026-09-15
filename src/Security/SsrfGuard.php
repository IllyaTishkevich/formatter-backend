<?php

namespace App\Security;

/**
 * Validates a proxy target URL before it's requested: only public http(s) hosts are
 * allowed, resolved up front so the caller can pin the outgoing connection to the exact
 * IP it just validated (a second DNS lookup performed later - DNS rebinding - must not
 * be able to bypass this check).
 */
class SsrfGuard
{
    public function resolveTarget(string $url): TargetResolution
    {
        if ($url === '') {
            return TargetResolution::rejected('URL is required');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return TargetResolution::rejected('Invalid URL');
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return TargetResolution::rejected('Only http and https URLs are allowed');
        }

        $host = $parts['host'];

        if (strtolower($host) === 'localhost') {
            return TargetResolution::rejected('Requests to local addresses are not allowed');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = gethostbynamel($host);

            if ($resolved === false || $resolved === []) {
                return TargetResolution::rejected('Could not resolve host');
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
            return TargetResolution::rejected('Requests to private or internal addresses are not allowed');
        }

        return TargetResolution::allowed($host, $publicIp);
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
}
