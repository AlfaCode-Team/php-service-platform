<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Cookie;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\Crypto\Infrastructure\AesEncrypter;

#[CoversClass(CookieJar::class)]
final class CookieJarTest extends TestCase
{
    /** Queue a plain cookie and flush it onto a response. */
    public function test_queues_a_cookie_and_applies_it_to_the_response(): void
    {
        $jar = new CookieJar();              // no encrypter
        $jar->queue('theme', 'dark', maxAge: 3600);

        $this->assertTrue($jar->hasQueued('theme'));

        $response = $jar->applyTo(Response::json(['ok' => true]));
        $line = $this->cookieLine($response, 'theme');

        $this->assertStringContainsString('theme=dark', $line);
        $this->assertStringContainsStringIgnoringCase('httponly', $line);
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $line);
    }

    /** forget() emits an expired clearing cookie. */
    public function test_forget_clears_the_cookie(): void
    {
        $jar = new CookieJar();
        $jar->forget('session_id');

        $response = $jar->applyTo(Response::noContent());
        $line = $this->cookieLine($response, 'session_id');

        // A "deleted" value with Max-Age=0 and a past expiry marks it for deletion.
        $this->assertStringContainsString('session_id=deleted', $line);
        $this->assertStringContainsString('Max-Age=0', $line);
    }

    /** With an EncryptionPort the stored value is ciphertext, not plaintext. */
    public function test_encrypts_value_on_flush_when_encrypter_present(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $jar = new CookieJar(encrypter: $enc);

        $jar->queue('token', 'super-secret', maxAge: 60);
        $response = $jar->applyTo(Response::json([]));
        $line = $this->cookieLine($response, 'token');

        $this->assertStringNotContainsString('super-secret', $line);
    }

    /** read() decrypts an incoming cookie that was written encrypted (round-trip). */
    public function test_read_round_trips_an_encrypted_cookie(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $jar = new CookieJar(encrypter: $enc);

        // Simulate what the browser would send back: the encrypted value.
        $cipher  = $enc->encryptString('hello-world');
        $request = Request::build('GET', '/', cookies: ['greeting' => $cipher]);

        $this->assertSame('hello-world', $jar->read($request, 'greeting'));
    }

    /** A tampered ciphertext decrypts to null rather than throwing. */
    public function test_read_returns_null_for_tampered_cookie(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $jar = new CookieJar(encrypter: $enc);

        $request = Request::build('GET', '/', cookies: ['greeting' => 'not-valid-cipher']);

        $this->assertNull($jar->read($request, 'greeting'));
    }

    /** Exempt cookies are stored and read as plaintext even with an encrypter. */
    public function test_exempt_cookie_is_not_encrypted(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $jar = new CookieJar(encrypter: $enc, exempt: ['plain']);

        $jar->queue('plain', 'visible', maxAge: 60);
        $response = $jar->applyTo(Response::json([]));

        $this->assertStringContainsString('plain=visible', $this->cookieLine($response, 'plain'));

        // ...and reading it back returns the raw value untouched.
        $request = Request::build('GET', '/', cookies: ['plain' => 'visible']);
        $this->assertSame('visible', $jar->read($request, 'plain'));
    }

    /** queue() falls back to the configured defaults when attributes are omitted. */
    public function test_uses_configured_defaults_when_attributes_omitted(): void
    {
        $jar = new CookieJar(defaults: [
            'lifetime'  => 10,      // minutes
            'path'      => '/app',
            'secure'    => false,
            'http_only' => true,
            'same_site' => 'Strict',
        ]);

        $jar->queue('pref', 'on');   // no attributes — pull from defaults
        $line = $this->cookieLine($jar->applyTo(Response::json([])), 'pref');

        $this->assertStringContainsString('pref=on', $line);
        $this->assertStringContainsString('path=/app', $line);
        $this->assertStringContainsStringIgnoringCase('samesite=strict', $line);
        $this->assertStringNotContainsStringIgnoringCase('secure', $line);
        $this->assertStringContainsString('Max-Age=600', $line);   // 10 min → 600s
    }

    /** The cookie() helper produces a spread-ready attribute array. */
    public function test_cookie_helper_builds_spreadable_attributes(): void
    {
        $spec = cookie('seen', '1', minutes: 5, overrides: ['path' => '/x']);

        $this->assertSame('seen', $spec['name']);
        $this->assertSame('1', $spec['value']);
        $this->assertSame(300, $spec['maxAge']);   // 5 min → 300s
        $this->assertSame('/x', $spec['path']);

        // Spread straight into the jar.
        $jar = new CookieJar();
        $jar->queue(...cookie('via', 'helper', minutes: 1));

        $this->assertStringContainsString('via=helper', $this->cookieLine($jar->applyTo(Response::json([])), 'via'));
    }

    /** cookie_config() returns env-driven values from config/cookie.php. */
    public function test_cookie_config_reads_keys(): void
    {
        $this->assertIsArray(cookie_config());
        $this->assertArrayHasKey('same_site', cookie_config());
        $this->assertSame('fallback', cookie_config('does_not_exist', 'fallback'));
    }

    /** Find the Set-Cookie line for a given cookie name. */
    private function cookieLine(Response $response, string $name): string
    {
        foreach ($response->cookies() as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }

        $this->fail("No Set-Cookie line found for [{$name}].");
    }
}
