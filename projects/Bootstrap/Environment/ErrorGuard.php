<?php

declare(strict_types=1);

namespace Project\Bootstrap\Environment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\DebugPageRenderer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;

/**
 * ErrorGuard — production-safe last-resort error net (Project layer).
 *
 * The kernel's HTTP ErrorStage already returns generic, leak-free responses for
 * anything that happens INSIDE a built kernel. But errors that happen BEFORE the
 * kernel exists — a thrown RuntimeException in app/bootstrap/base.php (e.g. the
 * APP_KEY guard), a parse error, an autoload failure, an OOM — never reach that
 * stage. With php.ini `display_errors = On` they are printed verbatim: full stack
 * traces, absolute file paths, and sometimes the values of nearby variables.
 * That is exactly how frameworks leak secrets on a crash.
 *
 * This guard, installed in the entry points immediately after the environment is
 * loaded, ensures that in non-debug mode such a failure produces ONLY a generic
 * 500 (HTML or JSON, SAPI-aware) while the real detail is sent to the error log.
 *
 * Debug mode is opt-in via APP_DEBUG=true (or APP_ENV in local/development).
 * In production the developer cannot accidentally enable a verbose error page —
 * it requires an explicit env flag, which should never be set on a live host.
 */
final class ErrorGuard
{
    private static bool $installed = false;

    /** Same JSON-line sink the kernel's FileNotifier writes to (var/logs/errors.log). */
    private static ?string $logFile = null;

    /** Resolved once at install(): render the debug page vs a generic 500. */
    private static bool $debug = false;

    /**
     * @param string|null $logFile Absolute path to the unified error log. Pass the
     *        same file the kernel's FileNotifier uses ({project}/var/logs/errors.log)
     *        so pre-kernel and fatal failures land alongside every other error
     *        instead of in PHP's separate error_log. When null, only error_log()
     *        is used. The guard stays kernel-independent — this is a file append,
     *        not a call into the (possibly non-existent) ErrorPipeline.
     * @param bool $registerHandlers When false, only hardens php.ini flags and
     *        does not install output handlers. Use this for long-running servers
     *        (OpenSwoole) that own their own error/output lifecycle.
     */
    public static function install(?string $logFile = null, bool $registerHandlers = true): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        self::$logFile   = $logFile;

        $debug = self::isDebug();

        // Always report everything to the log; never paint it on the response
        // surface in production.
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');

        if (!$registerHandlers) {
            // ini-only mode (OpenSwoole owns its own output lifecycle).
            return;
        }

        self::$debug = $debug;

        set_exception_handler(static function (\Throwable $e): void {
            self::log($e);
            self::emit($e);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (($err['type'] & $fatal) === 0) {
                return;
            }
            $e = new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']);
            self::log($e);
            self::emit($e);
        });
    }

    /** Reset (tests only). */
    public static function reset(): void
    {
        self::$installed = false;
        self::$logFile   = null;
        self::$debug     = false;
    }

    /**
     * Whether the guard is running in debug (developer) mode. Exposed so the
     * project bootstrap can pick the in-request error pipeline: the rich
     * ErrorGuard-backed notifier in development, the production notifiers
     * (Slack/Mail/DB/File) on a live host.
     */
    public static function debugEnabled(): bool
    {
        return self::isDebug();
    }

    /**
     * In-request entry point for the kernel's ErrorPipeline (via the project-layer
     * {@see ErrorGuardNotifier}). The kernel's ErrorStage catches a Throwable inside a
     * running pipeline, builds an immutable {@see ErrorContext}, and hands it here so
     * the SAME ErrorGuard machinery that handles pre-kernel/fatal failures also records
     * in-request failures — one error layer, one unified log.
     *
     * Never throws (notifier contract): a failure to write degrades to error_log().
     * Does NOT emit a response — the kernel's ErrorStage owns the HTTP/CLI surface and
     * already renders the DebugPageRenderer in debug mode.
     */
    public static function capture(ErrorContext $context): void
    {
        error_log(sprintf(
            '[Sentinel] %s %s in layer "%s": %s',
            $context->severity,
            $context->exceptionClass,
            $context->layer,
            $context->message,
        ));

        if (self::$logFile === null) {
            return;
        }

        try {
            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $line = json_encode(
                ['source' => 'error_guard'] + $context->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            @file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Last line of defence — error_log() above already fired.
        }
    }

    private static function isDebug(): bool
    {
        $debug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');
        if ($debug !== false && $debug !== null && $debug !== '') {
            return filter_var($debug, FILTER_VALIDATE_BOOL);
        }

        $env = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
        return in_array($env, ['local', 'development', 'dev'], true);
    }

    private static function log(\Throwable $e): void
    {
        error_log(sprintf(
            '[Sentinel] Uncaught %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        self::writeUnifiedLog($e);
    }

    /**
     * Append a JSON line to the same var/logs/errors.log the kernel's FileNotifier
     * writes to, in a compatible shape, tagged source=error_guard. This is the
     * deliberate, kernel-independent connection between the two error layers: a
     * file append (never a call into the ErrorPipeline, which may not exist).
     */
    private static function writeUnifiedLog(\Throwable $e): void
    {
        if (self::$logFile === null) {
            return;
        }

        try {
            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $line = json_encode([
                'source'         => 'error_guard',
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
                'severity'       => 'critical',
                'layer'          => 'bootstrap',
                'correlation_id' => null,
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
                'occurred_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            @file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Last line of defence — error_log() above already fired.
        }
    }

    /**
     * Render the outcome of a caught failure. In debug, show the rich developer
     * page (the same kernel DebugPageRenderer the in-request ErrorStage uses);
     * otherwise emit a generic, secret-free 500.
     */
    private static function emit(\Throwable $e): void
    {
        if (!self::$debug) {
            self::emitGeneric();
            return;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, DebugPageRenderer::renderCli($e));
            return;
        }

        // AJAX / API / JSON clients get debug detail as JSON, never the HTML page.
        if (self::expectsJson()) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => [
                'code'    => 'server_error',
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]], JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo DebugPageRenderer::renderHtml($e);
    }

    /**
     * Pre-kernel equivalent of Request::expectsJson() — there is no Request object
     * yet, so detect AJAX / API / JSON intent straight from $_SERVER:
     *   - X-Requested-With: XMLHttpRequest      (AJAX/fetch)
     *   - Accept: …/json or …+json              (API client)
     *   - Content-Type: …/json                  (JSON request body)
     *   - path under /api                        (API surface convention)
     */
    private static function expectsJson(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, '/json') || str_contains($accept, '+json')) {
            return true;
        }

        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), '/json')) {
            return true;
        }

        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        return is_string($path) && str_starts_with($path, '/api');
    }

    /**
     * Emit a generic, secret-free 500. SAPI-aware: JSON for API/web, plain text
     * for CLI. Never echoes the exception message in production.
     */
    private static function emitGeneric(): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "A fatal error occurred. See the error log for details.\n");
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo '{"error":{"code":"server_error","message":"An internal error occurred."}}';
    }
}
