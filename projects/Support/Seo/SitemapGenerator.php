<?php

declare(strict_types=1);

namespace Project\Support\Seo;

use Plugins\SiteSEO\Sitemap\SitemapBuilder;

/**
 * Project-layer convenience wrapper around the SiteSEO sitemap toolkit.
 *
 * The plugin's {@see Sitemap} builder is powerful but low-level (callback-driven
 * `build()`s, manual `loc()`/`priority()` chaining). This wrapper gives the
 * project a flat, declarative surface:
 *
 *   $file = SitemapGenerator::for('https://shop.example.com')
 *       ->fromRoutes(RouteCatalog::fromManifest())   // every public page
 *       ->add('/blog/hello-world', priority: '0.6', changeFreq: 'monthly')
 *       ->save(Paths::public('.'));                  // writes <dir>/sitemap.xml
 *
 * It drives the toolkit's {@see SitemapBuilder} directly (a single `<urlset>`),
 * NOT the multi-file `Sitemap` index — the index pulls in an XSL stylesheet that
 * needs a `site_url()` global, while the builder is dependency-free and writes
 * one self-contained file. That covers up to 30 000 URLs (the builder's cap); a
 * site large enough to need a sitemap *index* should use the plugin's `Sitemap`
 * class directly and provide a `site_url()` helper.
 *
 * Pure plumbing — no DI required: the SiteSEO toolkit classes autoload directly,
 * so a project can build a sitemap without loading the SEO module's services.
 */
final class SitemapGenerator
{
    /** @var list<array{path: string, priority: ?string, changeFreq: ?string, lastMod: ?string}> */
    private array $entries = [];

    /**
     * @param string $baseUrl Absolute site root, e.g. "https://example.com" (no trailing slash).
     */
    public function __construct(
        private readonly string $baseUrl,
        private string $sitemapName = 'pages',
        private string $indexName = 'sitemap.xml',
    ) {
    }

    public static function for(string $baseUrl): self
    {
        return new self(rtrim($baseUrl, '/'));
    }

    /** Name of the single child sitemap registered on the index (default "pages"). */
    public function named(string $sitemapName): self
    {
        $this->sitemapName = $sitemapName;

        return $this;
    }

    /** Filename of the index written to disk (default "sitemap.xml"). */
    public function indexedAs(string $indexName): self
    {
        $this->indexName = $indexName;

        return $this;
    }

    /**
     * Add every public, static page discovered in the route manifest.
     */
    public function fromRoutes(RouteCatalog $catalog, string $priority = '0.8', string $changeFreq = 'weekly'): self
    {
        foreach ($catalog->publicPaths() as $path) {
            $this->add($path, priority: $priority, changeFreq: $changeFreq);
        }

        return $this;
    }

    /**
     * Add a single URL (relative path or absolute URL). Dynamic pages — blog
     * posts, products — are added here from your own data.
     */
    public function add(
        string $path,
        ?string $priority = null,
        ?string $changeFreq = null,
        string|\DateTimeInterface|null $lastMod = null,
    ): self {
        $this->entries[] = [
            'path'       => $this->normalize($path),
            'priority'   => $priority,
            'changeFreq' => $changeFreq,
            'lastMod'    => $lastMod instanceof \DateTimeInterface
                ? $lastMod->format('Y-m-d')
                : $lastMod,
        ];

        return $this;
    }

    /**
     * @param iterable<string> $paths
     */
    public function addMany(iterable $paths, ?string $priority = null, ?string $changeFreq = null): self
    {
        foreach ($paths as $path) {
            $this->add($path, priority: $priority, changeFreq: $changeFreq);
        }

        return $this;
    }

    /** Number of URLs queued so far. */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Build the sitemap and write it to <directory>/<indexName> (default
     * sitemap.xml). Returns the full file path written.
     */
    public function save(string $directory): string
    {
        $file = rtrim($directory, '/') . '/' . $this->indexName;

        $this->buildBuilder()->saveTo($file);

        return $file;
    }

    /**
     * Build the sitemap XML in memory and return it as a string — ideal for
     * serving straight from a controller without touching disk.
     */
    public function toXml(): string
    {
        $builder = $this->buildBuilder();
        $builder->append();                 // flush the last queued <url>

        $xml = $builder->getDoc()->asXML();

        return $xml === false ? '' : $xml;
    }

    /**
     * Assemble a single {@see SitemapBuilder} holding every queued entry.
     */
    private function buildBuilder(): SitemapBuilder
    {
        $builder = new SitemapBuilder($this->baseUrl, ['name' => $this->sitemapName]);

        foreach ($this->entries as $entry) {
            $builder->loc($entry['path']);

            if ($entry['changeFreq'] !== null) {
                $builder->changeFreq($entry['changeFreq']);
            }
            if ($entry['priority'] !== null) {
                $builder->priority($entry['priority']);
            }
            if ($entry['lastMod'] !== null) {
                $builder->lastMod($entry['lastMod']);
            }
        }

        return $builder;
    }

    /** Make a path root-relative (the builder prefixes the domain itself). */
    private function normalize(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // Strip the base URL back to a path so the builder can re-prefix it.
            $path = str_replace($this->baseUrl, '', $path);
        }

        return '/' . ltrim($path, '/');
    }
}
