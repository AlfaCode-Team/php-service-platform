<?php

declare(strict_types=1);

namespace Project\Support\Seo;

/**
 * The IndexNow API key + where it is published.
 *
 * IndexNow proves you own a domain by having you host a key file that an engine
 * fetches before trusting your submissions. The key is an 8–128 char token of
 * `[a-zA-Z0-9-]`; the file's body is exactly the key.
 *
 * Two ways to publish it:
 *   1. At the root as `{key}.txt`              → keyLocation = https://host/{key}.txt
 *   2. At any URL you control (a route)        → keyLocation = that URL
 *
 * Option 2 is friendlier in a framework (no dynamic root file), so this object
 * carries an explicit publish path and builds the matching keyLocation.
 *
 *   $k = IndexNowKey::fromString(env('INDEXNOW_KEY'))->publishedAt('/seo/indexnow.txt');
 *   $k->value();                       // the token
 *   $k->fileContents();                // what the served file must contain
 *   $k->location('https://shop.example.com');   // keyLocation for the submission
 */
final class IndexNowKey
{
    private function __construct(
        private readonly string $key,
        private readonly string $path,
    ) {
    }

    /** Generate a fresh 32-hex-char key (publish it once, then keep it stable). */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)), '');
    }

    public static function fromString(string $key): self
    {
        if (!preg_match('/^[a-zA-Z0-9\-]{8,128}$/', $key)) {
            throw new \InvalidArgumentException(
                'IndexNow key must be 8–128 chars of [a-zA-Z0-9-].'
            );
        }

        return new self($key, '');
    }

    /**
     * Set the path the key file is served from (e.g. "/seo/indexnow.txt").
     * When empty, the canonical `{key}.txt` at the site root is used.
     */
    public function publishedAt(string $path): self
    {
        return new self($this->key, $path);
    }

    public function value(): string
    {
        return $this->key;
    }

    /** The body the published file MUST return (exactly the key). */
    public function fileContents(): string
    {
        return $this->key;
    }

    /** The path the key is served from, relative to the host. */
    public function path(): string
    {
        return $this->path !== '' ? '/' . ltrim($this->path, '/') : '/' . $this->key . '.txt';
    }

    /** The absolute keyLocation URL for the submission payload. */
    public function location(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . $this->path();
    }
}
