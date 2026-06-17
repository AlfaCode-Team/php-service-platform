<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * Lightweight redirect value object returned by Provider::redirect().
 *
 * Controllers convert it to a kernel Response via toResponse() so the GDA
 * HTTP pipeline returns a proper 302 without the plugin depending on the
 * kernel's response shape internally.
 */
class RedirectResponse
{
    public function __construct(
        private readonly string $targetUrl,
        private readonly int $status = 302,
    ) {
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function toResponse(): Response
    {
        return Response::redirect($this->targetUrl, $this->status);
    }
}
