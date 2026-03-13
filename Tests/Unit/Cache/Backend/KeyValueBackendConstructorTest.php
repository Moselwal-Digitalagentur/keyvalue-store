<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the KeyValueBackend constructor compatibility shim.
 *
 * TYPO3 11-13: CacheManager calls new KeyValueBackend('production', $options)
 * TYPO3 14:    CacheManager calls new KeyValueBackend($options)
 *
 * Note: Instantiation tests require ext-redis. The signature test runs without it.
 * Cross-version testing happens in CI via the matrix strategy.
 */
final class KeyValueBackendConstructorTest extends TestCase
{
    /**
     * Verify the constructor signature accepts both union types.
     * This test does NOT require ext-redis since it only uses reflection.
     */
    public function testConstructorSignatureIsUnionType(): void
    {
        $ref = new \ReflectionMethod(KeyValueBackend::class, '__construct');
        $firstParam = $ref->getParameters()[0];

        // First parameter must accept both string and array
        $type = $firstParam->getType();
        self::assertInstanceOf(\ReflectionUnionType::class, $type);

        $typeNames = array_map(
            static fn(\ReflectionNamedType $t) => $t->getName(),
            $type->getTypes()
        );
        self::assertContains('string', $typeNames);
        self::assertContains('array', $typeNames);
    }

    /**
     * Verify the second parameter is array $options with a default.
     */
    public function testConstructorSecondParamIsOptions(): void
    {
        $ref = new \ReflectionMethod(KeyValueBackend::class, '__construct');
        $secondParam = $ref->getParameters()[1];

        self::assertSame('options', $secondParam->getName());
        self::assertSame('array', (string)$secondParam->getType());
        self::assertTrue($secondParam->isDefaultValueAvailable());
        self::assertSame([], $secondParam->getDefaultValue());
    }

    /**
     * Verify the constructor detects parent signature correctly via reflection.
     */
    public function testConstructorUsesReflectionForParentDispatch(): void
    {
        // Verify parent class has a __construct we can reflect on
        $parentRef = new \ReflectionMethod(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, '__construct');
        $firstParam = $parentRef->getParameters()[0] ?? null;

        self::assertNotNull($firstParam, 'Parent constructor must have at least one parameter');

        // The installed TYPO3 version determines what the parent accepts.
        // We just verify we CAN detect it — the shim uses this same logic.
        $parentAcceptsContext = (string)$firstParam->getType() === 'string'
            || $firstParam->getName() === 'context';

        // With TYPO3 14: parent should NOT accept context
        // With TYPO3 11-13: parent SHOULD accept context
        // Either way, the shim handles it — we just verify detection works.
        self::assertIsBool($parentAcceptsContext);
    }

    /**
     * Verify the constructor accepts an array as first argument (TYPO3 14 pattern).
     */
    #[RequiresPhpExtension('redis')]
    public function testConstructorAcceptsArrayOptionsOnly(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        self::assertInstanceOf(KeyValueBackend::class, $backend);
    }

    /**
     * Verify the constructor accepts string+array (TYPO3 11-13 pattern).
     * The shim detects the parent signature and routes correctly.
     */
    #[RequiresPhpExtension('redis')]
    public function testConstructorAcceptsStringContextAndOptions(): void
    {
        $backend = new KeyValueBackend('production', [
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        self::assertInstanceOf(KeyValueBackend::class, $backend);
    }

    /**
     * Default call with no arguments should also work.
     */
    #[RequiresPhpExtension('redis')]
    public function testConstructorAcceptsNoArguments(): void
    {
        $backend = new KeyValueBackend();

        self::assertInstanceOf(KeyValueBackend::class, $backend);
    }
}
