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
    public function defaultSerializerIsPhpNative(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeastOnce())
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value): bool {
                if (\Redis::OPT_SERIALIZER === $option) {
                    self::assertSame(\Redis::SERIALIZER_PHP, $value);
                }

                return true;
            });

        $this->invokeApplySerializerOption($redis, []);
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
    public function noneSerializerDisablesPhpredisLayer(): void
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
    public function unknownSerializerValueThrows(): void
    {
        $redis = $this->createMock(\Redis::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1700100002);

        $this->invokeApplySerializerOption($redis, ['serializer' => 'msgpack-but-it-is-not-installed']);
    }

    #[Test]
    public function autoSelectsIgbinaryIfAvailable(): void
    {
        $expected = extension_loaded('igbinary') && \defined('Redis::SERIALIZER_IGBINARY')
            ? \Redis::SERIALIZER_IGBINARY
            : \Redis::SERIALIZER_PHP;

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
