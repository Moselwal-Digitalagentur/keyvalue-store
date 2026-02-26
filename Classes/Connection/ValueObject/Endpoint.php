<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection\ValueObject;

final class Endpoint
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly float $timeout,
    ) {}
}
