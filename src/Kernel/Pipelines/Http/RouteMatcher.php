<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http;

/**
 * RouteMatcher — matches a method+path against the compiled route manifest.
 *
 * Exact matches are an O(1) hash lookup. Parameterized routes ({id}, {slug})
 * fall back to a regex scan with NAMED capture, so captured values are returned
 * and forwarded to the controller. Dynamic routes are bucketed by HTTP method,
 * so a request only scans the regexes registered for its own method.
 *
 * Built once and reused across requests — it holds no per-request state.
 */
final class RouteMatcher
{
    /** @var array<string, array<string, mixed>> "METHOD /path" => entry (static routes) */
    private array $static = [];

    /**
     * Dynamic (parameterized) routes bucketed by HTTP method, so a request only
     * scans the regexes for ITS method instead of the whole dynamic table.
     *
     * @var array<string, list<array{regex: string, params: list<string>, entry: array<string, mixed>}>>
     */
    private array $dynamic = [];

    /** @param array<string, array<string, mixed>> $manifest */
    public function __construct(array $manifest)
    {
        foreach ($manifest as $key => $entry) {
            [$method, $path] = explode(' ', $key, 2);

            if (!str_contains($path, '{')) {
                $this->static[$key] = $entry;
                continue;
            }

            $params = [];
            $regex  = preg_replace_callback(
                '/\{([^}]+)\}/',
                static function (array $m) use (&$params): string {
                    $name     = preg_replace('/[^a-zA-Z0-9_]/', '', $m[1]);
                    $params[] = $name;
                    return '(?P<' . $name . '>[^/]+)';
                },
                $path,
            );

            $this->dynamic[$method][] = [
                'regex'  => '#^' . $regex . '$#',
                'params' => $params,
                'entry'  => $entry,
            ];
        }
    }

    /**
     * @return array{entry: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $key = $method . ' ' . $path;
        if (isset($this->static[$key])) {
            return ['entry' => $this->static[$key], 'params' => []];
        }

        foreach ($this->dynamic[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($route['params'] as $name) {
                    $params[$name] = $matches[$name] ?? '';
                }
                return ['entry' => $route['entry'], 'params' => $params];
            }
        }

        return null;
    }
}
