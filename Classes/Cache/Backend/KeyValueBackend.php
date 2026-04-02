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

    /**
     * All raw options passed by CacheManager, stored for use in buildFactoryOptions().
     * This includes TLS/sentinel/backoff keys that have no setter in RedisBackend.
     */
    private array $rawOptions = [];

    /**
     * Options that RedisBackend (or AbstractBackend) has setters for.
     * Only these are forwarded to parent::__construct() to avoid the
     * "Invalid cache backend option" InvalidArgumentException.
     */
    private const PARENT_OPTION_KEYS = [
        'hostname', 'port', 'database', 'username', 'password',
        'compression', 'compressionLevel', 'connectionTimeout',
        'persistentConnection', 'defaultLifetime',
    ];

    /**
     * Constructor compatibility shim for TYPO3 11-13 and TYPO3 14.
     *
     * TYPO3 11-13: CacheManager passes (string $context, array $options)
     * TYPO3 14:    CacheManager passes (array $options) — $context removed
     *
     * Detection: if first argument is an array → v14 mode; if string → v11-13 mode.
     * The parent call is dispatched based on the actual parent constructor signature
     * to ensure compatibility regardless of which TYPO3 version is installed.
     */
    public function __construct(string|array $contextOrOptions = '', array $options = [])
    {
        if (is_array($contextOrOptions)) {
            // TYPO3 14 call pattern: first arg is $options
            $options = $contextOrOptions;
            $context = '';
        } else {
            // TYPO3 11-13 call pattern: first arg is $context string
            $context = $contextOrOptions;
        }

        $this->rawOptions = $options;
        $filteredOptions = array_intersect_key($options, array_flip(self::PARENT_OPTION_KEYS));

        // Detect parent constructor signature: TYPO3 14 removed the $context parameter.
        $parentRef = new \ReflectionMethod(parent::class, '__construct');
        $firstParam = $parentRef->getParameters()[0] ?? null;
        $parentAcceptsContext = $firstParam !== null
            && ((string)$firstParam->getType() === 'string' || $firstParam->getName() === 'context');

        if ($parentAcceptsContext) {
            parent::__construct($context, $filteredOptions);
        } else {
            parent::__construct($filteredOptions);
        }

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
     * Map RedisBackend's TYPO3-style properties plus rawOptions into the
     * KeyValueConnectionFactory option schema.
     *
     * Standard options (hostname, port, database, password, …) are read from
     * the RedisBackend properties set by parent::__construct() via setters.
     * TLS, Sentinel, backoff and any other extra keys come from $this->rawOptions
     * and are merged on top, overriding the defaults where needed.
     */
    private function buildFactoryOptions(): array
    {
        $opts = [
            'host' => $this->hostname,
            'port' => $this->port,
            'connectTimeout' => (float)$this->connectionTimeout,
            'readTimeout' => (float)($this->rawOptions['readTimeout'] ?? $this->rawOptions['read_timeout'] ?? 0.0),
            'retryInterval' => (int)($this->rawOptions['retryInterval'] ?? $this->rawOptions['retry_interval'] ?? 0),
            'database' => $this->database,
            // Persistent: use the database-specific ID so that different databases
            // get distinct persistent connections.
            'persistent' => $this->persistentConnection
                ? ('typo3-cache-' . (string)$this->database)
                : false,
        ];

        $authentication = $this->getAuthentication();
        if ($authentication !== null) {
            $opts['auth'] = $authentication;
        }

        // Merge all raw options (TLS, sentinel, backoff, and any extra keys).
        // rawOptions may also contain standard keys like hostname/port that were
        // already applied via parent setters — they are harmless extras for the factory.
        return array_replace($opts, $this->rawOptions);
    }
}
