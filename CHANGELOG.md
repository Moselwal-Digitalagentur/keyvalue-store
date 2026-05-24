# Changelog

All notable changes to `moselwal/keyvalue-store` are documented in this file.
The format is loosely based on Keep-a-Changelog; this package follows
SemVer (breaking changes bump the major).

## [4.2.0] - 2026-05-24

### Fixed (CRITICAL)

- **`KeyValueSessionBackend::getAll()` returned `[]` against real Redis**:
  v4.1.0 changed the SCAN cursor initialisation from `null` to `0` based
  on a misread of the phpredis API. phpredis 6.3.0 treats `int 0` as
  "iteration finished" and returns `false` immediately — without ever
  talking to the server. The intended T2.3 fix in v4.1.0 was therefore
  the opposite of an improvement: it silently broke session enumeration
  in the BE "Active sessions" module. v4.2.0 restores `cursor = null`
  initial and uses `0 !== (int) $cursor` as the loop guard. Bench
  confirms 500 sessions are now found (was: 0).

### Performance (override pack against TYPO3 Core anti-patterns)

- **`flush()` override**: Replaces TYPO3 Core's `KEYS prefix*` + `DEL`
  pipeline with `SCAN` + `UNLINK`. `KEYS` server-side-blocks Redis for
  every other client while it walks the keyspace — at millions of keys
  that is hundreds of milliseconds of stop-the-world for *all* pods.
  Wallclock cost for the caller increases ~30 % (10k keys: 7.4 ms →
  9.5 ms) because `SCAN` is iterative, but Redis stays responsive to
  concurrent clients throughout. In a shared multi-site setup this is
  the right trade.
- **`flushByTag()` / `flushByTags()` override**: One sUnion across all
  passed tags plus one pipelined `UNLINK` cleanup, replacing TYPO3
  Core's N× sequential `flushByTag()` fan-out. **3.3× faster for
  multi-tag flushes** (10 tags × ~100 entries: 4.1 ms → 1.2 ms).
- **`collectGarbage()` override**: Same `KEYS → SCAN` swap as `flush()`,
  with `UNLINK` for orphan cleanup. Same wallclock-vs-server-loop
  trade-off (1.4 ms → 2.8 ms wallclock; Redis stays responsive). GC
  runs on the scheduler-container clock so the caller-side latency is
  uncritical — server-side responsiveness is what matters.
- **`OPT_SCAN = SCAN_RETRY`**: Set in `initializeObject()` so phpredis
  internally re-issues empty SCAN pages until either keys appear or
  the cursor is exhausted. Removes a footgun for any future code that
  uses SCAN through this backend.

### Bench (real, against the moselwal Valkey container)

| Op | TYPO3 Core | v4.2.0 | Δ |
|---|---:|---:|---|
| Bootstrap 11 caches (T1.1) | 25.1 ms | 0.07 ms | **381×** |
| getAll() 500 sessions (T1.2) | 37.2 ms | 1.5 ms | **24.6×** |
| renew() (T2.2) | 360 µs | 161 µs | 2.2× |
| Retry-Backoff 2 failures (T2.1) | 162 ms | 31 ms | **5.1×** |
| flushByTags 10 tags | 4.1 ms | 1.2 ms | **3.3×** |
| flush 10k keys (wallclock) | 7.4 ms | 9.5 ms | −30 % wallclock, +∞ server fairness |
| collectGarbage 5k keys | 1.4 ms | 2.8 ms | −50 % wallclock, +∞ server fairness |

## [4.1.0] - 2026-05-24

### Performance

- **Lazy-connect default** (`KeyValueBackend`): `buildFactoryOptions()` now
  emits `lazy=true`. The TCP/TLS handshake is deferred to the first command
  instead of being paid at TYPO3 bootstrap for every configured cache
  backend. Removes ~11× ping-roundtrips per request on multi-cache setups
  (and the full TLS handshake on the first warm request after a worker
  reload). Operators can still force eager connect by passing
  `lazy => false` in the backend options.
- **`KeyValueSessionBackend::getAll()`**: Replaced the SCAN+per-key GET
  loop with a SCAN+MGET pipeline. One roundtrip per SCAN page instead of
  N+1 — at 500 active sessions, that is ~5 roundtrips instead of ~505.
  Improves the BE "Active sessions" module load time from seconds to
  sub-second.

### Fixed

- **`KeyValueSessionBackend::getAll()` cursor bug**: The cursor was
  initialised to `null`, which made `while ($cursor > 0)` short-circuit
  on the first iteration whenever phpredis did not implicit-cast. Result:
  the session list was silently truncated to the first SCAN page (~100
  entries). Now initialised to `0`.
- **`KeyValueSessionBackend::renew()` race**: Previously executed GET,
  TTL, SETEX, DEL as four separate commands. A concurrent `update()`
  landing between our GET and SETEX was silently overwritten with the
  older snapshot we had just copied. Rewritten as a single Lua `EVAL`
  so the rename is atomic relative to other writes.

### Changed

- **`KeyValueSessionBackend::getRedis()` retry-backoff**: Linear
  50/100/150 ms sleeps replaced with decorrelated jitter (10 ms base,
  100 ms cap). Two failed attempts now complete in well under 200 ms
  instead of ~300 ms — fail-fast for transient Valkey blips, less
  synchronised retry storms across pods.
- **`KeyValueBackend::__construct(array $options = [])`**: Dropped the
  TYPO3 11/12/13 compatibility shim (string-context first arg + parent
  signature detection via runtime reflection). The package is TYPO3
  14-only since v4.0.0, so the shim was dead code costing one reflection
  call per cache instantiation.

### BC

- `new KeyValueBackend('production', [...])` (old TYPO3 11-13 call shape)
  now throws `TypeError`. The TYPO3 14 CacheManager calls
  `new KeyValueBackend([...])` and is unaffected — `autoconfigureCaching()`
  in `moselwal/typo3-config` already targets that signature.

## [4.0.0] - 2026-04-03

- PHP 8.5 baseline, `ext-redis >= 6.3` required.
- TYPO3 14-only support; the 11/12/13 matrix collapsed.
- `moselwal/dev` bumped to v5.

## [3.x] and earlier

Multi-version TYPO3 11/12/13/14 matrix. See git history for the full
incremental log.
