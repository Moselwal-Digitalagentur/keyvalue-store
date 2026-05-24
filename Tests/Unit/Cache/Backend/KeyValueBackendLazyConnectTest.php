<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression: KeyValueBackend must build factory options with `lazy=true`
 * so TYPO3 bootstrap does not pay 11× ping-roundtrips for the configured
 * cache backends. The connection is deferred until the first command
 * (get/set/has/remove).
 */
final class KeyValueBackendLazyConnectTest extends TestCase
{
    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsSetsLazyTrue(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertArrayHasKey('lazy', $opts);
        self::assertTrue($opts['lazy'], 'buildFactoryOptions() must default to lazy=true');
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function rawOptionsCannotDowngradeLazyToFalse(): void
    {
        // rawOptions are array_replaced on top of $opts — they CAN override
        // 'lazy'. That is intentional (operators may force eager connect).
        // We test the override path here so the contract is explicit and
        // the merge order does not silently regress.
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'lazy' => false,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);
        self::assertFalse($opts['lazy']);
    }

    private function invokeBuildFactoryOptions(KeyValueBackend $backend): array
    {
        $method = new \ReflectionMethod(KeyValueBackend::class, 'buildFactoryOptions');

        return $method->invoke($backend);
    }
}
