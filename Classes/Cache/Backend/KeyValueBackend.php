<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Exception;

/**
 * TYPO3 cache backend that replaces the default RedisBackend connection logic
 * with KeyValueConnectionFactory to support TLS/mTLS and Redis Sentinel.
 *
 * Configure via $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][<name>]:
 *
 *   'backend' => \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
 *   'options'  => [
 *       // Standard RedisBackend options (hostname, port, database, password, …) work as usual.
 *       //
 *       // TLS options:
 *       'tls'              => true,
 *       'ca_file'          => '/run/tls/ca.crt',
 *       'cert_file'        => '/run/tls/client.crt',  // mTLS only
 *       'key_file'         => '/run/tls/client.key',  // mTLS only
 *       'peer_name'        => 'redis.internal',
 *       'verify_peer'      => true,
 *       'verify_peer_name' => true,
 *       'allow_self_signed'=> false,
 *       //
 *       // Sentinel options:
 *       'sentinel'         => true,
 *       'sentinel_host'    => 'sentinel',
 *       'sentinel_port'    => 26379,
 *       'sentinel_service' => 'mymaster',
 *       'sentinel_password'=> null,
 *       //
 *       // phpredis 6.x backoff (optional):
 *       'backoff' => [
 *           'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
 *           'base' => 500,
 *           'cap'  => 750,
 *       ],
 *   ],
 */
final class KeyValueBackend extends RedisBackend
{
    private KeyValueConnectionFactory $factory;

    public function __construct(string $context = '')
    {
        parent::__construct($context);
        $this->factory = new KeyValueConnectionFactory();
    }

    /**
     * Override RedisBackend::initializeObject() to use our factory instead of
     * the built-in phpredis connect() call.
     *
     * @throws Exception if the connection cannot be established
     */
    public function initializeObject(): void
    {
        try {
            $this->redis = $this->factory->create($this->buildFactoryOptions());
            $this->connected = true;
        } catch (\RedisException | \InvalidArgumentException $e) {
            $this->connected = false;
            throw new Exception(
                'KeyValueBackend could not connect to Redis: ' . $e->getMessage(),
                1700100001,
                $e
            );
        }
    }

    /**
     * Map RedisBackend's TYPO3-style properties plus any extra 'options' array
     * entries into the KeyValueConnectionFactory option schema.
     *
     * phpredis camelCase keys are primary. TYPO3's AbstractBackend stores the raw
     * backend options array in $this->options; TLS, Sentinel, and backoff keys must
     * be provided there and will override the standard RedisBackend properties.
     */
    private function buildFactoryOptions(): array
    {
        $opts = [
            'host' => $this->hostname,
            'port' => (int)$this->port,
            'connectTimeout' => (float)$this->connectionTimeout,
            'readTimeout' => (float)$this->readTimeout,
            'retryInterval' => (int)$this->retryInterval,
            'database' => (int)$this->database,
            // Persistent: use the database-specific ID so that different databases
            // get distinct persistent connections.
            'persistent' => $this->persistentConnection
                ? ('typo3-cache-' . (string)$this->database)
                : false,
        ];

        if ((string)$this->password !== '') {
            $opts['auth'] = (string)$this->password;
        }

        if (is_array($this->options ?? null)) {
            $opts = array_replace($opts, $this->options);
        }

        return $opts;
    }
}
