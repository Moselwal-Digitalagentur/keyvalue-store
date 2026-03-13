<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection;

use Moselwal\KeyValueStore\Connection\TlsContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TlsContextBuilder::build() method.
 *
 * Covers TLS/SSL stream context generation for Redis connections,
 * including mTLS, CA certificate, peer verification, and peer name options.
 */
final class TlsContextBuilderTest extends TestCase
{
    private TlsContextBuilder $subject;

    protected function setUp(): void
    {
        $this->subject = new TlsContextBuilder();
    }

    /**
     * Test 1: Returns null when tls is explicitly false.
     */
    public function testReturnsNullWhenTlsIsFalse(): void
    {
        $result = $this->subject->build(['tls' => false]);

        self::assertNull($result);
    }

    /**
     * Test 2: Returns null when tls option is missing entirely.
     */
    public function testReturnsNullWhenTlsOptionIsMissing(): void
    {
        $result = $this->subject->build([]);

        self::assertNull($result);
    }

    /**
     * Test 3: Returns SSL context with defaults when tls=true and no other options.
     */
    public function testReturnsDefaultSslContextWhenTlsIsTrue(): void
    {
        $result = $this->subject->build(['tls' => true]);

        self::assertIsArray($result);
        self::assertArrayHasKey('ssl', $result);

        $ssl = $result['ssl'];
        self::assertTrue($ssl['verify_peer']);
        self::assertTrue($ssl['verify_peer_name']);
        self::assertFalse($ssl['allow_self_signed']);
        self::assertArrayNotHasKey('cafile', $ssl);
        self::assertArrayNotHasKey('local_cert', $ssl);
        self::assertArrayNotHasKey('local_pk', $ssl);
        self::assertArrayNotHasKey('peer_name', $ssl);
    }

    /**
     * Test 4: Includes cafile when ca_file is set.
     */
    public function testIncludesCafileWhenCaFileIsSet(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'ca_file' => '/etc/ssl/certs/ca.pem',
        ]);

        self::assertIsArray($result);
        self::assertSame('/etc/ssl/certs/ca.pem', $result['ssl']['cafile']);
    }

    /**
     * Test 5: Includes local_cert and local_pk for mTLS.
     */
    public function testIncludesMtlsCertAndKey(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'cert_file' => '/etc/ssl/client.crt',
            'key_file' => '/etc/ssl/client.key',
        ]);

        self::assertIsArray($result);
        self::assertSame('/etc/ssl/client.crt', $result['ssl']['local_cert']);
        self::assertSame('/etc/ssl/client.key', $result['ssl']['local_pk']);
    }

    /**
     * Test 6: Throws InvalidArgumentException when cert_file set without key_file.
     */
    public function testThrowsExceptionWhenCertFileSetWithoutKeyFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mTLS requires both cert_file and key_file to be set.');

        $this->subject->build([
            'tls' => true,
            'cert_file' => '/etc/ssl/client.crt',
        ]);
    }

    /**
     * Test 7: Throws InvalidArgumentException when key_file set without cert_file.
     */
    public function testThrowsExceptionWhenKeyFileSetWithoutCertFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mTLS requires both cert_file and key_file to be set.');

        $this->subject->build([
            'tls' => true,
            'key_file' => '/etc/ssl/client.key',
        ]);
    }

    /**
     * Test 8: Includes peer_name when set.
     */
    public function testIncludesPeerNameWhenSet(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'peer_name' => 'redis.example.com',
        ]);

        self::assertIsArray($result);
        self::assertSame('redis.example.com', $result['ssl']['peer_name']);
    }

    /**
     * Test 9: Respects verify_peer=false.
     */
    public function testRespectsVerifyPeerFalse(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'verify_peer' => false,
        ]);

        self::assertIsArray($result);
        self::assertFalse($result['ssl']['verify_peer']);
        self::assertTrue($result['ssl']['verify_peer_name']);
    }

    /**
     * Test 10: Respects allow_self_signed=true.
     */
    public function testRespectsAllowSelfSignedTrue(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'allow_self_signed' => true,
        ]);

        self::assertIsArray($result);
        self::assertTrue($result['ssl']['allow_self_signed']);
    }

    /**
     * Test 11: Full mTLS configuration with all options.
     */
    public function testFullMtlsConfiguration(): void
    {
        $result = $this->subject->build([
            'tls' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'ca_file' => '/etc/ssl/certs/ca-bundle.crt',
            'cert_file' => '/etc/ssl/client.crt',
            'key_file' => '/etc/ssl/client.key',
            'peer_name' => 'redis.production.internal',
        ]);

        self::assertIsArray($result);
        self::assertArrayHasKey('ssl', $result);

        $ssl = $result['ssl'];
        self::assertTrue($ssl['verify_peer']);
        self::assertTrue($ssl['verify_peer_name']);
        self::assertFalse($ssl['allow_self_signed']);
        self::assertSame('/etc/ssl/certs/ca-bundle.crt', $ssl['cafile']);
        self::assertSame('/etc/ssl/client.crt', $ssl['local_cert']);
        self::assertSame('/etc/ssl/client.key', $ssl['local_pk']);
        self::assertSame('redis.production.internal', $ssl['peer_name']);
    }
}
