<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Utility\StringUtility;

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
     * TYPO3 14 constructor: CacheManager passes the options array directly.
     * Earlier TYPO3 versions are no longer supported (composer.json: ^14.0).
     */
    public function __construct(array $options = [])
    {
        $this->rawOptions = $options;
        $filteredOptions = array_intersect_key($options, array_flip(self::PARENT_OPTION_KEYS));
        parent::__construct($filteredOptions);
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
            // Make SCAN loops resilient: SCAN_RETRY tells phpredis to keep
            // hitting the server until either keys are returned or the
            // cursor is exhausted. Without this, an empty SCAN page returns
            // `false` and the caller has to spin its own retry loop.
            $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $this->connected = true;
        } catch (\RedisException|\InvalidArgumentException $e) {
            $this->connected = false;
            throw new Exception('KeyValueBackend could not connect to Redis: ' . $e->getMessage(), 1700100001, $e);
        }
    }

    /**
     * Override TYPO3 Core's `flush()`:
     * - Replaces `KEYS prefix*` with a `SCAN` loop. `KEYS` blocks the
     *   Redis event loop server-side for every other client while it
     *   walks the keyspace — at millions of keys that is hundreds of ms
     *   of stop-the-world.
     * - Replaces `DEL` with `UNLINK`. UNLINK marks the keys as gone and
     *   reclaims memory in a background thread, so the caller's pipeline
     *   does not wait for value teardown.
     *
     * For the no-prefix case we keep `FLUSHDB` — single command, atomic,
     * already non-blocking-by-design.
     */
    public function flush(): void
    {
        if (!$this->connected) {
            return;
        }
        if ('' === $this->keyPrefix) {
            $this->redis->flushDB();

            return;
        }
        $this->unlinkByPattern($this->keyPrefix . '*');
    }

    /**
     * Override TYPO3 Core's `flushByTags()` so we issue **one** pipeline
     * for all tags instead of replaying the full flushByTag() flow N
     * times. We also UNLINK instead of DEL for non-blocking server-side
     * cleanup.
     */
    public function flushByTags(array $tags): void
    {
        if (!$this->connected || [] === $tags) {
            return;
        }
        $tagIdentifiers = array_map($this->getTagIdentifier(...), $tags);
        $identifierSets = $this->redis->sUnion(...$tagIdentifiers);
        if (!is_array($identifierSets) || [] === $identifierSets) {
            // No entries reference these tags — only the tag sets need
            // to disappear.
            $this->redis->unlink(...$tagIdentifiers);

            return;
        }
        $this->removeIdentifierEntriesAndRelationsBatched($identifierSets, $tags);
    }

    /**
     * Override TYPO3 Core's `flushByTag()` to route through the same
     * batched pipeline path as `flushByTags([$tag])`. Keeps a single
     * implementation, single optimisation surface.
     */
    public function flushByTag(string $tag): void
    {
        $this->flushByTags([$tag]);
    }

    /**
     * Override TYPO3 Core's `collectGarbage()`. Same root cause as
     * `flush()`: Core uses `KEYS` which blocks the server. SCAN-loop
     * instead, plus UNLINK for cleanup.
     *
     * The N×EXISTS probe per orphan candidate is left sequential — it is
     * a scheduler-side workload and pipelining the entire keyspace would
     * mean buffering one EXISTS-result per cache entry in PHP memory.
     */
    public function collectGarbage(): void
    {
        if (!$this->connected) {
            return;
        }
        $pattern = $this->getTagsIdentifier('*');
        $tagsPrefixLength = strlen($this->keyPrefix . self::IDENTIFIER_TAGS_PREFIX);
        foreach ($this->scanIterator($pattern) as $identifierToTagsKey) {
            // The key shape is `<keyPrefix>identTags:<identifier>`. Stripping
            // the known prefix is more robust than searching with strpos —
            // strpos can theoretically return false on a malformed entry
            // and would crash the GC loop.
            $identifier = substr($identifierToTagsKey, $tagsPrefixLength);
            if ($this->redis->exists($this->getDataIdentifier($identifier))) {
                continue;
            }
            $tagsToRemoveIdentifierFrom = $this->redis->sMembers($identifierToTagsKey);
            $queue = $this->redis->multi(\Redis::PIPELINE);
            $queue->unlink($identifierToTagsKey);
            foreach ($tagsToRemoveIdentifierFrom as $tag) {
                $queue->srem($this->getTagIdentifier($tag), $identifier);
            }
            $queue->exec();
        }
    }

    /**
     * SCAN+UNLINK helper used by flush(). UNLINK is called per SCAN page
     * (max 500 keys at a time) so the argument list is bounded.
     */
    private function unlinkByPattern(string $pattern): void
    {
        foreach ($this->scanChunks($pattern, 500) as $batch) {
            if ([] !== $batch) {
                $this->redis->unlink(...$batch);
            }
        }
    }

    /**
     * Pipeline-friendly cleanup for flushByTag/flushByTags. Matches the
     * shape of TYPO3 Core's `removeIdentifierEntriesAndRelations()` —
     * same Redis-set algebra (sUnion + sDiffStore + DEL) — but issues
     * `UNLINK` for the final cleanup so the server-side memory teardown
     * happens asynchronously.
     *
     * @param array<int, string> $identifiers cache-entry identifiers to flush
     * @param array<int, string> $tags        tags whose sets disappear entirely
     */
    private function removeIdentifierEntriesAndRelationsBatched(array $identifiers, array $tags): void
    {
        $uniqueTempKey = 'temp:' . StringUtility::getUniqueId();
        $prefixedKeysToDelete = [$uniqueTempKey];
        $prefixedIdentifierToTagsKeysToDelete = [];
        foreach ($identifiers as $identifier) {
            $prefixedKeysToDelete[] = $this->getDataIdentifier($identifier);
            $prefixedIdentifierToTagsKeysToDelete[] = $this->getTagsIdentifier($identifier);
        }
        foreach ($tags as $tag) {
            $prefixedKeysToDelete[] = $this->getTagIdentifier($tag);
        }
        $tagToIdentifiersSetsToRemoveIdentifiersFrom = $this->redis->sUnion(...$prefixedIdentifierToTagsKeysToDelete);
        $tagToIdentifiersSetsToRemoveIdentifiersFrom = array_diff(
            $tagToIdentifiersSetsToRemoveIdentifiersFrom,
            $tags,
        );

        $queue = $this->redis->multi(\Redis::PIPELINE);
        foreach ($identifiers as $identifier) {
            $queue->sAdd($uniqueTempKey, $identifier);
        }
        foreach ($tagToIdentifiersSetsToRemoveIdentifiersFrom as $tagToIdentifiersSet) {
            $queue->sDiffStore(
                $this->getTagIdentifier($tagToIdentifiersSet),
                $this->getTagIdentifier($tagToIdentifiersSet),
                $uniqueTempKey,
            );
        }
        // UNLINK accepts variadic keys; UNLINK is non-blocking server-side.
        $queue->unlink(...array_merge($prefixedKeysToDelete, $prefixedIdentifierToTagsKeysToDelete));
        $queue->exec();
    }

    /**
     * Yield each matching key one at a time via SCAN. Used by GC, which
     * processes keys individually.
     *
     * @return \Generator<int, string>
     */
    private function scanIterator(string $pattern, int $chunk = 100): \Generator
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $pattern, $chunk);
            if (false === $keys) {
                continue;
            }
            foreach ($keys as $key) {
                yield $key;
            }
        } while (0 !== (int) $cursor);
    }

    /**
     * Yield matching keys as chunked arrays via SCAN. Used by flush
     * paths that benefit from batched UNLINK.
     *
     * @return \Generator<int, array<int, string>>
     */
    private function scanChunks(string $pattern, int $chunk = 500): \Generator
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $pattern, $chunk);
            if (false === $keys || [] === $keys) {
                continue;
            }
            yield $keys;
        } while (0 !== (int) $cursor);
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
            'connectTimeout' => (float) $this->connectionTimeout,
            'readTimeout' => (float) ($this->rawOptions['readTimeout'] ?? $this->rawOptions['read_timeout'] ?? 0.0),
            'retryInterval' => (int) ($this->rawOptions['retryInterval'] ?? $this->rawOptions['retry_interval'] ?? 0),
            'database' => $this->database,
            // Persistent: use the database-specific ID so that different databases
            // get distinct persistent connections.
            'persistent' => $this->persistentConnection
                ? ('typo3-cache-' . (string) $this->database)
                : false,
            // Defer the TCP/TLS handshake to the first command. TYPO3 boots
            // every configured cache backend eagerly, but most requests only
            // touch a handful of them — paying 11× ping-roundtrips at
            // bootstrap (and the full TLS handshake on the first warm
            // request after a worker reload) is pure overhead. phpredis
            // applies AUTH/SELECT on first connect, so correctness is
            // unaffected; only the timing of the network IO moves.
            'lazy' => true,
        ];

        $authentication = $this->getAuthentication();
        if (null !== $authentication) {
            $opts['auth'] = $authentication;
        }

        // Merge all raw options (TLS, sentinel, backoff, and any extra keys).
        // rawOptions may also contain standard keys like hostname/port that were
        // already applied via parent setters — they are harmless extras for the factory.
        return array_replace($opts, $this->rawOptions);
    }
}
