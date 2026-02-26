<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Session\Backend;

use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use Redis;
use RedisException;
use TYPO3\CMS\Core\Session\Backend\Exception\InvalidArgumentException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotCreatedException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotUpdatedException;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;

/**
 * Redis/Valkey session backend with optional Sentinel and TLS/mTLS support.
 *
 * Implements the full TYPO3 SessionBackendInterface contract:
 *   - Session records are stored as JSON-encoded arrays.
 *   - ses_id is always forced to $sessionId on write.
 *   - ses_tstamp is updated automatically on every write.
 *   - get() throws SessionNotFoundException when the session is missing.
 *   - set() throws SessionNotCreatedException on write failure.
 *   - update() throws SessionNotUpdatedException when the session is missing or write fails.
 *
 * Configuration via $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['BE|FE']:
 *
 *   'backend' => \Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend::class,
 *   'options'  => [
 *     'hostname'             => 'keyvaluecache',  // ignored when sentinel=true
 *     'port'                 => 6379,
 *     'database'             => 2,
 *     'sessionLifetime'      => 3600,             // Redis TTL in seconds; default 3600
 *
 *     // Auth — phpredis-style 'auth' or legacy 'username'/'password'
 *     'auth'                 => ['myuser', 'mypassword'],  // ACL
 *     // -- or --
 *     'password'             => 'secret',
 *     'username'             => 'myuser',                  // optional ACL username
 *
 *     'connectTimeout'       => 2.5,    // seconds (alias: connectionTimeout)
 *     'readTimeout'          => 1.0,    // seconds
 *     'persistentConnection' => true,
 *     'persistentId'         => 'typo3-session-fe',
 *     'prefix'               => 'typo3:sess:fe:',
 *
 *     // Backoff (optional, phpredis >= 6.0)
 *     'backoff' => [
 *         'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
 *         'base' => 500,
 *         'cap'  => 750,
 *     ],
 *
 *     // TLS/mTLS (optional)
 *     'tls'                  => true,
 *     'verify_peer'          => true,
 *     'verify_peer_name'     => true,
 *     'peer_name'            => 'valkey.internal',
 *     'ca_file'              => '/run/tls/ca.crt',
 *     'cert_file'            => '/run/tls/httpd.crt',  // mTLS
 *     'key_file'             => '/run/tls/httpd.key',  // mTLS
 *     'allow_self_signed'    => false,
 *
 *     // Sentinel (optional)
 *     'sentinel'             => true,
 *     'sentinel_host'        => 'sentinel',
 *     'sentinel_port'        => 26379,
 *     'sentinel_service'     => 'mymaster',
 *     'sentinel_password'    => null,
 *   ],
 */
final class KeyValueSessionBackend implements SessionBackendInterface
{
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_DB = 0;
    private const DEFAULT_CONNECT_TIMEOUT = 1.0;
    private const DEFAULT_READ_TIMEOUT = 1.0;
    private const DEFAULT_SESSION_LIFETIME = 3600;

    /** @var array<string,mixed> */
    private array $options = [];

    private ?Redis $redis = null;

    private string $prefix = 'typo3:sess:';

    private KeyValueConnectionFactory $factory;

    public function __construct()
    {
        $this->factory = new KeyValueConnectionFactory();
    }

    // -------------------------------------------------------------------------
    // SessionBackendInterface
    // -------------------------------------------------------------------------

    public function initialize(string $identifier, array $configuration): void
    {
        $this->options = $configuration;
        $this->prefix = (string)($configuration['prefix']
            ?? ('typo3:sess:' . strtolower($identifier) . ':'));
        $this->redis = null;
        $this->validateConfiguration();
    }

    public function validateConfiguration(): void
    {
        if (!empty($this->options['sentinel'])) {
            if (trim((string)($this->options['sentinel_host'] ?? '')) === '') {
                throw new InvalidArgumentException(
                    'Redis sentinel=true but sentinel_host is missing',
                    1730001001
                );
            }
            if (trim((string)($this->options['sentinel_service'] ?? '')) === '') {
                throw new InvalidArgumentException(
                    'Redis sentinel=true but sentinel_service is missing',
                    1730001004
                );
            }
        } else {
            $host = trim((string)($this->options['hostname'] ?? $this->options['host'] ?? ''));
            if ($host === '') {
                throw new InvalidArgumentException('Redis hostname is missing', 1730001002);
            }
        }

        $db = (int)($this->options['database'] ?? self::DEFAULT_DB);
        if ($db < 0) {
            throw new InvalidArgumentException('Redis database must be >= 0', 1730001003);
        }
    }

    /**
     * Read session data.
     *
     * @throws SessionNotFoundException when the session does not exist
     */
    public function get(string $sessionId): array
    {
        try {
            $raw = $this->getRedis()->get($this->key($sessionId));
        } catch (RedisException $e) {
            throw new SessionNotFoundException(
                'Redis error while reading session "' . $sessionId . '": ' . $e->getMessage(),
                1730001011,
                $e
            );
        }

        if ($raw === false) {
            throw new SessionNotFoundException(
                'Session "' . $sessionId . '" not found',
                1730001010
            );
        }

        $record = json_decode((string)$raw, true);
        return is_array($record) ? $record : [];
    }

    /**
     * List all sessions as an array of session record arrays.
     */
    public function getAll(): array
    {
        try {
            $redis = $this->getRedis();
            $sessions = [];
            $cursor = 0;

            do {
                $keys = $redis->scan($cursor, ['match' => $this->prefix . '*', 'count' => 100]);
                if ($keys === false) {
                    break;
                }
                foreach ($keys as $key) {
                    $raw = $redis->get($key);
                    if ($raw !== false) {
                        $record = json_decode((string)$raw, true);
                        if (is_array($record)) {
                            $sessions[] = $record;
                        }
                    }
                }
            } while ($cursor !== 0);

            return $sessions;
        } catch (RedisException) {
            return [];
        }
    }

    /**
     * Write new session data.
     *
     * Enforces ses_id = $sessionId and updates ses_tstamp per interface contract.
     *
     * @throws SessionNotCreatedException on write failure
     */
    public function set(string $sessionId, array $sessionData): array
    {
        $ttl = max(1, (int)($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME));

        // Interface contract: ses_id is always overwritten; ses_tstamp is updated.
        $sessionData['ses_id'] = $sessionId;
        $sessionData['ses_tstamp'] = $GLOBALS['EXEC_TIME'] ?? time();

        try {
            $encoded = json_encode($sessionData, JSON_THROW_ON_ERROR);
            $ok = $this->getRedis()->setex($this->key($sessionId), $ttl, $encoded);
            if (!$ok) {
                throw new SessionNotCreatedException(
                    'Could not write session "' . $sessionId . '" to Redis',
                    1730001020
                );
            }
            return $sessionData;
        } catch (RedisException $e) {
            throw new SessionNotCreatedException(
                'Redis error while creating session "' . $sessionId . '": ' . $e->getMessage(),
                1730001021,
                $e
            );
        }
    }

    /**
     * Update existing session data (partial merge).
     *
     * Preserves the existing TTL. Enforces ses_id = $sessionId and updates ses_tstamp.
     *
     * @throws SessionNotUpdatedException when the session is missing or the write fails
     */
    public function update(string $sessionId, array $sessionData): array
    {
        try {
            $redis = $this->getRedis();
            $key = $this->key($sessionId);

            $raw = $redis->get($key);
            if ($raw === false) {
                throw new SessionNotUpdatedException(
                    'Session "' . $sessionId . '" not found, cannot update',
                    1730001030
                );
            }

            $existing = json_decode((string)$raw, true);
            $record = is_array($existing) ? $existing : [];

            // Merge partial update on top of the existing record.
            $record = array_merge($record, $sessionData);

            // Interface contract: ses_id is always overwritten; ses_tstamp is updated.
            $record['ses_id'] = $sessionId;
            $record['ses_tstamp'] = $GLOBALS['EXEC_TIME'] ?? time();

            // Preserve the existing TTL; fall back to configured lifetime when the
            // key has no TTL (-1) or has already expired (-2).
            $ttl = (int)$redis->ttl($key);
            if ($ttl <= 0) {
                $ttl = (int)($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME);
            }

            $encoded = json_encode($record, JSON_THROW_ON_ERROR);
            $ok = $redis->setex($key, max(1, $ttl), $encoded);
            if (!$ok) {
                throw new SessionNotUpdatedException(
                    'Could not write updated session "' . $sessionId . '" to Redis',
                    1730001031
                );
            }
            return $record;
        } catch (RedisException $e) {
            throw new SessionNotUpdatedException(
                'Redis error while updating session "' . $sessionId . '": ' . $e->getMessage(),
                1730001032,
                $e
            );
        }
    }

    public function remove(string $sessionId): bool
    {
        try {
            return $this->getRedis()->del($this->key($sessionId)) > 0;
        } catch (RedisException) {
            return false;
        }
    }

    public function collectGarbage(int $maximumLifetime, int $maximumAnonymousLifetime = 0): void
    {
        // Redis TTL handles expiry automatically; nothing to do here.
    }

    /**
     * Rename a session key while preserving TTL (session fixation protection).
     * Not part of SessionBackendInterface.
     */
    public function renew(string $oldSessionId, string $newSessionId): bool
    {
        try {
            $redis = $this->getRedis();
            $oldKey = $this->key($oldSessionId);
            $newKey = $this->key($newSessionId);

            $ttl = (int)$redis->ttl($oldKey);
            if ($ttl <= 0) {
                $ttl = (int)($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME);
            }

            $raw = $redis->get($oldKey);
            if ($raw === false) {
                return false;
            }

            if (!$redis->setex($newKey, $ttl, (string)$raw)) {
                return false;
            }
            $redis->del($oldKey);

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }

    private function getRedis(): Redis
    {
        if ($this->redis instanceof Redis) {
            try {
                $this->redis->ping();
                return $this->redis;
            } catch (RedisException) {
                $this->redis = null;
            }
        }

        $factoryOptions = $this->buildFactoryOptions();

        $last = null;
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->redis = $this->factory->create($factoryOptions);
                $this->redis->ping();
                return $this->redis;
            } catch (RedisException $e) {
                $last = $e;
                $this->redis = null;
                usleep(50_000 * ($i + 1));
            }
        }

        throw $last ?? new RedisException('Could not connect to Redis');
    }

    private function buildFactoryOptions(): array
    {
        $opts = [
            'host' => trim((string)($this->options['hostname'] ?? $this->options['host'] ?? '')),
            'port' => (int)($this->options['port'] ?? self::DEFAULT_PORT),
            'connectTimeout' => (float)($this->options['connectTimeout']
                ?? $this->options['connectionTimeout']
                ?? self::DEFAULT_CONNECT_TIMEOUT),
            'readTimeout' => (float)($this->options['readTimeout'] ?? self::DEFAULT_READ_TIMEOUT),
            'database' => (int)($this->options['database'] ?? self::DEFAULT_DB),
            'lazy' => true,
        ];

        $persistentConnection = (bool)($this->options['persistentConnection'] ?? false);
        $persistentId = trim((string)($this->options['persistentId'] ?? ''));
        if ($persistentConnection) {
            $opts['persistent'] = $persistentId !== '' ? $persistentId : true;
        }

        if (array_key_exists('auth', $this->options)) {
            $opts['auth'] = $this->options['auth'];
        } else {
            $password = (string)($this->options['password'] ?? '');
            $username = (string)($this->options['username'] ?? '');
            if ($password !== '') {
                $opts['auth'] = $username !== '' ? [$username, $password] : $password;
            }
        }

        foreach ([
            'backoff',
            'tls', 'ca_file', 'cert_file', 'key_file', 'peer_name',
            'verify_peer', 'verify_peer_name', 'allow_self_signed',
            'sentinel', 'sentinel_host', 'sentinel_port', 'sentinel_service', 'sentinel_password',
        ] as $k) {
            if (array_key_exists($k, $this->options)) {
                $opts[$k] = $this->options[$k];
            }
        }

        return $opts;
    }
}
