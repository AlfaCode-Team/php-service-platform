<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\SiteSEO\API\Contracts\SeoServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * HTTP surface for the SEO module.
 *
 * Thin controllers (≤3 lines of logic): translate the request into a service
 * call and the result into a Response. All SEO logic lives in
 * {@see \Plugins\SiteSEO\Application\Services\SeoService}.
 */
final class SeoController extends ApiController
{
    public function __construct(
        private readonly SeoServiceContract $seo,
    ) {
    }

    /** Serve the live robots.txt as plain text. */
    public function robots(): Response
    {
        return Response::text($this->seo->robots()->getContent())
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** Notify search engines that a sitemap changed. */
    public function ping(Request $request): Response
    {
        $sitemap = (string) $request->input('sitemap', '');

        if ($sitemap === '') {
            return $this->unprocessable(['sitemap' => 'A sitemap URL is required.']);
        }

        $this->seo->pingSitemap($sitemap, (array) $request->input('engines', []));

        return $this->accepted(['pinged' => $sitemap]);
    }

    /** Submit URLs to IndexNow for instant indexing. */
    public function indexNow(Request $request): Response
    {
        $host = (string) $request->input('host', '');
        $keys = (array) $request->input('keys', []);
        $urls = (array) $request->input('urls', []);

        if ($host === '' || $keys === [] || $urls === []) {
            return $this->unprocessable([
                'host' => 'host, keys and urls are all required.',
            ]);
        }

        return $this->ok(['accepted' => $this->seo->submitUrls($host, $keys, $urls)]);
    }
}
