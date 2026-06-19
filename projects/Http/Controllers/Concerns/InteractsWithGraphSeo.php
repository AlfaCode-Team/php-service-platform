<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Project\Support\Seo\RichGraph;
use Project\Support\Seo\SeoHead;

/**
 * The full-fat SEO trait: everything in {@see InteractsWithSeo} (sitemap, Open
 * Graph, robots.txt, structured images) PLUS the Schema.org rich-graph and a
 * one-call <head> assembler ({@see SeoHead}) with canonical / robots / hreflang.
 *
 * Use this on any page controller that wants the works. A typical action:
 *
 *   public function show(string $slug): Response
 *   {
 *       $url = $this->siteBaseUrl() . '/blog/' . $slug;
 *
 *       $head = $this->seoHead()
 *           ->title($post->title)->description($post->excerpt)
 *           ->canonical($url)                          // dedupe ?utm=, /amp, …
 *           ->hreflang('en', $url)->xDefault($url)     // language variants
 *           ->openGraph(
 *               $this->openGraph('article', $post->title)
 *                   ->description($post->excerpt)->url($url)
 *                   ->image($this->ogImage($post->cover, 1200, 630))
 *                   ->twitterLargeImage()
 *           )
 *           ->graph(
 *               $this->graph()
 *                   ->organization('PSP Shop', logo: '/img/logo.png')
 *                   ->website('PSP Shop', searchUrl: '/search?q={search_term_string}')
 *                   ->webPage($url, $post->title)
 *                   ->breadcrumb([['Home','/'], ['Blog','/blog'], [$post->title, $url]])
 *                   ->blogPosting($url, $post->title, $post->excerpt, image: $post->cover,
 *                                 datePublished: $post->date, authorName: $post->author)
 *           );
 *
 *       return Response::html("<!doctype html><html><head>{$head}</head>…");
 *   }
 */
trait InteractsWithGraphSeo
{
    use InteractsWithSeo;

    /**
     * A Schema.org rich-results graph bound to the current host.
     *
     * Alias of {@see InteractsWithSeo::richGraph()} with the name that matches
     * this trait — both return the same host-aware {@see RichGraph}.
     */
    protected function graph(?Request $request = null): RichGraph
    {
        return $this->richGraph($request);
    }

    /**
     * A complete <head> SEO assembler bound to the current host — attach OG,
     * JSON-LD, canonical, robots and hreflang, then cast to string in the page.
     */
    protected function seoHead(?Request $request = null): SeoHead
    {
        return SeoHead::for($this->siteBaseUrl($request));
    }
}
