<?php

declare(strict_types=1);

namespace Plugins\View\Infrastructure;

use Plugins\View\API\Contracts\ViewDecoratorContract;
use Plugins\View\API\Contracts\ViewRendererContract;
use Plugins\View\Exceptions\ViewException;

/**
 * PHP template renderer — derived from CodeIgniter 4's View engine, rewritten
 * to obey the platform's GDA rules:
 *
 *   - NO globals: view paths, extensions, decorators and the escaper are all
 *     injected via the constructor (the original used kernel()/config()/esc_html()).
 *   - Request-scoped: bound per-request in the View plugin's ModuleContainer,
 *     so its mutable $data/$sections never leak across requests under OpenSwoole.
 *   - No file locator dependency: views resolve against the injected $viewPaths.
 *
 * Supports the same feature set: data binding with optional HTML escaping,
 * layouts (via $options['layout'] or extend()/section()), section rendering,
 * includes, decorators and performance logging.
 */
final class PhpViewRenderer implements ViewRendererContract
{
    /** @var array<string,mixed> */
    private array $data = [];

    /** @var array<string,mixed>|null */
    private ?array $tempData = null;

    /** @var array<string,mixed> */
    private array $renderVars = [];

    /** @var array<int,array{start:float,end:float,view:string}> */
    private array $performanceData = [];

    private ?string $layout = null;

    /** @var array<string,list<string>> */
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    /**
     * @param list<string>                       $viewPaths  Absolute directories to resolve views against.
     * @param list<string>                       $extensions Recognised file extensions (default ['php']).
     * @param list<class-string<ViewDecoratorContract>> $decorators Output decorators, applied in order.
     * @param (callable(string):string)|null     $escaper    HTML escaper; defaults to htmlspecialchars.
     */
    public function __construct(
        private readonly array $viewPaths,
        private readonly array $extensions = ['php'],
        private readonly array $decorators = [],
        private readonly bool $saveData = false,
        ?callable $escaper = null,
    ) {
        $this->escaper = $escaper ?? static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @var callable(string):string */
    private $escaper;

    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        $start = microtime(true);
        $saveData ??= $this->saveData;

        $this->setupRenderVars($view, $options);
        $this->resolveViewFile();
        $this->prepareTemplateData($saveData);

        if (! empty($this->renderVars['options']['layout'])) {
            $viewOutput = $this->executeView();

            $layout = $this->renderVars['options']['layout'];
            $this->setupRenderVars($layout, $options);
            $this->resolveViewFile();

            $this->tempData['view'] = $viewOutput;
            $output = $this->executeView();
        } else {
            $output = $this->executeView();
            $output = $this->handleLayout($output, $options, $saveData);
        }

        $output = $this->decorateOutput($output);

        $this->logPerformance($start, microtime(true), $this->renderVars['view']);
        $this->tempData = null;

        return $output;
    }

    public function renderString(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        $start = microtime(true);
        $saveData ??= $this->saveData;
        $this->prepareTemplateData($saveData);

        $output = (function (string $view): string {
            extract($this->tempData);
            ob_start();
            eval('?>' . $view);

            return ob_get_clean() ?: '';
        })($view);

        $this->logPerformance($start, microtime(true), $this->excerpt($view));
        $this->tempData = null;

        return $output;
    }

    public function setData(array $data = [], ?string $context = null): static
    {
        if ($context !== null && $context !== 'raw') {
            foreach ($data as $key => $value) {
                $data[$key] = $this->escapeViewData($value);
            }
        }

        $this->tempData ??= $this->data;
        $this->tempData = array_merge($this->tempData, $data);

        return $this;
    }

    public function setVar(string $name, mixed $value = null, ?string $context = null): static
    {
        if ($context !== null && $context !== 'raw') {
            $value = $this->escapeViewData($value);
        }

        $this->tempData ??= $this->data;
        $this->tempData[$name] = $value;

        return $this;
    }

    public function resetData(): static
    {
        $this->data = [];

        return $this;
    }

    /** @return array<string,mixed> */
    public function getData(): array
    {
        return $this->tempData ?? $this->data;
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $contents = ob_get_clean();

        if ($this->sectionStack === []) {
            throw new \RuntimeException('View: no current section to end.');
        }

        $section = array_pop($this->sectionStack);
        $this->sections[$section] ??= [];
        $this->sections[$section][] = $contents;
    }

    public function renderSection(string $sectionName, bool $saveData = false): void
    {
        if (! isset($this->sections[$sectionName])) {
            return;
        }

        foreach ($this->sections[$sectionName] as $key => $contents) {
            echo $contents;
            if (! $saveData) {
                unset($this->sections[$sectionName][$key]);
            }
        }
    }

    public function include(string $view, ?array $options = null, bool $saveData = true): string
    {
        return $this->render($view, $options, $saveData);
    }

    /** @return array<int,array{start:float,end:float,view:string}> */
    public function getPerformanceData(): array
    {
        return $this->performanceData;
    }

    public function excerpt(string $string, int $length = 20): string
    {
        return (strlen($string) > $length) ? substr($string, 0, $length - 3) . '...' : $string;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function setupRenderVars(string $view, ?array $options): void
    {
        $fileExt = pathinfo($view, PATHINFO_EXTENSION);

        $this->renderVars['view'] = $fileExt === '' ? $view . '.php' : $view;

        if (! in_array($fileExt, $this->extensions, true)) {
            $this->renderVars['view'] = str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        }

        $this->renderVars['options'] = $options ?? [];
        $this->renderVars['start'] = microtime(true);
    }

    private function resolveViewFile(): void
    {
        $this->renderVars['file'] = false;

        $viewIsAbsolute = str_starts_with($this->renderVars['view'], DIRECTORY_SEPARATOR);
        if ($viewIsAbsolute && is_file($this->renderVars['view'])) {
            $this->renderVars['file'] = $this->renderVars['view'];

            return;
        }

        foreach ($this->viewPaths as $path) {
            $fullPath = realpath(rtrim($path, '/') . DIRECTORY_SEPARATOR . ltrim($this->renderVars['view'], '/'));
            if ($fullPath && is_file($fullPath)) {
                $this->renderVars['file'] = $fullPath;

                return;
            }
        }

        if ($this->renderVars['file'] === false) {
            throw ViewException::forInvalidFile($this->renderVars['view']);
        }
    }

    private function executeView(): string
    {
        $renderVars = $this->renderVars;

        $output = (function (): string {
            extract($this->tempData);
            ob_start();
            require $this->renderVars['file'];

            return ob_get_clean() ?: '';
        })();

        $this->renderVars = $renderVars;

        return $output;
    }

    private function handleLayout(string $output, ?array $options, bool $saveData): string
    {
        if ($this->layout !== null && $this->sectionStack === []) {
            $layoutView = $this->layout;
            $this->layout = null;
            $renderVars = $this->renderVars;
            $output = $this->render($layoutView, $options, $saveData);
            $this->renderVars = $renderVars;
        }

        return $output;
    }

    private function logPerformance(float $start, float $end, string $view): void
    {
        $this->performanceData[] = [
            'start' => $start,
            'end'   => $end,
            'view'  => $view,
        ];
    }

    private function prepareTemplateData(bool $saveData): void
    {
        $this->tempData ??= $this->data;

        if ($saveData) {
            $this->data = $this->tempData;
        }
    }

    private function escapeViewData(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->escapeViewData($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return ($this->escaper)($value);
        }

        return $value;
    }

    private function decorateOutput(string $html): string
    {
        foreach ($this->decorators as $decorator) {
            if (! is_subclass_of($decorator, ViewDecoratorContract::class)) {
                throw ViewException::forInvalidDecorator($decorator);
            }

            $html = $decorator::decorate($html);
        }

        return $html;
    }
}
