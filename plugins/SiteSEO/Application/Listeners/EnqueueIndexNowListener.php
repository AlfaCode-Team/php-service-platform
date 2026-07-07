<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Application\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * On `seo.url_published`, enqueue an IndexNow submission for that URL.
 *
 * This is the "index on publish" hook: a save flow dispatches
 * {@see \Plugins\SiteSEO\API\IntegrationEvents\UrlPublishedIntegrationEvent}
 * after commit, and this listener pushes a `seo.indexnow` job (queue "indexing")
 * so the worker submits it in the background — the request never waits on the
 * network.
 *
 * The EventBus resolves listeners from the CoreContainer, so the project binds
 * this class there with a QueuePort (see psp-shop bootstrap). Config is read
 * from env: INDEXNOW_KEY (required to submit) and INDEXNOW_LIVE (when truthy,
 * really submit; otherwise the enqueued job runs as a dry run).
 */
final class EnqueueIndexNowListener implements EventListenerContract
{
    public function __construct(
        private readonly QueuePort $queue,
        private readonly string $queueName = 'indexing',
        private readonly string $keyPath = '/seo/indexnow.txt',
    ) {
    }

    public function handle(IntegrationEventContract $event): void
    {
        $url = (string) ($event->payload()['url'] ?? '');
        if ($url === '') {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $key  = (string) (env('INDEXNOW_KEY') ?? '');
        if ($host === '' || $key === '') {
            return;   // not configured — nothing to do
        }

        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: 'https');
        $base   = $scheme . '://' . $host;

        $this->queue->push('seo.indexnow', [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $base . $this->keyPath,
            'urls'        => [$url],
            'dryRun'      => !filter_var(env('INDEXNOW_LIVE') ?? false, FILTER_VALIDATE_BOOL),
        ], $this->queueName);
    }
}
