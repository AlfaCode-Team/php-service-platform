<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Application\Services;

use Plugins\SiteSEO\API\Contracts\SeoServiceContract;
use Plugins\SiteSEO\Exceptions\SeoException;
use Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway;
use Plugins\SiteSEO\Interfaces\SchemaInterface;
use Plugins\SiteSEO\OpenGraph;
use Plugins\SiteSEO\RobotsTxtEditor;
use Plugins\SiteSEO\Schema;
use Plugins\SiteSEO\Sitemap;
use Plugins\SiteSEO\Type;

/**
 * Application service for the SEO module — the concrete behind
 * {@see SeoServiceContract}.
 *
 * Pure orchestration: it hands back the toolkit's builder objects (Open Graph,
 * schema, sitemap, robots) and delegates all outbound search-engine traffic to
 * {@see SearchEngineGateway} so no transport detail lives in the service.
 */
final class SeoService implements SeoServiceContract
{
    /** Map of supported Open Graph types to their OpenGraph factory method. */
    private const OG_TYPES = [
        'website'      => 'website',
        'article'      => 'article',
        'book'         => 'book',
        'profile'      => 'profile',
        'movie'        => 'movie',
        'tvShow'       => 'tvShow',
        'episode'      => 'episode',
        'other'        => 'other',
        'album'        => 'album',
        'song'         => 'song',
        'playlist'     => 'playlist',
        'radioStation' => 'radioStation',
    ];

    public function __construct(
        private readonly SearchEngineGateway $engines,
    ) {
    }

    public function openGraph(string $type = 'website', ?string $title = null): Type
    {
        $factory = self::OG_TYPES[$type] ?? null;

        if ($factory === null) {
            throw new SeoException(sprintf(
                'Unsupported Open Graph type [%s]. Supported: %s.',
                $type,
                implode(', ', array_keys(self::OG_TYPES)),
            ));
        }

        /** @var Type $object */
        $object = OpenGraph::{$factory}($title);

        return $object;
    }

    public function schema(SchemaInterface ...$things): Schema
    {
        return new Schema(...$things);
    }

    public function sitemap(string $domain, array $options = []): Sitemap
    {
        return new Sitemap($domain, $options === [] ? null : $options);
    }

    public function robots(string $encoding = RobotsTxtEditor::DEFAULT_ENCODING): RobotsTxtEditor
    {
        return new RobotsTxtEditor($encoding);
    }

    public function pingSitemap(string $sitemapUrl, array $engines = []): void
    {
        $this->engines->pingSitemap($sitemapUrl, $engines);
    }

    public function submitUrls(string $host, array $keys, array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        return $this->engines->submitIndexNow($host, $keys, $urls);
    }

    /** IndexNow protocol cap: at most 10 000 URLs per request. */
    private const INDEXNOW_BATCH = 10000;

    /** Default endpoint — propagates to all participating engines. */
    private const DEFAULT_ENDPOINTS = ['https://api.indexnow.org/indexnow'];

    public function indexNow(
        string $host,
        string $key,
        string $keyLocation,
        iterable $urls,
        array $endpoints = [],
        bool $dryRun = false,
    ): array {
        $endpoints = $endpoints !== [] ? array_values($endpoints) : self::DEFAULT_ENDPOINTS;

        $submitted = 0;
        $batchNo   = 0;
        $results   = [];
        $buffer    = [];

        // Flush one batch (≤10k) to every endpoint, then clear the buffer.
        $flush = function () use (&$buffer, &$submitted, &$batchNo, &$results, $endpoints, $host, $key, $keyLocation, $dryRun): void {
            if ($buffer === []) {
                return;
            }

            $batchNo++;
            $count = count($buffer);

            foreach ($endpoints as $endpoint) {
                $ok = $dryRun
                    ? true
                    : $this->engines->indexNowBatch($endpoint, $host, $key, $keyLocation, $buffer);

                $results[] = [
                    'endpoint' => $endpoint,
                    'batch'    => $batchNo,
                    'count'    => $count,
                    'ok'       => $ok,
                ];
            }

            $submitted += $count;
            $buffer = [];
        };

        // Consume the (possibly lazy) batches one chunk at a time.
        foreach ($this->indexNowChunks($urls) as $chunk) {
            $buffer = $chunk;
            $flush();
        }

        return [
            'submitted' => $submitted,
            'batches'   => $batchNo,
            'endpoints' => $endpoints,
            'dryRun'    => $dryRun,
            'results'   => $results,
        ];
    }

    public function indexNowChunks(iterable $urls): \Generator
    {
        $buffer = [];

        // Accept plain strings or {loc|url} arrays; never materialise the whole set.
        foreach ($urls as $url) {
            if (is_array($url)) {
                $url = (string) ($url['loc'] ?? $url['url'] ?? '');
            }
            if ($url === '') {
                continue;
            }

            $buffer[] = $url;

            if (count($buffer) >= self::INDEXNOW_BATCH) {
                yield $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer;
        }
    }
}
