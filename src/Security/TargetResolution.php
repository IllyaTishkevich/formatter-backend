<?php

namespace App\Security;

final class TargetResolution
{
    private function __construct(
        public readonly ?string $error,
        public readonly ?string $host,
        public readonly ?string $ip,
    ) {
    }

    public static function allowed(string $host, string $ip): self
    {
        return new self(null, $host, $ip);
    }

    public static function rejected(string $error): self
    {
        return new self($error, null, null);
    }

    public function isAllowed(): bool
    {
        return $this->error === null;
    }
}
