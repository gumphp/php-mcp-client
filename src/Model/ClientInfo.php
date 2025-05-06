<?php

declare(strict_types=1);

namespace PhpMcp\Client\Model;

final readonly class ClientInfo
{
    public function __construct(
        public string $name,
        public string $version,
        // Add other optional fields from spec if needed e.g., $supportedLocales
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
