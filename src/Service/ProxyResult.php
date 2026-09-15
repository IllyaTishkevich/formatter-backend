<?php

namespace App\Service;

final class ProxyResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?array $data,
        public readonly ?string $error,
        public readonly int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data): self
    {
        return new self(true, $data, null, 200);
    }

    public static function failure(string $error, int $status): self
    {
        return new self(false, null, $error, $status);
    }
}
