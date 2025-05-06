<?php

declare(strict_types=1);

namespace PhpMcp\Client\JsonRpc\Params;

use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\Model\ClientInfo;

final readonly class InitializeParams
{
    public function __construct(
        public string $protocolVersion,
        public Capabilities $capabilities,
        public ClientInfo $clientInfo,
        // Add optional processId, rootUri, trace etc. if client supports them
    ) {}

    public function toArray(): array
    {
        // Convert capabilities/info objects to arrays for JSON
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities->toClientArray(), // Use specific method
            'clientInfo' => $this->clientInfo->toArray(),
        ];
    }
}
