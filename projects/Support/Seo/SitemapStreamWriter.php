<?php

declare(strict_types=1);

namespace Project\Support\Seo;

/**
 * Enterprise-grade, streaming sitemap writer for HUGE URL sets.
 *
 * Unlike {@see SitemapGenerator} (which buffers every URL in an array and builds
 * a whole SimpleXMLElement DOM — fine for the few hundred routes a project has),
 * this writer is built for millions of dynamic links:
 *
 *   - URLs are consumed from any `iterable` — pass a GENERATOR backed by a
 *     buffered DB cursor so rows are never all in memory at once.
 *   - XML is written straight to a file handle with fwrite()/gzwrite(); there is
 *     NO DOM. Memory stays flat (O(1)) no matter how many URLs you write.
 *   - Output is auto-split at the sitemap-protocol cap (50 000 URLs / file) into
 *     `sitemap-1.xml`, `sitemap-2.xml`, … and a `sitemap.xml` INDEX referencing
 *     them is written last. Search engines fetch the index, then each child.
 *   - Optional gzip (`.xml.gz`) — sitemaps are highly compressible and engines
 *     accept gzipped sitemaps, cutting disk + bandwidth ~8x.
 *
 * CPU is linear and allocation-light: one small string per URL, manual XML
 * escaping (no regex, no DOM), buffered writes.
 *
 * Dependency-free and DI-free — it is pure I/O plumbing in the Project layer.
 *
 * Usage (stream straight from the database, constant memory):
 *
 *   $result = (new SitemapStreamWriter('https://shop.example.com'))
 *       ->write(Paths::public('.'), $repo->streamAllUrls());   // Generator
 *   // $result = ['index' => '…/sitemap.xml', 'sitemaps' => [...], 'urls' => 1_250_000]
 */
final class SitemapStreamWriter
{
    private const HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    private const FOOTER = '</urlset>' . "\n";

    private const INDEX_HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    private const INDEX_FOOTER = '</sitemapindex>' . "\n";

    /**
     * @param string $baseUrl    Absolute site root, e.g. "https://example.com".
     * @param int    $maxPerFile URLs per child sitemap (protocol max is 50 000).
     * @param bool   $gzip       Write `.xml.gz` instead of `.xml`.
     * @param string $indexName  Filename of the index entry point.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $maxPerFile = 50000,
        private readonly bool $gzip = false,
        private readonly string $indexName = 'sitemap.xml',
    ) {
    }

    /**
     * Stream every URL to split child sitemaps + an index, all under $directory.
     *
     * @param iterable<array{loc?: string, path?: string, lastmod?: string, changefreq?: string, priority?: string}|string> $urls
     * @return array{index: string, sitemaps: list<string>, urls: int}
     */
    public function write(string $directory, iterable $urls): array
    {
        $dir = rtrim($directory, '/');
        $ext = $this->gzip ? '.xml.gz' : '.xml';

        /** @var list<string> $children child sitemap file names */
        $children = [];
        $handle    = null;
        $inFile    = 0;     // URLs written to the current child
        $total     = 0;     // URLs written overall
        $fileNo    = 0;

        foreach ($urls as $url) {
            // Roll over to a new child file at the first URL and every cap.
            if ($handle === null || $inFile >= $this->maxPerFile) {
                if ($handle !== null) {
                    $this->put($handle, self::FOOTER, $this->gzip);
                    $this->closeHandle($handle, $this->gzip);
                }
                $name       = 'sitemap-' . (++$fileNo) . $ext;
                $children[] = $name;
                $handle     = $this->openHandle("{$dir}/{$name}", $this->gzip);
                $this->put($handle, self::HEADER, $this->gzip);
                $inFile = 0;
            }

            $this->put($handle, $this->renderUrl($url), $this->gzip);
            $inFile++;
            $total++;
        }

        if ($handle !== null) {
            $this->put($handle, self::FOOTER, $this->gzip);
            $this->closeHandle($handle, $this->gzip);
        }

        $indexPath = $this->writeIndex($dir, $children);

        return ['index' => $indexPath, 'sitemaps' => $children, 'urls' => $total];
    }

    /**
     * Emit a SINGLE urlset by echoing chunks — for serving moderate sets (≤ the
     * cap) straight to an HTTP response via Response::stream() without buffering
     * the whole body. Returns the number of URLs emitted.
     *
     * @param iterable<array<string, string>|string> $urls
     */
    public function echoStream(iterable $urls): int
    {
        echo self::HEADER;

        $count = 0;
        foreach ($urls as $url) {
            echo $this->renderUrl($url);

            // Flush each row out of PHP's buffer so memory never grows and the
            // client starts receiving bytes immediately.
            if ((++$count % 1000) === 0 && function_exists('flush')) {
                flush();
            }
        }

        echo self::FOOTER;

        return $count;
    }

    /**
     * Write the index file referencing every child sitemap by absolute URL.
     *
     * @param list<string> $children
     */
    private function writeIndex(string $dir, array $children): string
    {
        $path   = "{$dir}/{$this->indexName}";
        $handle = $this->openHandle($path, false);   // index is small — never gzip
        $this->put($handle, self::INDEX_HEADER, false);

        $lastmod = $this->esc(date('c'));
        foreach ($children as $name) {
            $loc = $this->esc($this->baseUrl . '/' . $name);
            $this->put($handle, "  <sitemap><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod></sitemap>\n", false);
        }

        $this->put($handle, self::INDEX_FOOTER, false);
        $this->closeHandle($handle, false);

        return $path;
    }

    /**
     * Render one `<url>` element. Accepts a plain path/URL string or an assoc
     * array with optional lastmod/changefreq/priority.
     *
     * @param array<string, string>|string $url
     */
    private function renderUrl(array|string $url): string
    {
        if (is_string($url)) {
            $url = ['loc' => $url];
        }

        $loc = $this->absolute((string) ($url['loc'] ?? $url['path'] ?? ''));
        $xml = '  <url><loc>' . $this->esc($loc) . '</loc>';

        if (!empty($url['lastmod'])) {
            $xml .= '<lastmod>' . $this->esc((string) $url['lastmod']) . '</lastmod>';
        }
        if (!empty($url['changefreq'])) {
            $xml .= '<changefreq>' . $this->esc((string) $url['changefreq']) . '</changefreq>';
        }
        if (!empty($url['priority'])) {
            $xml .= '<priority>' . $this->esc((string) $url['priority']) . '</priority>';
        }

        return $xml . "</url>\n";
    }

    /** Make a relative path absolute; leave already-absolute URLs untouched. */
    private function absolute(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return $this->baseUrl . '/' . ltrim($pathOrUrl, '/');
    }

    /** XML-safe escaping (no DOM) — fast and allocation-light. */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return resource */
    private function openHandle(string $path, bool $gzip)
    {
        $handle = $gzip ? @gzopen($path, 'wb6') : @fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open sitemap file for writing: {$path}");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function put($handle, string $data, bool $gzip): void
    {
        if ($gzip) {
            gzwrite($handle, $data);

            return;
        }

        fwrite($handle, $data);
    }

    /** @param resource $handle */
    private function closeHandle($handle, bool $gzip): void
    {
        if ($gzip) {
            gzclose($handle);

            return;
        }

        fclose($handle);
    }
}
