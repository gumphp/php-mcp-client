<?php

declare(strict_types=1);

namespace PhpMcp\Client\Model;

final readonly class ServerInfo
{
    public function __construct(
        public string $name,
        public string $version,
        // Add other optional fields from spec if needed
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'Unknown Server',
            version: $data['version'] ?? 'Unknown Version'
        );
    }
}
