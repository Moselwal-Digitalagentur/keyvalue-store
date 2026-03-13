<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Locking;

use Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Tests for KeyValueLockingStrategy::getCapabilities() and ::getPriority().
 *
 * These are static methods that do not require a Redis connection.
 */
final class KeyValueLockingStrategyTest extends TestCase
{
    private mixed $originalLockingConfig = null;
    private bool $hadConfig = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadConfig = isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]);
        if ($this->hadConfig) {
            $this->originalLockingConfig = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class];
        }
    }

    protected function tearDown(): void
    {
        if ($this->hadConfig) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class] = $this->originalLockingConfig;
        } else {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]);
        }
        parent::tearDown();
    }

    /**
     * Verify the capability bitmask includes both EXCLUSIVE and NOBLOCK.
     */
    public function testGetCapabilitiesReturnsExclusiveAndNoblock(): void
    {
        $capabilities = KeyValueLockingStrategy::getCapabilities();

        self::assertSame(
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
            $capabilities
        );
        self::assertNotSame(0, $capabilities & LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        self::assertNotSame(0, $capabilities & LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
    }

    /**
     * Verify default priority (95) when no GLOBALS configuration is set.
     */
    public function testGetPriorityReturnsDefaultWhenNoConfiguration(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]);

        self::assertSame(95, KeyValueLockingStrategy::getPriority());
    }

    /**
     * Verify that a custom priority value from GLOBALS is returned.
     */
    public function testGetPriorityReturnsConfiguredValue(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options'] = [
            'priority' => 42,
        ];

        self::assertSame(42, KeyValueLockingStrategy::getPriority());
    }

    /**
     * Verify fallback to default when the configuration value is not an array.
     */
    public function testGetPriorityReturnsDefaultWhenConfigIsNotArray(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options'] = 'invalid';

        self::assertSame(95, KeyValueLockingStrategy::getPriority());
    }
}
