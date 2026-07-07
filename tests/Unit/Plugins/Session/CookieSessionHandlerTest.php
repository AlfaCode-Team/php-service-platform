<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Session;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Crypto\Infrastructure\AesEncrypter;
use Plugins\Session\Infrastructure\Handlers\Contracts\CookieBackedHandler;
use Plugins\Session\Infrastructure\Handlers\CookieSessionConfig;
use Plugins\Session\Infrastructure\Handlers\CookieSessionHandler;
use Plugins\Session\Infrastructure\Store;
use Plugins\Session\Provider as SessionProvider;

#[CoversClass(CookieSessionHandler::class)]
#[CoversClass(CookieSessionConfig::class)]
final class CookieSessionHandlerTest extends TestCase
{
    private function encrypter(): EncryptionPort
    {
        return new AesEncrypter([str_repeat('k', 32)]);
    }

    /** Build a Store over a cookie handler with the given config. */
    private function store(CookieSessionConfig $config): Store
    {
        return new Store('hkm_session', new CookieSessionHandler($config));
    }

    // ── Provider wiring ───────────────────────────────────────────────────────

    public function test_provider_wires_cookie_driver_from_env_with_encryption(): void
    {
        $driverBackup = $_ENV['SESSION_DRIVER'] ?? null;
        $_ENV['SESSION_DRIVER'] = 'cookie';

        try {
            $core = new CoreContainer();
            $core->instance(EncryptionPort::class, $this->encrypter());

            $container = new ModuleContainer($core);
            $container->setScope('session.management');
            (new SessionProvider())->register($container);

            $session = $container->make(SessionPort::class);
            $this->assertInstanceOf(Store::class, $session);
            $this->assertInstanceOf(CookieBackedHandler::class, $session->handler());
        } finally {
            $driverBackup === null
                ? $_ENV['SESSION_DRIVER'] = null
                : $_ENV['SESSION_DRIVER'] = $driverBackup;
            if ($driverBackup === null) {
                unset($_ENV['SESSION_DRIVER']);
            }
        }
    }

    // ── Round-trip ─────────────────────────────────────────────────────────────

    public function test_round_trips_attributes_through_an_encrypted_cookie(): void
    {
        $enc = $this->encrypter();

        $store1 = $this->store(new CookieSessionConfig(encrypter: $enc));
        $store1->start(null);
        $store1->put('user_id', 99);
        $store1->save();

        /** @var CookieBackedHandler $h1 */
        $h1 = $store1->handler();
        $cookieValue = $h1->outgoing();
        $this->assertIsString($cookieValue);
        $this->assertNotSame('', $cookieValue);
        $this->assertStringNotContainsString('user_id', $cookieValue); // encrypted

        $handler2 = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc));
        $handler2->prime($cookieValue);
        $store2 = new Store('hkm_session', $handler2);
        $store2->start(null);

        $this->assertSame(99, $store2->get('user_id'));
    }

    public function test_signed_path_round_trips_without_an_encrypter(): void
    {
        $config = new CookieSessionConfig(signingKey: 'super-secret');

        $store = $this->store($config);
        $store->start(null);
        $store->put('theme', 'dark');
        $store->save();

        $payload = $store->handler()->outgoing();
        $this->assertIsString($payload);
        $this->assertStringContainsString('.', $payload); // body.signature

        $back = new CookieSessionHandler($config);
        $back->prime($payload);
        $store2 = new Store('hkm_session', $back);
        $store2->start(null);

        $this->assertSame('dark', $store2->get('theme'));
    }

    // ── Tamper / signature ───────────────────────────────────────────────────────

    public function test_tampered_encrypted_cookie_yields_empty_session(): void
    {
        $handler = new CookieSessionHandler(new CookieSessionConfig(encrypter: $this->encrypter()));
        $handler->prime('not-a-valid-ciphertext');
        $store = new Store('hkm_session', $handler);
        $store->start(null);

        $this->assertFalse($store->has('user_id'));
    }

    public function test_modified_signed_payload_is_rejected(): void
    {
        $config = new CookieSessionConfig(signingKey: 'k1');
        $store  = $this->store($config);
        $store->start(null);
        $store->put('role', 'user');
        $store->save();

        $payload = (string) $store->handler()->outgoing();
        // Flip a character in the body (before the '.') — signature no longer matches.
        $payload[0] = $payload[0] === 'A' ? 'B' : 'A';

        $back = new CookieSessionHandler($config);
        $back->prime($payload);
        $store2 = new Store('hkm_session', $back);
        $store2->start(null);

        $this->assertFalse($store2->has('role'));
    }

    public function test_wrong_signing_key_is_rejected(): void
    {
        $store = $this->store(new CookieSessionConfig(signingKey: 'right-key'));
        $store->start(null);
        $store->put('x', 1);
        $store->save();
        $payload = (string) $store->handler()->outgoing();

        $attacker = new CookieSessionHandler(new CookieSessionConfig(signingKey: 'wrong-key'));
        $attacker->prime($payload);
        $store2 = new Store('hkm_session', $attacker);
        $store2->start(null);

        $this->assertFalse($store2->has('x'));
    }

    // ── Lifetime / idle ────────────────────────────────────────────────────────────

    public function test_expired_absolute_lifetime_is_rejected(): void
    {
        $enc    = $this->encrypter();
        $writer = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, lifetime: 1));
        $writer->write('id', (string) json_encode(['x' => 1]));
        $payload = (string) $writer->outgoing();

        sleep(2);
        $reader = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, lifetime: 1));
        $reader->prime($payload);

        $this->assertSame('', $reader->read('id'));
    }

    public function test_zero_lifetime_disables_the_absolute_check(): void
    {
        $enc    = $this->encrypter();
        $writer = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, lifetime: 0));
        $writer->write('id', (string) json_encode(['x' => 1]));
        $payload = (string) $writer->outgoing();

        sleep(1);
        $reader = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, lifetime: 0));
        $reader->prime($payload);

        $this->assertNotSame('', $reader->read('id')); // 0 = no absolute limit
    }

    public function test_idle_timeout_one_second_rejects_after_inactivity(): void
    {
        $enc    = $this->encrypter();
        $writer = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, idleTimeout: 1));
        $writer->write('id', (string) json_encode(['x' => 1]));
        $payload = (string) $writer->outgoing();

        sleep(2);
        $reader = new CookieSessionHandler(new CookieSessionConfig(encrypter: $enc, idleTimeout: 1));
        $reader->prime($payload);

        $this->assertSame('', $reader->read('id'));
    }

    public function test_absolute_lifetime_is_not_extended_by_resaving(): void
    {
        $enc    = $this->encrypter();
        $config = new CookieSessionConfig(encrypter: $enc, lifetime: 100);

        $store = $this->store($config);
        $store->start(null);
        $store->put('a', 1);
        $store->save();
        $first = (string) $store->handler()->outgoing();

        // Load it again and re-save — the issue time 't' must be preserved.
        $h2 = new CookieSessionHandler($config);
        $h2->prime($first);
        $store2 = new Store('hkm_session', $h2);
        $store2->start(null);
        $store2->put('b', 2);
        $store2->save();
        $second = (string) $h2->outgoing();

        $t1 = $this->envelopeField($enc, $first, 't');
        $t2 = $this->envelopeField($enc, $second, 't');
        $this->assertSame($t1, $t2, 'absolute issue time must be preserved across saves');
    }

    // ── Fingerprint binding ──────────────────────────────────────────────────────

    private function fpConfig(array $parts): CookieSessionConfig
    {
        return new CookieSessionConfig(signingKey: 'k', fingerprint: $parts);
    }

    /** Issue a session, return its cookie payload. */
    private function issueWith(CookieSessionConfig $config, ?string $ua, ?string $ip): string
    {
        $h = new CookieSessionHandler($config);
        $h->bindClient($ua, $ip);
        $store = new Store('hkm_session', $h);
        $store->start(null);
        $store->put('uid', 5);
        $store->save();

        return (string) $h->outgoing();
    }

    /** Re-load a payload as a (possibly different) client; return the Store. */
    private function reloadAs(CookieSessionConfig $config, string $payload, ?string $ua, ?string $ip): Store
    {
        $h = new CookieSessionHandler($config);
        $h->bindClient($ua, $ip);
        $h->prime($payload);
        $store = new Store('hkm_session', $h);
        $store->start(null);

        return $store;
    }

    public function test_fingerprint_ua_and_ip_accepts_same_client(): void
    {
        $config  = $this->fpConfig([CookieSessionConfig::FP_USER_AGENT, CookieSessionConfig::FP_IP]);
        $payload = $this->issueWith($config, 'Mozilla/5.0', '203.0.113.7');

        $store = $this->reloadAs($config, $payload, 'Mozilla/5.0', '203.0.113.7');
        $this->assertSame(5, $store->get('uid'));
    }

    public function test_fingerprint_ua_and_ip_rejects_different_client(): void
    {
        $config  = $this->fpConfig([CookieSessionConfig::FP_USER_AGENT, CookieSessionConfig::FP_IP]);
        $payload = $this->issueWith($config, 'Mozilla/5.0', '203.0.113.7');

        $store = $this->reloadAs($config, $payload, 'EvilBot/1.0', '198.51.100.9');
        $this->assertFalse($store->has('uid'));
    }

    public function test_fingerprint_ua_only_survives_ip_change(): void
    {
        // UA-only is the usability-friendly mode: a mobile user whose IP rotates
        // keeps their session as long as the browser is the same.
        $config  = $this->fpConfig([CookieSessionConfig::FP_USER_AGENT]);
        $payload = $this->issueWith($config, 'Mozilla/5.0', '203.0.113.7');

        $store = $this->reloadAs($config, $payload, 'Mozilla/5.0', '10.9.9.9'); // new IP
        $this->assertSame(5, $store->get('uid'), 'UA-only must survive an IP change');
    }

    public function test_fingerprint_ua_only_still_rejects_different_browser(): void
    {
        $config  = $this->fpConfig([CookieSessionConfig::FP_USER_AGENT]);
        $payload = $this->issueWith($config, 'Mozilla/5.0', '203.0.113.7');

        $store = $this->reloadAs($config, $payload, 'EvilBot/1.0', '203.0.113.7'); // same IP, new UA
        $this->assertFalse($store->has('uid'));
    }

    public function test_fingerprint_ip_only_rejects_ip_change(): void
    {
        $config  = $this->fpConfig([CookieSessionConfig::FP_IP]);
        $payload = $this->issueWith($config, 'Mozilla/5.0', '203.0.113.7');

        $store = $this->reloadAs($config, $payload, 'Mozilla/5.0', '10.9.9.9');
        $this->assertFalse($store->has('uid'));
    }

    // ── Compression ──────────────────────────────────────────────────────────────

    public function test_large_payload_is_compressed_and_round_trips(): void
    {
        $config = new CookieSessionConfig(encrypter: $this->encrypter(), compressThreshold: 64);

        $big = str_repeat('lorem ipsum dolor ', 200); // highly compressible

        $store = $this->store($config);
        $store->start(null);
        $store->put('blob', $big);
        $store->save();
        $payload = (string) $store->handler()->outgoing();

        $back = new CookieSessionHandler($config);
        $back->prime($payload);
        $store2 = new Store('hkm_session', $back);
        $store2->start(null);

        $this->assertSame($big, $store2->get('blob'));
    }

    // ── Binary safety (php serialization) ────────────────────────────────────────

    public function test_php_serialized_binary_payload_round_trips(): void
    {
        $config = new CookieSessionConfig(encrypter: $this->encrypter(), compressThreshold: 0);

        // 'php' serialization can emit non-UTF-8 bytes; the envelope must survive.
        $store = new Store('hkm_session', new CookieSessionHandler($config), 'php');
        $store->start(null);
        $store->put('blob', "\x00\x01\xff\xfe binary \x80");
        $store->save();
        $payload = (string) $store->handler()->outgoing();
        $this->assertNotSame('', $payload);

        $back   = new CookieSessionHandler($config);
        $back->prime($payload);
        $store2 = new Store('hkm_session', $back, 'php');
        $store2->start(null);

        $this->assertSame("\x00\x01\xff\xfe binary \x80", $store2->get('blob'));
    }

    // ── Size ceiling ──────────────────────────────────────────────────────────────

    public function test_oversized_cookie_is_dropped(): void
    {
        $config = new CookieSessionConfig(
            encrypter:         $this->encrypter(),
            compressThreshold: 0,   // disable compression so it really is big
            maxBytes:          256,
        );

        $store = $this->store($config);
        $store->start(null);
        // base64 of random bytes: valid UTF-8 (survives JSON) AND incompressible.
        $store->put('blob', base64_encode(random_bytes(2000)));
        $store->save();

        $this->assertNull($store->handler()->outgoing(), 'oversized cookie must be dropped');
    }

    // ── Strict mode ──────────────────────────────────────────────────────────────

    public function test_require_authentication_throws_when_unprotected(): void
    {
        $this->expectException(KernelException::class);

        new CookieSessionHandler(new CookieSessionConfig(requireAuthentication: true));
    }

    public function test_require_authentication_allows_signing_key(): void
    {
        $handler = new CookieSessionHandler(
            new CookieSessionConfig(signingKey: 'k', requireAuthentication: true),
        );

        $this->assertInstanceOf(CookieSessionHandler::class, $handler);
    }

    public function test_require_encryption_throws_for_signed_only(): void
    {
        $this->expectException(KernelException::class);

        // Signed (key present) but NOT encrypted → confidentiality not guaranteed.
        new CookieSessionHandler(
            new CookieSessionConfig(signingKey: 'k', requireEncryption: true),
        );
    }

    public function test_require_encryption_allows_an_encrypter(): void
    {
        $handler = new CookieSessionHandler(
            new CookieSessionConfig(encrypter: $this->encrypter(), requireEncryption: true),
        );

        $this->assertInstanceOf(CookieSessionHandler::class, $handler);
    }

    /** Decrypt an outgoing cookie and read one envelope field (test helper). */
    private function envelopeField(EncryptionPort $enc, string $cookie, string $field): mixed
    {
        $json    = $enc->decryptString($cookie);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? ($decoded[$field] ?? null) : null;
    }
}
