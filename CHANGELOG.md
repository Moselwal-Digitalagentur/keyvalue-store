# Changelog

All notable changes to `moselwal/keyvalue-store` are documented in this file.
The format is loosely based on Keep-a-Changelog; this package follows
SemVer (breaking changes bump the major).

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
