<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * Server side of the Pageflow (Inertia-style) protocol.
 *
 * A controller returns a component name + props; this responder decides the
 * wire format:
 *   - XHR pageflow request (X-Pageflow: true)  -> JSON page object
 *   - full page load                           -> HTML document embedding the
 *                                                 page object in data-page
 *
 * It also honours partial reloads: when the client sends X-Pageflow-Partial-Data
 * for the same component, only the requested props are returned.
 */
final class PageflowResponder
{
    public function __construct(
        private readonly string $version,
        private readonly string $rootView,
        private readonly string $appId = 'app',
    ) {
    }

    /**
     * @param array<string,mixed> $props
     */
    public function render(Request $request, string $component, array $props): Response
    {
        $props = $this->resolvePartial($request, $component, $props);

        $page = new PageflowPage(
            component: $component,
            props:     $props,
            url:       $this->fullUrl($request),
            version:   $this->version,
        );

        return $this->isPageflow($request)
            ? $this->jsonResponse($page)
            : $this->htmlResponse($page);
    }

    public function isPageflow(Request $request): bool
    {
        return strtolower((string) ($request->header('X-Pageflow') ?? '')) === 'true';
    }

    private function jsonResponse(PageflowPage $page): Response
    {
        return Response::json($page->toArray(), 200, [
            'X-Pageflow' => 'true',
            'Vary'       => 'X-Pageflow',
        ]);
    }

    private function htmlResponse(PageflowPage $page): Response
    {
        $dataPage = htmlspecialchars($page->toJson(), ENT_QUOTES, 'UTF-8');
        $appDiv = sprintf('<div id="%s" data-page="%s"></div>', $this->appId, $dataPage);

        $html = str_contains($this->rootView, '{{app}}')
            ? str_replace('{{app}}', $appDiv, $this->rootView)
            : $this->defaultDocument($appDiv);

        return Response::text($html, 200)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Partial reload: when the client asks for a subset of props on the same
     * component, return only those (or all except the excluded ones).
     *
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private function resolvePartial(Request $request, string $component, array $props): array
    {
        $only   = $this->headerList($request, 'X-Pageflow-Partial-Data');
        $except = $this->headerList($request, 'X-Pageflow-Partial-Except');
        $target = (string) ($request->header('X-Pageflow-Partial-Component') ?? '');

        // Partial rules only apply when the requested component matches.
        if (($only === [] && $except === []) || ($target !== '' && $target !== $component)) {
            return $props;
        }

        if ($only !== []) {
            $props = array_intersect_key($props, array_flip($only));
        }
        if ($except !== []) {
            $props = array_diff_key($props, array_flip($except));
        }

        return $props;
    }

    /** @return list<string> */
    private function headerList(Request $request, string $name): array
    {
        $raw = (string) ($request->header($name) ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s) => $s !== ''));
    }

    private function fullUrl(Request $request): string
    {
        $path = $request->path();
        $query = http_build_query($request->queryAll());
        return $query === '' ? $path : $path . '?' . $query;
    }

    private function defaultDocument(string $appDiv): string
    {
        return '<!DOCTYPE html>' . "\n"
            . '<html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '</head><body>' . $appDiv . '</body></html>';
    }
}
