<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Functional tests for KeyValueBackend set/get/remove cycle.
 * Requires a running Redis instance.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendFunctionalTest extends TestCase
{
    private KeyValueBackend $backend;

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

        $this->backend = new KeyValueBackend([
            'hostname' => $host,
            'port' => $port,
            'database' => 15,
        ]);

        $frontend = new VariableFrontend('test_cache', $this->backend);
        $this->backend->setCache($frontend);
        $this->backend->initializeObject();
        $this->backend->flush();
    }

    protected function tearDown(): void
    {
        try {
            $this->backend->flush();
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }

    public function testSetAndGetEntry(): void
    {
        $this->backend->set('entry1', 'value1');

        $result = $this->backend->get('entry1');
        self::assertSame('value1', $result);
    }

    public function testGetReturnsSerializedData(): void
    {
        $data = ['key' => 'value', 'nested' => [1, 2, 3]];
        $this->backend->set('complex', serialize($data));

        $result = unserialize($this->backend->get('complex'));
        self::assertSame($data, $result);
    }

    public function testGetReturnsFalseForNonexistentEntry(): void
    {
        $result = $this->backend->get('nonexistent');
        self::assertFalse($result);
    }

    public function testRemoveDeletesEntry(): void
    {
        $this->backend->set('to_remove', 'data');
        self::assertSame('data', $this->backend->get('to_remove'));

        $removed = $this->backend->remove('to_remove');
        self::assertTrue($removed);
        self::assertFalse($this->backend->get('to_remove'));
    }

    public function testRemoveReturnsFalseForNonexistentEntry(): void
    {
        $result = $this->backend->remove('never_existed');
        self::assertFalse($result);
    }

    public function testHasReturnsTrueForExistingEntry(): void
    {
        $this->backend->set('exists', 'yes');

        self::assertTrue($this->backend->has('exists'));
    }

    public function testHasReturnsFalseForNonexistentEntry(): void
    {
        self::assertFalse($this->backend->has('nonexistent'));
    }

    public function testFlushRemovesAllEntries(): void
    {
        $this->backend->set('a', '1');
        $this->backend->set('b', '2');

        $this->backend->flush();

        self::assertFalse($this->backend->get('a'));
        self::assertFalse($this->backend->get('b'));
    }

    public function testSetWithLifetime(): void
    {
        $this->backend->set('ttl_entry', 'expires', [], 1);

        self::assertSame('expires', $this->backend->get('ttl_entry'));

        // Wait for expiry
        sleep(2);

        self::assertFalse($this->backend->get('ttl_entry'));
    }

    public function testSetOverwritesExistingEntry(): void
    {
        $this->backend->set('overwrite', 'original');
        $this->backend->set('overwrite', 'updated');

        self::assertSame('updated', $this->backend->get('overwrite'));
    }
}
