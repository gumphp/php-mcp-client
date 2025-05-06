<?php

namespace PhpMcp\Client\JsonRpc\Results;

use PhpMcp\Client\JsonRpc\Result;
use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\Model\ServerInfo;

class InitializeResult extends Result
{
    /**
     * Create a new InitializeResult.
     *
     * @param  ServerInfo  $serverInfo  Server information
     * @param  string  $protocolVersion  Protocol version
     * @param  Capabilities  $capabilities  Server capabilities
     * @param  string|null  $instructions  Optional instructions text
     */
    public function __construct(
        public readonly ServerInfo $serverInfo,
        public readonly string $protocolVersion,
        public readonly Capabilities $capabilities,
        public readonly ?string $instructions = null
    ) {}

    public static function fromArray(array $data): static
    {
        $serverInfo = ServerInfo::fromArray($data['serverInfo']);
        $capabilities = Capabilities::fromServerResponse($data['capabilities']);

        return new static(
            serverInfo: $serverInfo,
            protocolVersion: $data['protocolVersion'],
            capabilities: $capabilities,
            instructions: $data['instructions'] ?? null
        );
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'serverInfo' => $this->serverInfo,
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }
}
