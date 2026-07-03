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
 *   - XHR pageflow request (X-Pageflow header, or Accept: application/json)
 *       -> JSON page object
 *   - full page load
 *       -> HTML document that boots the client from `window.initialPage`
 *
 * Wire protocol matches the legacy HKM\lib\PageFlow implementation:
 *   - initial load renders a PHP layout template via ob_start (the template has
 *     $FLOW_PAGE in scope and echoes $FLOW_PAGE->renderScript()), exposing the
 *     page object as `window.initialPage` so existing clients boot unchanged;
 *   - shared data (see share()/mergeShared()) is merged into every page's props,
 *     page props winning on key collision;
 *   - partial reloads honour X-Pageflow-Partial-Data / -Except / -Component;
 *   - loadPage:false navigations rewrite url/component from
 *     X-Pageflow-Url / X-Pageflow-Page.
 *
 * Shared data is held per instance (NOT static) so it is request-scoped and
 * safe under OpenSwoole; the module binds the responder as a per-request
 * singleton so share() and render() see the same bag.
 */
final class PageflowResponder
{
    /** @var array<string,mixed> */
    private array $shared = [];

    /**
     * @param string $layoutPath absolute path to the PHP layout template rendered
     *                           via ob_start for a full page load ($FLOW_PAGE is
     *                           in scope). Empty falls back to a minimal document.
     */
    public function __construct(
        private readonly string $version,
        private readonly string $layoutPath,
        private readonly string $appId = 'app',
    ) {
    }

    /** Register a single shared prop present on every rendered page. */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Merge many shared props at once.
     *
     * @param array<string,mixed> $data
     */
    public function mergeShared(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * @param array<string,mixed> $props
     * @param bool $loadPage false for a client-driven partial navigation that
     *                       supplies its own url/component via headers.
     */
    public function render(Request $request, string $component, array $props, bool $loadPage = true): Response
    {
        // Shared props first, page props override (matches legacy array_merge).
        $props = array_merge($this->shared, $props);
        $props = $this->resolvePartial($request, $component, $props);

        $url = $this->fullUrl($request);
        if (!$loadPage) {
            $headerUrl = (string) ($request->header('X-Pageflow-Url') ?? '');
            $parsed = $headerUrl !== '' ? (parse_url($headerUrl, PHP_URL_PATH) ?: $url) : $url;
            $url = (string) $parsed;
            $component = (string) ($request->header('X-Pageflow-Page') ?? $component);
        }

        $page = new PageflowPage(
            component: $component,
            props:     $props,
            url:       $url,
            version:   $this->version,
        );

        return $this->isPageflow($request)
            ? $this->jsonResponse($page)
            : $this->htmlResponse($page);
    }

    /**
     * A pageflow (XHR) request carries the X-Pageflow header (any value) OR
     * negotiates JSON via Accept — matching the legacy detection.
     */
    public function isPageflow(Request $request): bool
    {
        if ((string) ($request->header('X-Pageflow') ?? '') !== '') {
            return true;
        }

        return str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'application/json');
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
        $html = ($this->layoutPath !== '' && is_file($this->layoutPath))
            ? $this->renderLayout($this->layoutPath, $page)
            : $this->defaultDocument($page);

        return Response::text($html, 200)->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Render a PHP layout template, capturing its output with ob_start. The
     * template runs in an isolated scope with only $FLOW_PAGE available (legacy
     * ResponseFactory::send contract) — no globals leak in.
     */
    private function renderLayout(string $path, PageflowPage $page): string
    {
        $capture = static function (string $__path, PageflowPage $FLOW_PAGE): string {
            ob_start();
            try {
                require $__path;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return ob_get_clean() ?: '';
        };

        return $capture($path, $page);
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

    private function defaultDocument(PageflowPage $page): string
    {
        $mount = sprintf('<div id="%s"></div>', htmlspecialchars($this->appId, ENT_QUOTES, 'UTF-8'));

        return '<!DOCTYPE html>' . "\n"
            . '<html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . $page->renderScript()
            . '</head><body>' . $mount . '</body></html>';
    }
}
