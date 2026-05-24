<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Regression for the v4.1.0 getAll() refactor:
 *
 * - Previous implementation did one GET per key after SCAN (N+1
 *   roundtrips). The new implementation issues one MGET per SCAN chunk.
 * - Previous implementation initialised `$cursor = null`. phpredis takes
 *   the cursor by reference and only advances it to 0 once the iteration
 *   is exhausted. The `while ($cursor > 0)` guard at the end short-circuited
 *   on the very first iteration (null > 0 === false) when phpredis did
 *   not coerce implicitly — silently truncating the result list after
 *   the first SCAN page.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendGetAllTest extends TestCase
{
    #[Test]
    public function getAllUsesMgetInsteadOfPerKeyGet(): void
    {
        $redis = $this->createMock(\Redis::class);

        // SCAN returns one page, then exhausts (cursor becomes 0).
        $redis->expects(self::once())
            ->method('scan')
            ->willReturnCallback(function (&$cursor, string $pattern, int $count) {
                $cursor = 0;

                return ['typo3:sess:fe:a', 'typo3:sess:fe:b', 'typo3:sess:fe:c'];
            });

        // The whole point of T1.2: one MGET, not three GETs.
        $redis->expects(self::once())
            ->method('mget')
            ->with(['typo3:sess:fe:a', 'typo3:sess:fe:b', 'typo3:sess:fe:c'])
            ->willReturn([
                json_encode(['ses_id' => 'a']),
                json_encode(['ses_id' => 'b']),
                false, // simulate concurrent expiry between SCAN and MGET
            ]);

        $redis->expects(self::never())->method('get');

        $sessions = $this->invokeGetAllWithMockRedis($redis);

        self::assertCount(2, $sessions, 'expired entries must be filtered, valid ones returned');
        self::assertSame('a', $sessions[0]['ses_id']);
        self::assertSame('b', $sessions[1]['ses_id']);
    }

    #[Test]
    public function getAllIteratesAcrossSeveralScanChunks(): void
    {
        $redis = $this->createMock(\Redis::class);

        $callCount = 0;
        $redis->expects(self::exactly(2))
            ->method('scan')
            ->willReturnCallback(function (&$cursor, string $pattern, int $count) use (&$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    $cursor = 42;

                    return ['typo3:sess:fe:a'];
                }
                $cursor = 0;

                return ['typo3:sess:fe:b'];
            });

        $redis->expects(self::exactly(2))
            ->method('mget')
            ->willReturnOnConsecutiveCalls(
                [json_encode(['ses_id' => 'a'])],
                [json_encode(['ses_id' => 'b'])],
            );

        $sessions = $this->invokeGetAllWithMockRedis($redis);

        self::assertCount(2, $sessions);
        // T2.3 regression guard: a null-initial cursor would have aborted
        // the loop after the first chunk and missed 'b' entirely.
        self::assertSame('a', $sessions[0]['ses_id']);
        self::assertSame('b', $sessions[1]['ses_id']);
    }

    /**
     * Inject a mocked Redis into the backend without going through the
     * real connection factory.
     */
    private function invokeGetAllWithMockRedis(\Redis $redis): array
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', ['hostname' => 'irrelevant.test']);

        $prop = new \ReflectionProperty($backend, 'redis');
        $prop->setValue($backend, $redis);

        return $backend->getAll();
    }
}
