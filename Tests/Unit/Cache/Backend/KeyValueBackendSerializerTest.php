<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.3.0: opt-in `serializer` option for the Redis cache backend.
 *
 * Default stays `php` (BC-safe; no on-disk format change from v4.2.0).
 * Operators can switch to `igbinary`, `none`, or `auto` — but must
 * follow the FLUSHDB-then-deploy sequence documented in CHANGELOG and
 * README, because mixed PHP-serialize / igbinary payloads in the same
 * keyspace would silent-corrupt or throw on unserialize.
 *
 * The tests do not exercise the real phpredis OPT_SERIALIZER path
 * (that would require a live Redis); they pin the configuration
 * dispatch — which constant ends up on the Redis instance for each
 * `serializer` value.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendSerializerTest extends TestCase
{
    #[Test]
    public function defaultLeavesSerializerUntouchedSoTypo3OwnsEncoding(): void
    {
        // No 'serializer' option set → applySerializerOption() must NOT
        // call setOption(OPT_SERIALIZER, …) at all. TYPO3's VariableFrontend
        // does its own serialize()/unserialize(); double-encoding via
        // phpredis would corrupt all reads. This is the v4.3.1 hotfix.
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())
            ->method('setOption')
            ->with(\Redis::OPT_SERIALIZER, self::anything());

        $this->invokeApplySerializerOption($redis, []);
    }

    #[Test]
    public function explicitNoneSetsSerializerNone(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeastOnce())
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value): bool {
                if (\Redis::OPT_SERIALIZER === $option) {
                    self::assertSame(\Redis::SERIALIZER_NONE, $value);
                }

                return true;
            });

        $this->invokeApplySerializerOption($redis, ['serializer' => 'none']);
    }

    #[Test]
    public function explicitPhpSetsSerializerPhp(): void
    {
        // For advanced setups where the frontend does NOT serialise itself.
        // Setting 'php' is an explicit opt-in; not the default.
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeastOnce())
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value): bool {
                if (\Redis::OPT_SERIALIZER === $option) {
                    self::assertSame(\Redis::SERIALIZER_PHP, $value);
                }

                return true;
            });

        $this->invokeApplySerializerOption($redis, ['serializer' => 'php']);
    }

    #[Test]
    public function explicitIgbinaryUsesIgbinaryWhenExtensionLoaded(): void
    {
        if (!extension_loaded('igbinary') || !\defined('Redis::SERIALIZER_IGBINARY')) {
            self::markTestSkipped('ext-igbinary not loaded — handled by separate fallback test');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeastOnce())
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value): bool {
                if (\Redis::OPT_SERIALIZER === $option) {
                    self::assertSame(\Redis::SERIALIZER_IGBINARY, $value);
                }

                return true;
            });

        $this->invokeApplySerializerOption($redis, ['serializer' => 'igbinary']);
    }

    #[Test]
    public function unknownSerializerValueThrows(): void
    {
        $redis = $this->createMock(\Redis::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1700100002);

        $this->invokeApplySerializerOption($redis, ['serializer' => 'msgpack-but-it-is-not-installed']);
    }

    #[Test]
    public function autoSelectsIgbinaryIfAvailableOtherwiseNone(): void
    {
        // 'auto' fallback is SERIALIZER_NONE (not PHP) so the wire format
        // stays identical to the default-unset case when ext-igbinary is
        // missing — TYPO3 keeps owning the serialisation layer.
        $expected = extension_loaded('igbinary') && \defined('Redis::SERIALIZER_IGBINARY')
            ? \Redis::SERIALIZER_IGBINARY
            : \Redis::SERIALIZER_NONE;

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeastOnce())
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value) use ($expected): bool {
                if (\Redis::OPT_SERIALIZER === $option) {
                    self::assertSame($expected, $value);
                }

                return true;
            });

        $this->invokeApplySerializerOption($redis, ['serializer' => 'auto']);
    }

    /**
     * Build a backend with the desired rawOptions and call the private
     * applySerializerOption() with a mocked Redis instance attached.
     */
    private function invokeApplySerializerOption(\Redis $redis, array $rawOptions): void
    {
        $backend = new KeyValueBackend(array_merge(['hostname' => '127.0.0.1'], $rawOptions));

        $reflection = new \ReflectionClass($backend);
        $reflection->getProperty('redis')->setValue($backend, $redis);
        $reflection->getProperty('rawOptions')->setValue($backend, $rawOptions);

        $method = $reflection->getMethod('applySerializerOption');
        $method->invoke($backend);
    }
}
