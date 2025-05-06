<?php

declare(strict_types=1);

namespace PhpMcp\Client\JsonRpc\Parameter;

final readonly class SubscribeResourceParams
{
    public function __construct(public string $uri) {}

    public function toArray(): array
    {
        return ['uri' => $this->uri];
    }
}
