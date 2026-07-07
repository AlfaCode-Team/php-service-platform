<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * PendingRequestContract — the fluent outbound-request builder surface a
 * Gateway may depend on.
 *
 * The kernel defines the contract; a plugin/project provides the immutable
 * implementation (e.g. plugins/HttpClient PendingRequest). Every builder
 * method returns a NEW instance, so a configured builder is a safe reusable
 * template. Reachable from HttpClientPort::pending() so the transport detail
 * never leaks past the gateway layer.
 */
interface PendingRequestContract
{
    public function baseUrl(string $url): static;

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static;

    public function withHeader(string $name, string $value): static;

    public function withToken(string $token, string $type = 'Bearer'): static;

    public function withBasicAuth(string $username, string $password): static;

    public function asJson(): static;

    public function asForm(): static;

    /** Switch to multipart/form-data — required before attach()ing files. */
    public function asMultipart(): static;

    /** Attach an in-memory file to a multipart request. Implies asMultipart(). */
    public function attach(string $name, string $contents, ?string $filename = null): static;

    public function acceptJson(): static;

    public function timeout(int $seconds): static;

    public function connectTimeout(int $seconds): static;

    public function retry(int $times): static;

    /**
     * Override which HTTP verbs are eligible for auto-retry. By default only
     * idempotent methods retry; widen this ONLY when the endpoint is safe to
     * re-invoke (e.g. an idempotency-key-protected POST).
     *
     * @param list<string> $methods
     */
    public function retryMethods(array $methods): static;

    /** @param array<string, scalar> $query */
    public function get(string $url, array $query = []): HttpClientResponse;

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = []): HttpClientResponse;

    /** @param array<string, mixed> $data */
    public function put(string $url, array $data = []): HttpClientResponse;

    /** @param array<string, mixed> $data */
    public function patch(string $url, array $data = []): HttpClientResponse;

    /** @param array<string, mixed> $data */
    public function delete(string $url, array $data = []): HttpClientResponse;

    /** @param array<string, mixed> $options */
    public function send(string $method, string $url, array $options = []): HttpClientResponse;
}
