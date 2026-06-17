<?php

declare(strict_types=1);

namespace Plugins\View\API\Contracts;

/**
 * Published contract for the View plugin.
 *
 * Modules that declare "view.rendering" in their module.json requires[] may
 * inject this contract to render PHP templates into a string. The renderer is
 * request-scoped — never bind it as an app-lifetime singleton (it carries
 * mutable per-request template data).
 */
interface ViewRendererContract
{
    /**
     * Render a view file (resolved against the configured view paths) into HTML.
     *
     * @param array<string,mixed>|null $options Engine options (e.g. ['layout' => 'layouts/app']).
     * @param bool|null                $saveData Persist set data for subsequent calls.
     */
    public function render(string $view, ?array $options = null, ?bool $saveData = null): string;

    /**
     * Render a raw template string into HTML.
     *
     * @param array<string,mixed>|null $options
     */
    public function renderString(string $view, ?array $options = null, ?bool $saveData = null): string;

    /**
     * Set several pieces of view data at once.
     *
     * @param array<string,mixed>             $data
     * @param null|'html'|'raw'               $context When 'html', string values are escaped.
     */
    public function setData(array $data = [], ?string $context = null): static;

    /**
     * Set a single piece of view data.
     *
     * Escaping: pass $context = 'html' to pre-escape here AND echo the value raw
     * in the template, OR pass it raw (no context) and escape in the template.
     * Do NOT do both — that double-escapes.
     *
     * @param null|'html'|'raw' $context When 'html', string values are escaped.
     */
    public function setVar(string $name, mixed $value = null, ?string $context = null): static;

    /**
     * Remove all view data.
     */
    public function resetData(): static;
}
