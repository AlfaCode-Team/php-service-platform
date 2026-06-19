<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\API\Contracts;

use Plugins\SiteSEO\Interfaces\SchemaInterface;
use Plugins\SiteSEO\RobotsTxtEditor;
use Plugins\SiteSEO\Schema;
use Plugins\SiteSEO\Sitemap;
use Plugins\SiteSEO\Type;

/**
 * Published contract for the SEO module (`seo.management`).
 *
 * The ONLY surface other modules and project controllers may depend on. It
 * exposes the SEO toolkit (Open Graph / Twitter meta, JSON-LD schema, sitemap
 * generation, robots.txt editing) plus search-engine submission, which goes
 * through {@see \Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway}
 * (HttpClientPort) rather than raw cURL.
 */
interface SeoServiceContract
{
    /**
     * Build an Open Graph / Twitter meta-tag document of a given type.
     *
     * @param string      $type  One of: website, article, book, profile,
     *                           movie, tvShow, episode, song, album,
     *                           playlist, radioStation.
     * @param string|null $title Optional document title.
     */
    public function openGraph(string $type = 'website', ?string $title = null): Type;

    /**
     * Build a JSON-LD schema.org graph from one or more schema things.
     */
    public function schema(SchemaInterface ...$things): Schema;

    /**
     * Start a sitemap index builder for a domain.
     *
     * @param array<string, mixed> $options
     */
    public function sitemap(string $domain, array $options = []): Sitemap;

    /**
     * Open the robots.txt editor.
     */
    public function robots(string $encoding = RobotsTxtEditor::DEFAULT_ENCODING): RobotsTxtEditor;

    /**
     * Notify search engines that a sitemap was updated.
     *
     * @param list<string> $engines Extra engine base URLs to ping.
     */
    public function pingSitemap(string $sitemapUrl, array $engines = []): void;

    /**
     * Submit URLs to the IndexNow protocol for instant indexing.
     *
     * @param array<string, string> $keys Engine host => API key.
     * @param list<string>          $urls URLs to submit.
     * @return array<string, bool>        Engine host => accepted.
     *
     * @deprecated Prefer {@see indexNow()} — it adds keyLocation verification,
     *             auto-batching and lazy streaming for large URL sets.
     */
    public function submitUrls(string $host, array $keys, array $urls): array;

    /**
     * Submit URLs to IndexNow with ownership verification + auto-batching.
     *
     * Enterprise-grade: $urls may be ANY iterable (pass a generator to stream
     * millions lazily), and it is chunked into batches of 10 000 (the protocol
     * cap) before each batch is POSTed. `keyLocation` is the public URL of the
     * hosted key file so engines can verify the domain. With $dryRun the batch
     * plan is computed WITHOUT any network call (safe for previews / tests).
     *
     * @param iterable<string>  $urls      Absolute URLs on $host.
     * @param list<string>      $endpoints IndexNow endpoints (default: api.indexnow.org).
     * @return array{submitted: int, batches: int, endpoints: list<string>, dryRun: bool, results: list<array{endpoint: string, batch: int, count: int, ok: bool}>}
     */
    public function indexNow(
        string $host,
        string $key,
        string $keyLocation,
        iterable $urls,
        array $endpoints = [],
        bool $dryRun = false,
    ): array;

    /**
     * Lazily split a URL stream into IndexNow-sized batches (≤10 000 each).
     *
     * Use this to enqueue ONE background job per batch: the generator never holds
     * more than one batch in memory, so a million-URL catalogue can be dispatched
     * to the queue at flat memory.
     *
     * @param iterable<string> $urls
     * @return \Generator<int, list<string>>
     */
    public function indexNowChunks(iterable $urls): \Generator;
}
