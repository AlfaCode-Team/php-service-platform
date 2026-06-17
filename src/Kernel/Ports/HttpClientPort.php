<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * HttpClientPort — the ONLY way a Gateway makes outbound HTTP calls.
 *
 * The kernel defines this interface; a plugin/project provides the adapter
 * (e.g. the cURL-based client in plugins/HttpClient). Keeping outbound HTTP
 * behind a port means Gateways stay testable (swap in a fake client) and the
 * vendor/transport detail never leaks past the gateway layer.
 *
 * @phpstan-type ClientOptions array{
 *     headers?: array<string, string>,
 *     query?: array<string, scalar>,
 *     json?: mixed,
 *     form?: array<string, scalar>,
 *     body?: string,
 *     timeout?: int,
 *     connect_timeout?: int,
 *     retry?: int
 * }
 */
interface HttpClientPort
{
    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): HttpClientResponse;

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
}
