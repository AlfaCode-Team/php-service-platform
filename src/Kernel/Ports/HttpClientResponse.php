<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * HttpClientResponse — immutable result of an outbound HTTP call.
 *
 * Lives in the Ports namespace (not the adapter) so Gateways type-hint the
 * kernel contract, never a vendor/plugin class. Header names are lower-cased
 * for case-insensitive lookup, matching the kernel Request convention.
 */
final class HttpClientResponse
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $body,
        array $headers = [],
    ) {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Decode the JSON body. Returns null when the body is not valid JSON.
     *
     * @return mixed
     */
    public function json()
    {
        $decoded = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * Throw a GatewayException on a 4xx/5xx response so callers can treat a
     * failed outbound call like any other gateway failure.
     */
    public function throw(string $layer = 'gateway.http_client'): self
    {
        if ($this->failed()) {
            throw new GatewayException(
                "Outbound HTTP request failed with status {$this->status}.",
                layer:   $layer,
                context: ['status' => $this->status, 'body' => substr($this->body, 0, 500)],
            );
        }
        return $this;
    }
}
