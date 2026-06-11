<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.4 session-ID hashing (audit finding M11).
 *
 * Session IDs land in Redis HMAC-hashed by default. Without that an
 * operator with read access to the cache could lift any active session
 * by reading the raw key and replaying it as a cookie. The cookie value
 * the user holds is unchanged; only the persistence key is rewritten.
 */
final class KeyValueSessionBackendHashingTest extends TestCase
{
    private const FIXTURE_SECRET = 'test-fixture-encryption-key-32bytes';

    #[Test]
    public function keyIsHmacOfSessionIdWithConfiguredSecret(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'prefix' => 'typo3:sess:fe:',
            'hashSecret' => self::FIXTURE_SECRET,
        ]);

        $key = $this->invokeKey($backend, 'plain-session-id-12345');

        $expected = 'typo3:sess:fe:' . hash_hmac('sha256', 'plain-session-id-12345', self::FIXTURE_SECRET);
        self::assertSame($expected, $key);
        self::assertStringNotContainsString('plain-session-id-12345', $key, 'Raw session ID must not appear in the cache key');
    }

    #[Test]
    public function keyFallsBackToEncryptionKeyWhenHashSecretAbsent(): void
    {
        $previous = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::FIXTURE_SECRET;

        try {
            $backend = new KeyValueSessionBackend();
            $backend->initialize('FE', [
                'hostname' => 'irrelevant.test',
                'prefix' => 'typo3:sess:fe:',
            ]);

            $key = $this->invokeKey($backend, 'plain-session-id-67890');
            $expected = 'typo3:sess:fe:' . hash_hmac('sha256', 'plain-session-id-67890', self::FIXTURE_SECRET);
            self::assertSame($expected, $key);
        } finally {
            if (null === $previous) {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $previous;
            }
        }
    }

    #[Test]
    public function hashingCanBeDisabledForLegacyMigration(): void
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'prefix' => 'typo3:sess:fe:',
            'hashSessionIds' => false,
        ]);

        $key = $this->invokeKey($backend, 'raw-legacy-id');
        self::assertSame('typo3:sess:fe:raw-legacy-id', $key);
    }

    #[Test]
    public function validateConfigurationThrowsWhenHashingEnabledWithoutSecret(): void
    {
        $previous = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionCode(1733012001);

            $backend = new KeyValueSessionBackend();
            $backend->initialize('FE', [
                'hostname' => 'irrelevant.test',
                // hashSessionIds defaults to true, hashSecret missing.
            ]);
        } finally {
            if (null !== $previous) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $previous;
            }
        }
    }

    private function invokeKey(KeyValueSessionBackend $backend, string $sessionId): string
    {
        $method = new \ReflectionMethod($backend, 'key');

        return (string) $method->invoke($backend, $sessionId);
    }
}
