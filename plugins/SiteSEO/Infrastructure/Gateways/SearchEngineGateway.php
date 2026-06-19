<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;

/**
 * Outbound search-engine communication (IndexNow + sitemap ping).
 *
 * GDA gateway rules: talks to the outside world ONLY through HttpClientPort,
 * translates every transport failure into a GatewayException so no vendor /
 * transport exception escapes this layer.
 */
final class SearchEngineGateway
{
    /** Default engines pinged when a sitemap changes. */
    private const DEFAULT_PING_ENGINES = [
        'https://www.google.com',
        'https://www.bing.com',
        'https://webmaster.yandex.com',
    ];

    public function __construct(
        private readonly HttpClientPort $http,
    ) {
    }

    /**
     * Submit URLs to the IndexNow protocol, one request per engine.
     *
     * @param array<string, string> $keys Engine host => API key.
     * @param list<string>          $urls
     * @return array<string, bool>        Engine host => accepted (2xx).
     */
    public function submitIndexNow(string $host, array $keys, array $urls): array
    {
        $accepted = [];

        foreach ($keys as $engine => $key) {
            try {
                $response = $this->http->post("https://{$engine}/indexnow", [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json'    => [
                        'host'    => $host,
                        'key'     => $key,
                        'urlList' => array_values($urls),
                    ],
                ]);

                $accepted[$engine] = $response->ok();
            } catch (\Throwable $e) {
                throw new GatewayException(
                    "IndexNow submission to [{$engine}] failed.",
                    layer: 'gateway.seo.indexnow',
                    context: ['engine' => $engine, 'urls' => count($urls)],
                    previous: $e,
                );
            }
        }

        return $accepted;
    }

    /**
     * Submit ONE batch of URLs to a single IndexNow endpoint (modern protocol).
     *
     * Includes `keyLocation` so the engine can verify domain ownership against
     * the hosted key file instead of guessing `{key}.txt` at the root. A single
     * submission to api.indexnow.org propagates to all participating engines
     * (Bing, Yandex, Seznam, Naver, …). The IndexNow cap is 10 000 URLs per
     * request — the caller (service) chunks to honour it.
     *
     * @param list<string> $urls Absolute URLs, all on $host.
     * @return bool             Whether the endpoint accepted the batch (2xx).
     */
    public function indexNowBatch(string $endpoint, string $host, string $key, string $keyLocation, array $urls): bool
    {
        try {
            $response = $this->http->post($endpoint, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json'    => [
                    'host'        => $host,
                    'key'         => $key,
                    'keyLocation' => $keyLocation,
                    'urlList'     => array_values($urls),
                ],
            ]);

            // IndexNow: 200/202 accepted, 422 = invalid URLs, 403 = key not
            // verified, 429 = too many. Only 2xx counts as accepted.
            return $response->ok();
        } catch (\Throwable $e) {
            throw new GatewayException(
                "IndexNow submission to [{$endpoint}] failed.",
                layer: 'gateway.seo.indexnow',
                context: ['endpoint' => $endpoint, 'urls' => count($urls)],
                previous: $e,
            );
        }
    }

    /**
     * Ping search engines with an updated sitemap URL.
     *
     * @param list<string> $extraEngines Additional engine base URLs.
     */
    public function pingSitemap(string $sitemapUrl, array $extraEngines = []): void
    {
        $engines = array_unique([...self::DEFAULT_PING_ENGINES, ...$extraEngines]);

        foreach ($engines as $engine) {
            try {
                $this->http->get("{$engine}/ping", ['sitemap' => $sitemapUrl]);
            } catch (\Throwable $e) {
                throw new GatewayException(
                    "Sitemap ping to [{$engine}] failed.",
                    layer: 'gateway.seo.ping',
                    context: ['engine' => $engine, 'sitemap' => $sitemapUrl],
                    previous: $e,
                );
            }
        }
    }
}
