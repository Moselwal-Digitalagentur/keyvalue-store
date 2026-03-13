<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection\ValueObject;

use Moselwal\KeyValueStore\Connection\ValueObject\ConnectionParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * T019: Test ConnectionParams value object.
 *
 * Covers default values, alias resolution, auth resolution,
 * persistent resolution, and backoff passthrough.
 */
final class ConnectionParamsTest extends TestCase
{
    #[Test]
    public function defaultValuesWhenEmptyOptions(): void
    {
        $params = ConnectionParams::fromOptions([]);

        self::assertSame(1.0, $params->connectTimeout);
        self::assertSame(0.0, $params->readTimeout);
        self::assertSame(0, $params->retryInterval);
        self::assertSame(0, $params->database);
        self::assertNull($params->auth);
        self::assertFalse($params->persistent);
        self::assertNull($params->backoff);
    }

    #[Test]
    public function camelCasePrimaryKeys(): void
    {
        $params = ConnectionParams::fromOptions([
            'connectTimeout' => 2.5,
            'readTimeout' => 3.0,
            'retryInterval' => 100,
            'database' => 5,
        ]);

        self::assertSame(2.5, $params->connectTimeout);
        self::assertSame(3.0, $params->readTimeout);
        self::assertSame(100, $params->retryInterval);
        self::assertSame(5, $params->database);
    }

    #[Test]
    public function snakeCaseAliasKeys(): void
    {
        $params = ConnectionParams::fromOptions([
            'timeout' => 4.0,
            'read_timeout' => 1.5,
            'retry_interval' => 200,
        ]);

        self::assertSame(4.0, $params->connectTimeout);
        self::assertSame(1.5, $params->readTimeout);
        self::assertSame(200, $params->retryInterval);
    }

    #[Test]
    public function camelCaseKeyTakesPriorityOverSnakeCaseAlias(): void
    {
        $params = ConnectionParams::fromOptions([
            'connectTimeout' => 9.0,
            'timeout' => 1.0,
            'readTimeout' => 8.0,
            'read_timeout' => 2.0,
            'retryInterval' => 999,
            'retry_interval' => 111,
        ]);

        self::assertSame(9.0, $params->connectTimeout);
        self::assertSame(8.0, $params->readTimeout);
        self::assertSame(999, $params->retryInterval);
    }

    #[Test]
    public function authResolutionPasswordOnly(): void
    {
        $params = ConnectionParams::fromOptions([
            'password' => 'secret',
        ]);

        self::assertSame('secret', $params->auth);
    }

    #[Test]
    public function authResolutionUsernameAndPassword(): void
    {
        $params = ConnectionParams::fromOptions([
            'username' => 'admin',
            'password' => 'secret',
        ]);

        self::assertSame(['admin', 'secret'], $params->auth);
    }

    #[Test]
    public function authKeyTakesPriorityOverUsernamePassword(): void
    {
        $params = ConnectionParams::fromOptions([
            'auth' => 'direct-auth',
            'username' => 'ignored',
            'password' => 'ignored',
        ]);

        self::assertSame('direct-auth', $params->auth);
    }

    #[Test]
    public function authKeyNullPassedThrough(): void
    {
        $params = ConnectionParams::fromOptions([
            'auth' => null,
            'password' => 'should-be-ignored',
        ]);

        self::assertNull($params->auth);
    }

    #[Test]
    public function noAuthReturnsNull(): void
    {
        $params = ConnectionParams::fromOptions([]);

        self::assertNull($params->auth);
    }

    #[Test]
    public function emptyPasswordReturnsNullAuth(): void
    {
        $params = ConnectionParams::fromOptions([
            'password' => '',
        ]);

        self::assertNull($params->auth);
    }

    #[Test]
    public function persistentStringId(): void
    {
        $params = ConnectionParams::fromOptions([
            'persistent' => 'my-connection',
        ]);

        self::assertSame('my-connection', $params->persistent);
    }

    #[Test]
    public function persistentTrueAutoId(): void
    {
        $params = ConnectionParams::fromOptions([
            'persistent' => true,
        ]);

        self::assertTrue($params->persistent);
    }

    #[Test]
    public function persistentFalseOff(): void
    {
        $params = ConnectionParams::fromOptions([
            'persistent' => false,
        ]);

        self::assertFalse($params->persistent);
    }

    #[Test]
    public function persistentLegacyPersistentId(): void
    {
        $params = ConnectionParams::fromOptions([
            'persistent_id' => 'legacy-id',
        ]);

        self::assertSame('legacy-id', $params->persistent);
    }

    #[Test]
    public function persistentKeyTakesPriorityOverPersistentId(): void
    {
        $params = ConnectionParams::fromOptions([
            'persistent' => 'primary-id',
            'persistent_id' => 'legacy-id',
        ]);

        self::assertSame('primary-id', $params->persistent);
    }

    #[Test]
    public function backoffArrayPassthrough(): void
    {
        $backoff = [
            'algorithm' => 6, // BACKOFF_ALGORITHM_DECORRELATED_JITTER
            'base' => 500,
            'cap' => 750,
        ];

        $params = ConnectionParams::fromOptions([
            'backoff' => $backoff,
        ]);

        self::assertSame($backoff, $params->backoff);
    }

    #[Test]
    public function backoffNonArrayIsIgnored(): void
    {
        $params = ConnectionParams::fromOptions([
            'backoff' => 'invalid',
        ]);

        self::assertNull($params->backoff);
    }

    #[Test]
    public function readonlyPropertiesAreImmutable(): void
    {
        $params = ConnectionParams::fromOptions([
            'connectTimeout' => 2.0,
            'database' => 3,
        ]);

        $reflection = new \ReflectionClass($params);
        foreach ($reflection->getProperties() as $property) {
            self::assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly', $property->getName()),
            );
        }
    }
}
