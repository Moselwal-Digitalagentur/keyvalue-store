<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.1.0: renew() must execute the GET/SETEX/DEL sequence atomically via
 * a Lua EVAL so a concurrent update() landing between the GET and SETEX
 * cannot be silently overwritten.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendRenewAtomicTest extends TestCase
{
    #[Test]
    public function renewIssuesSingleEvalWithRenewScript(): void
    {
        $renewScript = $this->renewScriptConstant();

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('eval')
            ->with(
                $renewScript,
                ['typo3:sess:fe:old', 'typo3:sess:fe:new', '3600'],
                2,
            )
            ->willReturn(1);

        $redis->expects(self::never())->method('get');
        $redis->expects(self::never())->method('setex');
        $redis->expects(self::never())->method('del');

        $backend = $this->buildBackendWithMockRedis($redis);

        self::assertTrue($backend->renew('old', 'new'));
    }

    #[Test]
    public function renewReturnsFalseWhenScriptSignalsMissingSession(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn(0);

        $backend = $this->buildBackendWithMockRedis($redis);

        self::assertFalse($backend->renew('missing', 'new'));
    }

    private function buildBackendWithMockRedis(\Redis $redis): KeyValueSessionBackend
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'sessionLifetime' => 3600,
        ]);

        $prop = new \ReflectionProperty($backend, 'redis');
        $prop->setValue($backend, $redis);

        return $backend;
    }

    private function renewScriptConstant(): string
    {
        $ref = new \ReflectionClassConstant(KeyValueSessionBackend::class, 'RENEW_SCRIPT');

        return (string) $ref->getValue();
    }
}
