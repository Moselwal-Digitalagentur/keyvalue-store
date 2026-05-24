<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Locking;

use Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Functional tests for KeyValueLockingStrategy acquire/release and non-blocking mode.
 * Requires a running Redis instance.
 *
 * Covers T017 (acquire/release) and T018 (non-blocking mode).
 */
#[RequiresPhpExtension('redis')]
final class KeyValueLockingStrategyFunctionalTest extends TestCase
{
    private ?array $originalGlobals = null;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $redis = new \Redis();
            $redis->connect($host, $port, 1.0);
            $redis->ping();
            $redis->close();
        } catch (\RedisException) {
            self::markTestSkipped('Redis is not available at ' . $host . ':' . $port);
        }

        // Save original GLOBALS
        $this->originalGlobals = $GLOBALS['TYPO3_CONF_VARS'] ?? null;

        // Configure locking strategy
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options'] = [
            'host' => $host,
            'port' => $port,
            'database' => 15,
            'ttl' => 5,
        ];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key-for-locking-tests';
    }

    protected function tearDown(): void
    {
        if (null !== $this->originalGlobals) {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalGlobals;
        } else {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        }
    }

    // -----------------------------------------------------------------------
    // T017: Acquire and Release
    // -----------------------------------------------------------------------

    public function testAcquireExclusiveLock(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-acquire');

        $result = $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        self::assertTrue($result);
        self::assertTrue($lock->isAcquired());

        $lock->release();
    }

    public function testReleaseAfterAcquire(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-release');

        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        self::assertTrue($lock->isAcquired());

        $released = $lock->release();

        self::assertTrue($released);
        self::assertFalse($lock->isAcquired());
    }

    public function testReleaseWithoutAcquireReturnsTrue(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-no-acquire');

        // Releasing a lock that was never acquired should return true
        $result = $lock->release();

        self::assertTrue($result);
        self::assertFalse($lock->isAcquired());
    }

    public function testAcquireTwiceReturnsTrueWithoutReacquiring(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-double');

        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        self::assertTrue($lock->isAcquired());

        // Second acquire should return true immediately (already acquired)
        $result = $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        self::assertTrue($result);

        $lock->release();
    }

    public function testDestroyReleasesLock(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-destroy');

        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        self::assertTrue($lock->isAcquired());

        $lock->destroy();
        self::assertFalse($lock->isAcquired());
    }

    public function testLockIsReleasedOnDestruct(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-destruct');
        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        // Destroy the lock instance
        unset($lock);

        // A new lock with the same resource should be acquirable
        $newLock = new KeyValueLockingStrategy('test-resource-destruct');
        $result = $newLock->acquire(
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        self::assertTrue($result);
        $newLock->release();
    }

    // -----------------------------------------------------------------------
    // T018: Non-blocking mode
    // -----------------------------------------------------------------------

    public function testNonBlockingAcquireSucceedsWhenFree(): void
    {
        $lock = new KeyValueLockingStrategy('test-resource-noblock-free');

        $result = $lock->acquire(
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );

        self::assertTrue($result);
        self::assertTrue($lock->isAcquired());

        $lock->release();
    }

    public function testNonBlockingAcquireThrowsWhenLocked(): void
    {
        // First lock acquires the resource
        $firstLock = new KeyValueLockingStrategy('test-resource-noblock-taken');
        $firstLock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        // Second lock tries non-blocking acquire — should throw
        $secondLock = new KeyValueLockingStrategy('test-resource-noblock-taken');

        $this->expectException(LockAcquireWouldBlockException::class);
        $secondLock->acquire(
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
    }

    public function testLockAfterReleaseBySameName(): void
    {
        // First lock acquires and releases
        $firstLock = new KeyValueLockingStrategy('test-resource-reacquire');
        $firstLock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        $firstLock->release();

        // Second lock should succeed
        $secondLock = new KeyValueLockingStrategy('test-resource-reacquire');
        $result = $secondLock->acquire(
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );

        self::assertTrue($result);
        $secondLock->release();
    }
}
