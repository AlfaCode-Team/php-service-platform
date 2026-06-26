<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Tiny helper to build a kernel Request carrying body input for DTO tests.
 */
final class FakeRequest
{
    /** @param array<string,mixed> $body */
    public static function with(array $body, string $method = 'POST', string $path = '/test'): Request
    {
        return Request::build(method: $method, path: $path, body: $body);
    }
}
