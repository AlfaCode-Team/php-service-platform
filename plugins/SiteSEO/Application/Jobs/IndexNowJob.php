<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway;

/**
 * Background job that submits ONE IndexNow batch (≤10 000 URLs).
 *
 * Large indexing runs must not block a web request, so the dispatcher streams
 * the URL set, chunks it into batches, and enqueues one IndexNowJob per batch
 * (queue "indexing"). Each job POSTs its batch through SearchEngineGateway
 * (HttpClientPort). A transport failure throws → the worker's retry strategy
 * (exponential, from module.json) re-runs it; after max attempts failed() runs.
 *
 * Payload shape (set by the dispatcher):
 *   { host, key, keyLocation, urls: string[], endpoints?: string[] }
 *
 * The job is resolved from the SEO module's container (Provider::register binds
 * it with the gateway), so it autowires with full GDA scope.
 */
final class IndexNowJob implements JobContract
{
    public function __construct(
        private readonly SearchEngineGateway $engines,
    ) {
    }

    public function handle(JobPayload $payload): JobResult
    {
        $data = $payload->data();

        $host        = (string) ($data['host'] ?? '');
        $key         = (string) ($data['key'] ?? '');
        $keyLocation = (string) ($data['keyLocation'] ?? '');
        /** @var list<string> $urls */
        $urls        = array_values(array_filter((array) ($data['urls'] ?? []), 'is_string'));
        /** @var list<string> $endpoints */
        $endpoints   = (array) ($data['endpoints'] ?? ['https://api.indexnow.org/indexnow']);

        if ($urls === [] || $host === '' || $key === '') {
            return JobResult::skipped('IndexNow job missing host/key/urls — nothing to submit.');
        }

        // Dry run: exercise the full job/worker path without any network call.
        if (!empty($data['dryRun'])) {
            return JobResult::success(['submitted' => count($urls), 'dryRun' => true]);
        }

        $accepted = 0;
        foreach ($endpoints as $endpoint) {
            // Throws GatewayException on transport failure → triggers retry.
            if ($this->engines->indexNowBatch($endpoint, $host, $key, $keyLocation, $urls)) {
                $accepted++;
            }
        }

        return JobResult::success([
            'submitted'         => count($urls),
            'endpoints_total'   => count($endpoints),
            'endpoints_accepted' => $accepted,
        ]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        // Retries exhausted. In a real app: record the failed batch for a manual
        // re-submit / alert. Kept side-effect free here (the worker logs the
        // throwable via the error pipeline).
    }
}
