<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\SiteSEO\OpenGraph;
use Plugins\SiteSEO\RobotsTxtEditor;
use Plugins\SiteSEO\StructuredProperties\Image;
use Plugins\SiteSEO\Type;
use Project\Support\Seo\RichGraph;
use Project\Support\Seo\RouteCatalog;
use Project\Support\Seo\SitemapGenerator;

/**
 * SEO helpers for base controllers.
 *
 * Gives any RequestAware controller a host-aware entry point into the SiteSEO
 * toolkit without reaching into the container or hard-coding the domain — the
 * base URL is read from the validated request (`Request::site()`), so the same
 * controller produces correct absolute URLs on every domain it is served from.
 *
 *   public function sitemap(): Response                 // RequestAware — no $request
 *   {
 *       $xml = $this->sitemapFromRoutes()               // every public page
 *           ->add('/blog/hello', priority: '0.6')       // + dynamic URLs you own
 *           ->toXml();
 *
 *       return Response::text($xml)->withHeader('Content-Type', 'application/xml');
 *   }
 *
 * The SiteSEO toolkit classes autoload directly, so these helpers work even on a
 * route that does NOT load the SEO module — no `requires` needed for sitemap /
 * Open Graph / robots building. (Pinging search engines DOES need the module,
 * because that hits the network through HttpClientPort.)
 */
trait InteractsWithSeo
{
    use HasRequest;

    /**
     * The absolute site root for the current request, e.g. "https://shop.example.com".
     */
    protected function siteBaseUrl(?Request $request = null): string
    {
        return rtrim($this->resolveRequest($request)->site()->base(), '/');
    }

    /**
     * Read the project's public pages from the compiled route manifest.
     */
    protected function routeCatalog(): RouteCatalog
    {
        return RouteCatalog::fromManifest();
    }

    /**
     * A fresh sitemap generator bound to the current request's host.
     */
    protected function sitemap(?Request $request = null): SitemapGenerator
    {
        return SitemapGenerator::for($this->siteBaseUrl($request));
    }

    /**
     * Sitemap generator pre-loaded with every public, static GET route.
     * Add dynamic URLs (blog posts, products) with ->add() before saving.
     */
    protected function sitemapFromRoutes(?Request $request = null): SitemapGenerator
    {
        return $this->sitemap($request)->fromRoutes($this->routeCatalog());
    }

    /**
     * Open Graph / Twitter meta builder for a page type (website, article, …).
     *
     * Chain the fluent setters and cast to string to render the <head> tags:
     *
     *   $tags = (string) $this->openGraph('article', $post->title)
     *       ->description($post->excerpt)
     *       ->url($this->siteBaseUrl().'/blog/'.$post->slug)
     *       ->image($this->ogImage($post->cover, 1200, 630, $post->title))
     *       ->twitterLargeImage();
     */
    protected function openGraph(string $type = 'website', ?string $title = null): Type
    {
        /** @var Type $object */
        $object = OpenGraph::{$type}($title);

        return $object;
    }

    /**
     * Build a structured OG image (dimensions + alt + secure_url) for `og:image`.
     *
     * Facebook/LinkedIn render a far better card when width/height are declared
     * up front (no reflow, no re-crawl). Pass a relative path or absolute URL —
     * relative paths are made absolute against the current host. The recommended
     * size for a large card is 1200×630.
     */
    protected function ogImage(
        string $url,
        ?int $width = null,
        ?int $height = null,
        ?string $alt = null,
        ?Request $request = null,
    ): Image {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = $this->siteBaseUrl($request) . '/' . ltrim($url, '/');
        }

        $image = Image::make($url)->secureUrl($url);

        if ($width !== null) {
            $image->width($width);
        }
        if ($height !== null) {
            $image->height($height);
        }
        if ($alt !== null) {
            $image->alt($alt);
        }

        return $image;
    }

    /**
     * Start a Schema.org JSON-LD rich-results graph bound to the current host.
     *
     * Produces the connected `@graph` Google reads for rich snippets (article
     * cards, product price/stars, breadcrumbs, sitelinks searchbox, FAQ). Render
     * it into the page <head> alongside the Open Graph tags:
     *
     *   $jsonLd = (string) $this->richGraph()
     *       ->organization('PSP Shop', logo: '/img/logo.png')
     *       ->website(searchUrl: '/search?q={search_term_string}')
     *       ->webPage($url, $title)
     *       ->breadcrumb([['Home','/'], ['Blog','/blog'], [$title, $url]])
     *       ->article($url, $title, $excerpt, image: '/img/x.jpg',
     *                 datePublished: $date, authorName: 'Hakeem');
     */
    protected function richGraph(?Request $request = null): RichGraph
    {
        return RichGraph::for($this->siteBaseUrl($request));
    }

    /**
     * The robots.txt editor (reads/writes the live robots.txt).
     */
    protected function robots(string $encoding = RobotsTxtEditor::DEFAULT_ENCODING): RobotsTxtEditor
    {
        return new RobotsTxtEditor($encoding);
    }
}
