<?php

declare(strict_types=1);

namespace Plugins\HttpClient\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;

/**
 * Immutable fluent builder for a single outbound request (GDA rewrite of the
 * 0.3 Guzzle-backed PendingRequest, minus the Laravel test-fake/middleware
 * machinery). Every with* method returns a NEW instance — the builder is safe
 * to share and reuse as a preconfigured template.
 *
 * Execution is delegated to CurlHttpClient so the transport lives in one place.
 */
final class PendingRequest
{
    /**
     * @param array<string, string> $headers
     */
    /**
     * @param array<string, string> $headers
     * @param list<array{name: string, contents: string, filename: ?string}> $files
     */
    private function __construct(
        private readonly CurlHttpClient $client,
        private readonly string $baseUrl = '',
        private readonly array $headers = [],
        private readonly string $bodyFormat = 'json',
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly int $retry = 0,
        private readonly array $files = [],
    ) {}

    public static function for(CurlHttpClient $client): self
    {
        return new self($client);
    }

    public function baseUrl(string $url): self
    {
        return $this->with(['baseUrl' => rtrim($url, '/')]);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return $this->with(['headers' => $this->headers + array_change_key_case($headers, CASE_LOWER)]);
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withHeaders([$name => $value]);
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeader('Authorization', trim($type . ' ' . $token));
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
    }

    public function asJson(): self
    {
        return $this->with(['bodyFormat' => 'json']);
    }

    public function asForm(): self
    {
        return $this->with(['bodyFormat' => 'form']);
    }

    /** Switch to multipart/form-data — required before attach()ing files. */
    public function asMultipart(): self
    {
        return $this->with(['bodyFormat' => 'multipart']);
    }

    /**
     * Attach an in-memory file to a multipart request. Implies asMultipart().
     */
    public function attach(string $name, string $contents, ?string $filename = null): self
    {
        $files = $this->files;
        $files[] = ['name' => $name, 'contents' => $contents, 'filename' => $filename];
        return $this->with(['bodyFormat' => 'multipart', 'files' => $files]);
    }

    public function acceptJson(): self
    {
        return $this->withHeader('Accept', 'application/json');
    }

    public function timeout(int $seconds): self
    {
        return $this->with(['timeout' => max(1, $seconds)]);
    }

    public function connectTimeout(int $seconds): self
    {
        return $this->with(['connectTimeout' => max(1, $seconds)]);
    }

    public function retry(int $times): self
    {
        return $this->with(['retry' => max(0, $times)]);
    }

    // ── Verbs ────────────────────────────────────────────────────────────────

    /** @param array<string, scalar> $query */
    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->send('GET', $url, $query === [] ? [] : ['query' => $query]);
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->send('POST', $url, $this->payload($data));
    }

    /** @param array<string, mixed> $data */
    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->send('PUT', $url, $this->payload($data));
    }

    /** @param array<string, mixed> $data */
    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->send('PATCH', $url, $this->payload($data));
    }

    /** @param array<string, mixed> $data */
    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->send('DELETE', $url, $this->payload($data));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $method, string $url, array $options = []): HttpClientResponse
    {
        $options['headers']         = $this->headers + ($options['headers'] ?? []);
        $options['timeout']         = $this->timeout;
        $options['connect_timeout'] = $this->connectTimeout;
        $options['retry']           = $this->retry;

        return $this->client->request($method, $this->resolveUrl($url), $options);
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        if ($this->bodyFormat === 'multipart' || $this->files !== []) {
            return ['multipart' => ['fields' => $data, 'files' => $this->files]];
        }
        if ($data === []) {
            return [];
        }
        return $this->bodyFormat === 'form' ? ['form' => $data] : ['json' => $data];
    }

    private function resolveUrl(string $url): string
    {
        if ($this->baseUrl === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        return $this->baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        return new self(
            client:         $this->client,
            baseUrl:        $changes['baseUrl']        ?? $this->baseUrl,
            headers:        $changes['headers']        ?? $this->headers,
            bodyFormat:     $changes['bodyFormat']     ?? $this->bodyFormat,
            timeout:        $changes['timeout']        ?? $this->timeout,
            connectTimeout: $changes['connectTimeout'] ?? $this->connectTimeout,
            retry:          $changes['retry']          ?? $this->retry,
            files:          $changes['files']          ?? $this->files,
        );
    }
}
