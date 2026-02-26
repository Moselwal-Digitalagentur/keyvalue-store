<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

use Moselwal\KeyValueStore\Connection\ValueObject\Endpoint;

final class SentinelResolver
{
    public function __construct(
        private readonly TlsContextBuilder $tlsContextBuilder = new TlsContextBuilder(),
    ) {}

    /**
     * Resolve master endpoint via RedisSentinel.
     *
     * Expected options (same keys as KeyValueConnectionFactory):
     *   sentinel          bool   must be true
     *   sentinel_host     string Sentinel hostname
     *   sentinel_port     int    default 26379
     *   sentinel_service  string master name
     *   sentinel_password string optional Sentinel AUTH password
     *   connectTimeout    float  seconds (alias: timeout)
     *   persistent_id     string optional Sentinel persistent connection ID
     *   tls               bool   enable TLS for the Sentinel connection itself
     *   ca_file, cert_file, key_file, peer_name, verify_peer, verify_peer_name, allow_self_signed
     */
    public function resolveMaster(array $options): Endpoint
    {
        if (empty($options['sentinel'])) {
            throw new \InvalidArgumentException('Sentinel is not enabled in options.');
        }

        $host = (string)($options['sentinel_host'] ?? '');
        $port = (int)($options['sentinel_port'] ?? 26379);
        $service = (string)($options['sentinel_service'] ?? '');
        // Accept both camelCase (primary) and the legacy 'timeout' alias.
        $connectTimeout = (float)($options['connectTimeout'] ?? $options['timeout'] ?? 1.0);

        if ($host === '' || $service === '') {
            throw new \InvalidArgumentException('Sentinel host and sentinel_service must be set.');
        }

        // RedisSentinel uses the same camelCase constructor keys as Redis.
        $sentinelConfig = [
            'host' => $host,
            'port' => $port,
            'connectTimeout' => $connectTimeout,
        ];

        // Only set persistent when an explicit ID is supplied.
        if (isset($options['persistent_id']) && (string)$options['persistent_id'] !== '') {
            $sentinelConfig['persistent'] = (string)$options['persistent_id'];
        }

        if (!empty($options['sentinel_password'])) {
            $sentinelConfig['auth'] = (string)$options['sentinel_password'];
        }

        // TLS for the sentinel connection itself (optional).
        if (!empty($options['tls'])) {
            $tlsContext = $this->tlsContextBuilder->build($options);
            if ($tlsContext !== null) {
                // Prefix with tls:// so phpredis negotiates TLS.
                $sentinelConfig['host'] = 'tls://' . $host;
                // RedisSentinel constructor: ssl key without outer wrapper, like Redis.
                $sentinelConfig['ssl'] = $tlsContext['ssl'];
            }
        }

        /** @phpstan-ignore-next-line */
        $sentinel = new \RedisSentinel($sentinelConfig);

        $addr = $sentinel->getMasterAddrByName($service);
        if (!is_array($addr) || count($addr) < 2) {
            throw new \RuntimeException(
                sprintf('Could not resolve master "%s" via sentinel getMasterAddrByName().', $service)
            );
        }

        return new Endpoint((string)$addr[0], (int)$addr[1], $connectTimeout);
    }
}
