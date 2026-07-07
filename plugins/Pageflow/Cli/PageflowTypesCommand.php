<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * pageflow:types — generate TypeScript typings for the Pageflow bridge.
 *
 * Emits a `.d.ts` that gives the client end-to-end type help Inertia lacks:
 *   • the shared props the server always sends (pageflow_auth, errors, csrf_token)
 *     are merged into PageProps, so usePage().props is typed for them;
 *   • every page component found under --pages is enumerated into a
 *     `PageflowPages` registry, so component names autocomplete and each page has
 *     a typed slot to declare its own props (augment PageflowPages in your app).
 *
 *   hkm pageflow:types                                   # auto-discover the frontend
 *   hkm pageflow:types --pages=resources/js/Pages --out=resources/js/pageflow.d.ts
 *
 * With no options it discovers the `hkm ui` frontend layout under the active
 * project: every surface's pages (frontend/src/surfaces/{star}/Pages/{star}{star}) plus the
 * pages federated by enabled plugins (frontend/plugins/{star}/{admin,site}/Pages/{star}{star}),
 * writing to frontend/src/pageflow.d.ts.
 */
final class PageflowTypesCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'pageflow:types';
        $this->description = 'Generate TypeScript typings (shared props + page registry) for the Pageflow client';

        // Empty defaults → auto-discover the frontend layout (see resolvePagesRoots/resolveOut).
        $this->addOption('pages', '', 'Directory of page components to enumerate (default: auto-discover the frontend)', acceptsValue: true, default: '');
        $this->addOption('out', '', 'Output .d.ts path (default: frontend/src/pageflow.d.ts)', acceptsValue: true, default: '');
    }

    protected function handle(): int
    {
        $pagesOpt = (string) $this->option('pages');
        $roots    = $pagesOpt !== '' ? [$pagesOpt] : $this->discoverPagesRoots();
        $outPath  = (string) $this->option('out') ?: $this->defaultOut();

        $components = [];
        $found      = false;
        foreach ($roots as $root) {
            if (is_dir($root)) {
                $found = true;
                foreach ($this->scanComponents($root) as $name) {
                    $components[$name] = true;
                }
            }
        }
        if (!$found) {
            $this->info(sprintf(
                'No page directories found (%s) — emitting shared-prop typings only.',
                implode(', ', $roots) ?: '(none)',
            ));
        }
        $components = array_keys($components);
        sort($components);

        $contents = $this->render($components);

        $dir = \dirname($outPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create output directory: {$dir}");
            return 1;
        }

        if (@file_put_contents($outPath, $contents) === false) {
            $this->error("Failed to write: {$outPath}");
            return 1;
        }

        $this->info(sprintf('Wrote %s (%d page component%s).', $outPath, \count($components), \count($components) === 1 ? '' : 's'));
        return 0;
    }

    /**
     * Enumerate component names from *.tsx/*.jsx/*.vue files, using the path
     * relative to the pages dir (sans extension) as the component key — matching
     * the usual resolvePageComponent() convention (e.g. "Users/Index").
     *
     * @return list<string>
     */
    private function scanComponents(string $pagesDir): array
    {
        if (!is_dir($pagesDir)) {
            return [];
        }

        $names = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!\in_array($ext, ['tsx', 'jsx', 'vue'], true)) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($pagesDir))), '/');
            $name = preg_replace('/\.(tsx|jsx|vue)$/i', '', $relative);
            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * Auto-discover every `Pages/` root in the `hkm ui` frontend of the active
     * project: each surface (frontend/src/surfaces/{name}/Pages) plus the pages
     * federated by enabled plugins (frontend/plugins/{name}/{admin,site}/Pages).
     * Falls back to the legacy resources/js/Pages when no frontend exists.
     *
     * @return list<string>
     */
    private function discoverPagesRoots(): array
    {
        $frontend = Paths::project('frontend');
        if (!is_dir($frontend)) {
            return [Paths::project('resources/js/Pages')];
        }

        $roots = [];
        foreach (glob($frontend . '/src/surfaces/*/Pages', GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[] = $dir;
        }
        foreach (glob($frontend . '/plugins/*/{admin,site}/Pages', GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $dir) {
            $roots[] = $dir;
        }

        return $roots !== [] ? $roots : [$frontend . '/src/Pages'];
    }

    /** Default output path — beside the frontend source, else legacy resources/js. */
    private function defaultOut(): string
    {
        $frontend = Paths::project('frontend');
        return is_dir($frontend)
            ? $frontend . '/src/pageflow.d.ts'
            : Paths::project('resources/js/pageflow.d.ts');
    }

    /** @param list<string> $components */
    private function render(array $components): string
    {
        $generated = date('c');

        $registry = $components === []
            ? "  // No page components discovered. Augment this interface to type a page:\n"
                . "  //   'Users/Index': { users: User[] }\n"
            : implode("\n", array_map(
                static fn(string $name): string => sprintf('  %s: Record<string, unknown>', self::quote($name)),
                $components,
            ));

        return <<<TS
        // ---------------------------------------------------------------------------
        // GENERATED by `hkm pageflow:types` on {$generated}. Do not edit by hand.
        // Re-run the command after adding pages or changing shared props.
        // ---------------------------------------------------------------------------
        import '@pageflow/react'

        /**
         * Shared props the server sends on every page (see the PHP Provider).
         * Merged into PageProps so usePage().props sees them everywhere.
         */
        export interface PageflowSharedProps {
          pageflow_auth: {
            userId: string
            tenantId: string
            roles: string[]
            permissions: string[]
            authenticated: boolean
          }
          errors: Record<string, string>
          csrf_token?: string
        }

        /**
         * Registry of page component name -> its props. Augment per page in your app:
         *   declare module '@pageflow/react' {
         *     interface PageflowPages { 'Users/Index': { users: User[] } }
         *   }
         */
        export interface PageflowPages {
        {$registry}
        }

        declare module '@pageflow/core' {
          interface PageProps extends PageflowSharedProps {}
        }

        declare module '@pageflow/react' {
          interface PageflowPages {}
        }

        TS;
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }
}
