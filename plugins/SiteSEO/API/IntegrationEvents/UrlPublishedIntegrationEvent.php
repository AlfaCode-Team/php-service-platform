<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Emitted whenever a public URL is created or changed (a post published, a
 * product saved, …). The SEO module subscribes to it and enqueues an IndexNow
 * submission, so indexing happens on publish without any explicit call.
 *
 * Integration event = primitives only (it crosses module boundaries and may be
 * serialized onto a queue). Dispatch it AFTER the save transaction commits.
 *
 *   $eventBus->dispatch(new UrlPublishedIntegrationEvent($url, 'blog'));
 */
final class UrlPublishedIntegrationEvent implements IntegrationEventContract
{
    public function __construct(
        public readonly string $url,
        public readonly string $type = 'page',
    ) {
    }

    public function name(): string
    {
        return 'seo.url_published';
    }

    public function version(): string
    {
        return '1.0';
    }

    /** @return array{url: string, type: string} */
    public function payload(): array
    {
        return ['url' => $this->url, 'type' => $this->type];
    }
}
