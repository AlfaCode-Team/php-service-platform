<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request as KernelRequest;

/**
 * Stateful request wrapper the OAuth providers expect.
 *
 * Adapts the kernel's immutable, stateless Request to the small surface the
 * ported Socialite providers use (input() + session()). Build one per
 * incoming HTTP request from the kernel Request.
 */
class Request
{
    /** @param array<string,mixed> $input */
    public function __construct(
        private array $input = [],
        private ?Session $session = null,
    ) {
        $this->session ??= new Session();
    }

    public static function fromKernel(KernelRequest $request): self
    {
        return new self(array_merge($request->queryAll(), $request->all()));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function session(): Session
    {
        return $this->session;
    }
}
