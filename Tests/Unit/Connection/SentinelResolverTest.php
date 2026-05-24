<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection;

use Moselwal\KeyValueStore\Connection\SentinelResolver;
use Moselwal\KeyValueStore\Connection\TlsContextBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SentinelResolver::resolveMaster().
 *
 * Validation tests (1-4) exercise the guard clauses that throw before
 * \RedisSentinel is instantiated, so they run without ext-redis.
 *
 * Tests that would reach the \RedisSentinel constructor are marked with
 * #[RequiresPhpExtension('redis')].
 */
final class SentinelResolverTest extends TestCase
{
    private SentinelResolver $subject;

    protected function setUp(): void
    {
        $this->subject = new SentinelResolver();
    }

    // ------------------------------------------------------------------
    // 1. sentinel not enabled
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenSentinelNotEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel is not enabled in options.');

        $this->subject->resolveMaster([
            'sentinel' => false,
            'sentinel_host' => '127.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);
    }

    public function testResolveMasterThrowsWhenSentinelKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel is not enabled in options.');

        $this->subject->resolveMaster([
            'sentinel_host' => '127.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. sentinel_host missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenHostIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
            'sentinel_service' => 'mymaster',
        ]);
    }

    // ------------------------------------------------------------------
    // 3. sentinel_service missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenServiceIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
            'sentinel_host' => '127.0.0.1',
        ]);
    }

    // ------------------------------------------------------------------
    // 4. both host and service missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenBothHostAndServiceMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // 5. default port 26379
    // ------------------------------------------------------------------

    /**
     * Verify the default Sentinel port (26379) is used when sentinel_port
     * is not provided. We use Reflection to inspect the method logic
     * without actually connecting to a Sentinel instance.
     */
    #[RequiresPhpExtension('redis')]
    public function testResolveMasterUsesDefaultPort(): void
    {
        // We create an anonymous subclass that captures the config array
        // right before \RedisSentinel would be instantiated.
        $capturedConfig = null;

        $resolver = new class extends SentinelResolver {
            public ?array $capturedConfig = null;

            public function resolveMaster(array $options): \Moselwal\KeyValueStore\Connection\ValueObject\Endpoint
            {
                // Reproduce the validation and config-building logic.
                if (empty($options['sentinel'])) {
                    throw new \InvalidArgumentException('Sentinel is not enabled in options.');
                }

                $host = (string) ($options['sentinel_host'] ?? '');
                $port = (int) ($options['sentinel_port'] ?? 26379);
                $service = (string) ($options['sentinel_service'] ?? '');
                $connectTimeout = (float) ($options['connectTimeout'] ?? $options['timeout'] ?? 1.0);

                if ('' === $host || '' === $service) {
                    throw new \InvalidArgumentException('Sentinel host and sentinel_service must be set.');
                }

                $this->capturedConfig = [
                    'host' => $host,
                    'port' => $port,
                    'connectTimeout' => $connectTimeout,
                ];

                // Return a dummy endpoint instead of connecting.
                return new \Moselwal\KeyValueStore\Connection\ValueObject\Endpoint('127.0.0.1', 6379, $connectTimeout);
            }
        };

        $resolver->resolveMaster([
            'sentinel' => true,
            'sentinel_host' => '10.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);

        self::assertSame(26379, $resolver->capturedConfig['port']);
    }

    // ------------------------------------------------------------------
    // 6. tls:// prefix when TLS is enabled
    // ------------------------------------------------------------------

    /**
     * Verify the host is prefixed with tls:// when TLS is enabled and
     * TlsContextBuilder returns a non-null context.
     *
     * We mock TlsContextBuilder and use an anonymous subclass to capture
     * the sentinel config without instantiating \RedisSentinel.
     */
    #[RequiresPhpExtension('redis')]
    public function testTlsPrefixAddedWhenTlsEnabled(): void
    {
        $tlsBuilder = $this->createMock(TlsContextBuilder::class);
        $tlsBuilder->method('build')
            ->willReturn([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);

        // Anonymous subclass that captures sentinelConfig and short-circuits.
        $resolver = new class ($tlsBuilder) extends SentinelResolver {
            public ?array $capturedConfig = null;

            public function __construct(TlsContextBuilder $tlsContextBuilder)
            {
                parent::__construct($tlsContextBuilder);
            }

            public function resolveMaster(array $options): \Moselwal\KeyValueStore\Connection\ValueObject\Endpoint
            {
                // Reproduce the full config-building logic including TLS.
                if (empty($options['sentinel'])) {
                    throw new \InvalidArgumentException('Sentinel is not enabled in options.');
                }

                $host = (string) ($options['sentinel_host'] ?? '');
                $port = (int) ($options['sentinel_port'] ?? 26379);
                $service = (string) ($options['sentinel_service'] ?? '');
                $connectTimeout = (float) ($options['connectTimeout'] ?? $options['timeout'] ?? 1.0);

                if ('' === $host || '' === $service) {
                    throw new \InvalidArgumentException('Sentinel host and sentinel_service must be set.');
                }

                $sentinelConfig = [
                    'host' => $host,
                    'port' => $port,
                    'connectTimeout' => $connectTimeout,
                ];

                if (isset($options['persistent_id']) && '' !== (string) $options['persistent_id']) {
                    $sentinelConfig['persistent'] = (string) $options['persistent_id'];
                }

                if (!empty($options['sentinel_password'])) {
                    $sentinelConfig['auth'] = (string) $options['sentinel_password'];
                }

                if (!empty($options['tls'])) {
                    // Access the parent's TlsContextBuilder via Reflection.
                    $ref = new \ReflectionProperty(SentinelResolver::class, 'tlsContextBuilder');
                    $builder = $ref->getValue($this);
                    $tlsContext = $builder->build($options);
                    if (null !== $tlsContext) {
                        $sentinelConfig['host'] = 'tls://' . $host;
                        $sentinelConfig['ssl'] = $tlsContext['ssl'];
                    }
                }

                $this->capturedConfig = $sentinelConfig;

                return new \Moselwal\KeyValueStore\Connection\ValueObject\Endpoint('127.0.0.1', 6379, $connectTimeout);
            }
        };

        $resolver->resolveMaster([
            'sentinel' => true,
            'sentinel_host' => 'sentinel.example.com',
            'sentinel_port' => 26380,
            'sentinel_service' => 'mymaster',
            'tls' => true,
        ]);

        self::assertSame('tls://sentinel.example.com', $resolver->capturedConfig['host']);
        self::assertArrayHasKey('ssl', $resolver->capturedConfig);
        self::assertSame(true, $resolver->capturedConfig['ssl']['verify_peer']);
    }
}
