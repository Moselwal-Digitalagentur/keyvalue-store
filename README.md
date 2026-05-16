# KeyValue Store — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Redis/Valkey integration for TYPO3 providing caching backends, session storage, and locking strategies with Sentinel and TLS/mTLS support.

## Features

- **Caching Backends** — Redis-based cache backends for TYPO3's Caching Framework
- **Session Storage** — Redis-backed frontend and backend session handling
- **Locking Strategy** — Distributed locking via Redis for multi-server setups
- **Sentinel Support** — High-availability Redis through Sentinel discovery
- **TLS/mTLS** — Encrypted connections with mutual TLS certificate support
- **TYPO3 14.x** — getestet auf der aktuellen LTS-Linie

## Installation

```bash
composer require moselwal/keyvalue-store
```

### Requirements

- PHP 8.2+
- TYPO3 14.x
- `ext-redis` >= 6.3 (PHPRedis extension)
- Redis or Valkey server

## Architecture

```
Classes/
├── Cache/           # TYPO3 caching framework backends
├── Connection/      # Redis connection management, Sentinel, TLS
├── Locking/         # Distributed locking strategy
└── Session/         # Session storage backends
```

## Configuration

Configure through `moselwal/typo3-config` for seamless integration, or manually via TYPO3's `config/config.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['my_cache'] = [
    'backend' => \Moselwal\KeyvalueStore\Cache\RedisBackend::class,
    'options' => [
        'hostname' => 'redis',
        'port' => 6379,
        'database' => 3,
    ],
];
```

### TLS/mTLS

For encrypted connections, provide certificate paths or use auto-discovery from `/run/tls/`.

## Development

```bash
composer install
composer test                    # Unit tests
composer test:functional         # Functional tests (requires Redis)
composer phpstan                 # Static analysis
vendor/bin/php-cs-fixer fix      # Code style (PER-CS3x0)
```

## Dependencies

| Package | Type | Purpose |
|---------|------|---------|
| `ext-redis` (>= 6.3) | Required | PHPRedis extension |
| `moselwal/dev` | Dev | Shared QA tooling |

## Related

- [typo3-config](../typo3-config) — Fluent configuration API with built-in KeyValue Store integration

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
