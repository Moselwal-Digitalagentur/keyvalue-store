<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
// TYPO3 14 removed this class — use PHP's built-in \InvalidArgumentException
// The source code will be updated to throw \InvalidArgumentException instead

/**
 * Unit tests for KeyValueSessionBackend::initialize() and validateConfiguration().
 *
 * These tests verify configuration parsing and validation only — no Redis
 * connection is established, so ext-redis is not required.
 */
final class KeyValueSessionBackendTest extends TestCase
{
    // -------------------------------------------------------------------------
    // initialize() — prefix handling
    // -------------------------------------------------------------------------

    #[Test]
    public function initializeSetsDefaultPrefixFromIdentifier(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('BE', [
            'hostname' => '127.0.0.1',
        ]);

        $ref = new \ReflectionProperty($backend, 'prefix');
        self::assertSame('typo3:sess:be:', $ref->getValue($backend));
    }

    #[Test]
    public function initializeUsesCustomPrefixFromConfiguration(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('BE', [
            'hostname' => '127.0.0.1',
            'prefix' => 'custom:session:',
        ]);

        $ref = new \ReflectionProperty($backend, 'prefix');
        self::assertSame('custom:session:', $ref->getValue($backend));
    }

    // -------------------------------------------------------------------------
    // validateConfiguration() — valid configurations
    // -------------------------------------------------------------------------

    #[Test]
    public function validateConfigurationPassesWithValidDirectConnection(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'redis.local',
            'port' => 6379,
            'database' => 0,
        ]);

        // No exception means the configuration is valid.
        self::assertTrue(true);
    }

    #[Test]
    public function validateConfigurationPassesWithHostAlias(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'host' => 'redis.local',
        ]);

        self::assertTrue(true);
    }

    #[Test]
    public function validateConfigurationPassesWithValidSentinelConfig(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'sentinel' => true,
            'sentinel_host' => 'sentinel.local',
            'sentinel_service' => 'mymaster',
        ]);

        self::assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // validateConfiguration() — invalid configurations
    // -------------------------------------------------------------------------

    #[Test]
    public function validateConfigurationThrowsWhenHostnameIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1730001002);

        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'port' => 6379,
            'database' => 0,
        ]);
    }

    #[Test]
    public function validateConfigurationThrowsWhenHostnameIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1730001002);

        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => '  ',
        ]);
    }

    #[Test]
    public function validateConfigurationThrowsWhenSentinelHostIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1730001001);

        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'sentinel' => true,
            'sentinel_service' => 'mymaster',
        ]);
    }

    #[Test]
    public function validateConfigurationThrowsWhenSentinelServiceIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1730001004);

        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'sentinel' => true,
            'sentinel_host' => 'sentinel.local',
        ]);
    }

    #[Test]
    public function validateConfigurationThrowsWhenDatabaseIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1730001003);

        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'redis.local',
            'database' => -1,
        ]);
    }
}
