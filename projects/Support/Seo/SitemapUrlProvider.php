<?php

declare(strict_types=1);

namespace Project\Support\Seo;

/**
 * Expands ONE dynamic route pattern into concrete sitemap URLs from a data store.
 *
 * The route manifest knows the PATTERN (`/blog/{slug}`) but not the values — the
 * slugs live in the database. {@see RouteCatalog} therefore skips any `{param}`
 * route. A provider closes that gap: it "claims" a pattern and streams the real
 * URLs that fill it.
 *
 * CRITICAL: urls() MUST be lazy — return a \Generator backed by a keyset DB
 * cursor (WHERE id > :last ORDER BY id LIMIT :chunk), never an array and never a
 * single unbounded SELECT. That is what keeps {@see SitemapStreamWriter} at O(1)
 * memory across millions of rows.
 *
 * Example:
 *   final class BlogSitemapProvider implements SitemapUrlProvider
 *   {
 *       public function pattern(): string { return '/blog/{slug}'; }
 *
 *       public function urls(): iterable
 *       {
 *           $lastId = 0;
 *           while ($rows = $this->db->query(
 *               'SELECT id, slug, updated_at FROM posts
 *                WHERE id > :last AND published = 1 ORDER BY id LIMIT 5000',
 *               ['last' => $lastId])
 *           ) {
 *               foreach ($rows as $r) {
 *                   $lastId = (int) $r['id'];
 *                   yield ['loc' => '/blog/' . $r['slug'], 'lastmod' => $r['updated_at']];
 *               }
 *           }
 *       }
 *   }
 */
interface SitemapUrlProvider
{
    /**
     * The dynamic route pattern this provider fills, e.g. "/blog/{slug}".
     *
     * Used by {@see SitemapSource} to match the provider against the `{param}`
     * routes in the manifest, so it can warn about dynamic routes that have NO
     * provider (and would otherwise be silently absent from the sitemap).
     */
    public function pattern(): string;

    /**
     * Lazily yield concrete sitemap entries for this pattern.
     *
     * @return iterable<array{loc?: string, path?: string, lastmod?: string, changefreq?: string, priority?: string}|string>
     */
    public function urls(): iterable;
}
