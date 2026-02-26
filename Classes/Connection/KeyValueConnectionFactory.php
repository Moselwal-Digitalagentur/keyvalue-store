<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

use Moselwal\KeyValueStore\Connection\ValueObject\ConnectionParams;
use Moselwal\KeyValueStore\Connection\ValueObject\Endpoint;

final class KeyValueConnectionFactory
{
    public function __construct(
        private readonly TlsContextBuilder $tlsContextBuilder = new TlsContextBuilder(),
        private readonly SentinelResolver $sentinelResolver = new SentinelResolver(),
    ) {}

    /**
     * Create a configured Redis connection.
     *
     * Option keys align with the phpredis >= 6.0 constructor array.
     * Snake_case aliases are accepted for backward compatibility.
     *
     * Connection options (phpredis camelCase primary / snake_case alias):
     *   host            string   hostname, IP, or unix-socket path
     *   port            int      default 6379; -1 or 0 for unix socket
     *   connectTimeout  float    seconds, 0 = unlimited (alias: timeout)
     *   readTimeout     float    seconds, 0 = unlimited (alias: read_timeout)
     *   retryInterval   int      milliseconds between retries (alias: retry_interval)
     *   database        int      Redis database index, default 0
     *   auth            mixed    phpredis auth: string (password), ['user','pass'] (ACL)
     *   password        string   legacy — combined with 'username' into auth
     *   username        string   legacy ACL username
     *   persistent      mixed    string = persistent ID, true = auto-ID, false = off
     *   persistent_id   string   legacy persistent ID alias
     *   backoff         array    phpredis backoff config:
     *                            ['algorithm' => Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
     *                             'base' => 500, 'cap' => 750]
     *
     * TLS options (handled by TlsContextBuilder):
     *   tls             bool     enable TLS
     *   ca_file         string   CA certificate path
     *   cert_file       string   client certificate path (mTLS)
     *   key_file        string   client private key path (mTLS)
     *   peer_name       string   SNI / expected peer name
     *   verify_peer     bool     default true
     *   verify_peer_name bool    default true
     *   allow_self_signed bool   default false
     *
     * Sentinel options:
     *   sentinel          bool   enable Sentinel master resolution
     *   sentinel_host     string Sentinel hostname
     *   sentinel_port     int    Sentinel port, default 26379
     *   sentinel_service  string master name (e.g. 'mymaster')
     *   sentinel_password string optional Sentinel AUTH password
     *
     * Behaviour:
     *   lazy  bool  If true, use the phpredis 6.x constructor for lazy connect.
     *               Auth and database are embedded in the constructor config and
     *               applied automatically on the first command. Default: false.
     */
    public function create(array $options): \Redis
    {
        $params = ConnectionParams::fromOptions($options);
        $tlsContext = $this->tlsContextBuilder->build($options);
        $endpoint = $this->resolveEndpoint($options, $params->connectTimeout);
        $lazy = (bool)($options['lazy'] ?? false);

        if ($lazy) {
            return $this->createLazy($endpoint, $tlsContext, $params);
        }

        $redis = new \Redis();
        $this->connectEager($redis, $endpoint, $tlsContext, $params);
        $this->postConnect($redis, $params);

        return $redis;
    }

    /**
     * Ensure an existing Redis handle is connected; re-connects on failure.
     * Pass the same $options array that was used for create().
     */
    public function ensureConnected(\Redis $redis, array $options): void
    {
        try {
            $redis->ping();
            return;
        } catch (\Throwable) {
            // fall through to reconnect
        }

        $params = ConnectionParams::fromOptions($options);
        $tlsContext = $this->tlsContextBuilder->build($options);
        $endpoint = $this->resolveEndpoint($options, $params->connectTimeout);

        $this->connectEager($redis, $endpoint, $tlsContext, $params);
        $this->postConnect($redis, $params);
    }

    // -------------------------------------------------------------------------
    // Private: endpoint resolution
    // -------------------------------------------------------------------------

    private function resolveEndpoint(array $options, float $connectTimeout): Endpoint
    {
        if (!empty($options['sentinel'])) {
            return $this->sentinelResolver->resolveMaster([
                'sentinel' => true,
                'sentinel_host' => (string)($options['sentinel_host'] ?? ''),
                'sentinel_port' => (int)($options['sentinel_port'] ?? 26379),
                'sentinel_service' => (string)($options['sentinel_service'] ?? ''),
                'sentinel_password' => $options['sentinel_password'] ?? null,
                'connectTimeout' => $connectTimeout,
                'persistent_id' => $options['sentinel_persistent_id'] ?? null,
                // Forward TLS options so the sentinel connection itself can be TLS-encrypted.
                'tls' => $options['tls'] ?? false,
                'ca_file' => $options['ca_file'] ?? '',
                'cert_file' => $options['cert_file'] ?? '',
                'key_file' => $options['key_file'] ?? '',
                'peer_name' => $options['peer_name'] ?? '',
                'verify_peer' => $options['verify_peer'] ?? true,
                'verify_peer_name' => $options['verify_peer_name'] ?? true,
                'allow_self_signed' => $options['allow_self_signed'] ?? false,
            ]);
        }

        $host = (string)($options['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException('Redis host must be set.');
        }

        return new Endpoint($host, (int)($options['port'] ?? 6379), $connectTimeout);
    }

    // -------------------------------------------------------------------------
    // Private: eager (immediate) connect path
    // -------------------------------------------------------------------------

    /**
     * Establishes a connection using Redis::connect() / Redis::pconnect().
     *
     * connect() / pconnect() use positional snake_case parameters, not the
     * constructor camelCase keys — phpredis intentionally has two APIs here.
     */
    private function connectEager(
        \Redis $redis,
        Endpoint $endpoint,
        ?array $tlsContext,
        ConnectionParams $params
    ): void {
        // Prefix host with tls:// so phpredis negotiates TLS on the socket.
        $host = $tlsContext !== null ? ('tls://' . $endpoint->host) : $endpoint->host;

        if ($params->persistent !== false) {
            // pconnect: string persistent value = explicit ID; true = let phpredis choose.
            $persistentId = is_string($params->persistent) ? $params->persistent : null;
            $ok = $redis->pconnect(
                $host,
                $endpoint->port,
                $params->connectTimeout,
                $persistentId,
                $params->retryInterval,
                $params->readTimeout,
                $tlsContext   // stream context array: ['ssl' => [...]]
            );
        } else {
            $ok = $redis->connect(
                $host,
                $endpoint->port,
                $params->connectTimeout,
                null,
                $params->retryInterval,
                $params->readTimeout,
                $tlsContext
            );
        }

        if ($ok !== true) {
            throw new \RedisException('Could not connect to Redis/Valkey endpoint.');
        }
    }

    /**
     * Run AUTH and SELECT after a successful eager connect.
     */
    private function postConnect(\Redis $redis, ConnectionParams $params): void
    {
        if ($params->auth !== null) {
            $ok = $redis->auth($params->auth);
            if ($ok !== true) {
                throw new \RedisException('Redis AUTH failed.');
            }
        }

        // Always SELECT explicitly — even database 0 — so that a reused persistent
        // connection from a different database is reset to the correct one.
        $ok = $redis->select($params->database);
        if ($ok !== true) {
            throw new \RedisException('Redis SELECT failed for database ' . $params->database);
        }
    }

    // -------------------------------------------------------------------------
    // Private: lazy connect path (phpredis >= 6.0 constructor)
    // -------------------------------------------------------------------------

    /**
     * Build a phpredis >= 6.0 constructor config array and return a lazy Redis handle.
     *
     * All keys match the phpredis constructor API exactly (camelCase).
     * Auth and database are embedded so phpredis applies them automatically
     * on the first real command — no explicit AUTH / SELECT call needed.
     *
     * phpredis constructor reference:
     *   host           string
     *   port           int
     *   connectTimeout float   seconds
     *   readTimeout    float   seconds
     *   retryInterval  int     milliseconds
     *   database       int
     *   auth           mixed   string | ['password'] | ['user', 'password']
     *   persistent     mixed   string (ID) | bool
     *   ssl            array   PHP stream SSL context options (without the outer 'ssl' wrapper)
     *   backoff        array   ['algorithm' => ..., 'base' => ..., 'cap' => ...]
     */
    private function createLazy(Endpoint $endpoint, ?array $tlsContext, ConnectionParams $params): \Redis
    {
        $cfg = [
            'host' => $tlsContext !== null ? ('tls://' . $endpoint->host) : $endpoint->host,
            'port' => $endpoint->port,
            'connectTimeout' => $params->connectTimeout,
            'readTimeout' => $params->readTimeout,
            'retryInterval' => $params->retryInterval,
            'database' => $params->database,
        ];

        if ($params->auth !== null) {
            $cfg['auth'] = $params->auth;
        }

        if ($params->persistent !== false) {
            $cfg['persistent'] = $params->persistent; // string ID or true
        }

        if ($tlsContext !== null) {
            // Constructor expects the SSL options array directly (no outer 'ssl' wrapper).
            $cfg['ssl'] = $tlsContext['ssl'];
        }

        if ($params->backoff !== null) {
            $cfg['backoff'] = $params->backoff;
        }

        /** @phpstan-ignore-next-line */
        return new \Redis($cfg);
    }
}
