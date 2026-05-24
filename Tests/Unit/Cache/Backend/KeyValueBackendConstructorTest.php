<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * KeyValueBackend constructor: TYPO3 14 array-only signature.
 *
 * v4.0.0 collapsed the supported matrix to TYPO3 14 (PHP 8.5 baseline).
 * v4.1.0 dropped the TYPO3 11-13 compatibility shim — the constructor
 * now mirrors the TYPO3 14 CacheManager call shape directly without
 * runtime reflection on the parent signature.
 */
final class KeyValueBackendConstructorTest extends TestCase
{
    public function testConstructorAcceptsArrayParameter(): void
    {
        $ref = new \ReflectionMethod(KeyValueBackend::class, '__construct');
        $params = $ref->getParameters();

        self::assertCount(1, $params, 'TYPO3 14 constructor takes only the options array');

        $firstParam = $params[0];
        self::assertSame('options', $firstParam->getName());
        self::assertSame('array', (string) $firstParam->getType());
        self::assertTrue($firstParam->isDefaultValueAvailable());
        self::assertSame([], $firstParam->getDefaultValue());
    }

    public function testParentRedisBackendIsTypo314Signature(): void
    {
        // The shim is gone — parent::__construct(array) must work without
        // any context-string detection. If a future TYPO3 update brings
        // back the context param, this test surfaces it as a hard failure
        // so we re-introduce a shim deliberately.
        $parentRef = new \ReflectionMethod(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, '__construct');
        $firstParam = $parentRef->getParameters()[0] ?? null;

        self::assertNotNull($firstParam);
        self::assertSame('array', (string) $firstParam->getType());
        self::assertSame('options', $firstParam->getName());
    }

    #[RequiresPhpExtension('redis')]
    public function testConstructorAcceptsArrayOptions(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        self::assertInstanceOf(KeyValueBackend::class, $backend);
    }

    #[RequiresPhpExtension('redis')]
    public function testConstructorAcceptsNoArguments(): void
    {
        $backend = new KeyValueBackend();

        self::assertInstanceOf(KeyValueBackend::class, $backend);
    }
}
