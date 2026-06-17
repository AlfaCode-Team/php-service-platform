<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Concerns\ManagesResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Response — HTTP response value object backed by Symfony HttpFoundation.
 *
 * Built via named constructors (json, empty, notFound, …). Treated as IMMUTABLE:
 * withHeader / withStatus / withCookie return clones and never mutate the
 * original. Cookies are managed through Symfony's ResponseHeaderBag so SAPI and
 * Swoole adapters both emit them correctly.
 *
 * Standard error body shape:
 *   { "error": { "code": "...", "message": "...", "fields": {...} } }
 */
final class Response extends SymfonyResponse
{
    use ManagesResponse;

    /** Set for streamed responses — invoked at send()/body() time. */
    private ?\Closure $streamCallback = null;

    /** Set for file responses — streamed from disk at send()/body() time. */
    private ?string $filePath = null;

    // ── Named constructors ────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            $status,
            ['Content-Type' => 'application/json'] + $headers,
        );
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Convenience: a 200 JSON success envelope. */
    public static function success(mixed $data = null, int $status = 200): self
    {
        return self::json(['success' => true, 'data' => $data], $status);
    }

    /** 201 Created — optional Location header for the new resource. */
    public static function created(mixed $data, ?string $location = null): self
    {
        $response = self::json($data, 201);

        return $location !== null ? $response->withHeader('Location', $location) : $response;
    }

    /** 202 Accepted — request queued for async processing. */
    public static function accepted(mixed $data = null): self
    {
        return $data === null ? self::empty(202) : self::json($data, 202);
    }

    public static function empty(int $status = 204): self
    {
        return new self('', $status, []);
    }

    /** Alias for empty(204). */
    public static function noContent(): self
    {
        return self::empty(204);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return self::error('not_found', $message, 404);
    }

    public static function unauthorized(string $message = 'Unauthenticated.'): self
    {
        return self::error('unauthorized', $message, 401);
    }

    public static function forbidden(string $message = 'Forbidden.'): self
    {
        return self::error('forbidden', $message, 403);
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return self::error('bad_request', $message, 400);
    }

    public static function conflict(string $message = 'Conflict.'): self
    {
        return self::error('conflict', $message, 409);
    }

    /** 429 — sets Retry-After when a delay is supplied. */
    public static function tooManyRequests(string $message = 'Too many requests.', ?int $retryAfter = null): self
    {
        $response = self::error('too_many_requests', $message, 429);

        return $retryAfter !== null ? $response->withHeader('Retry-After', (string) $retryAfter) : $response;
    }

    /** @param array<string, string|string[]> $errors */
    public static function unprocessable(array $errors, string $message = 'Validation failed.'): self
    {
        return self::json([
            'error' => [
                'code'    => 'validation_failed',
                'message' => $message,
                'fields'  => $errors,
            ],
        ], 422);
    }

    public static function serverError(string $message = 'An internal error occurred.'): self
    {
        return self::error('server_error', $message, 500);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** 301 — permanent redirect (cacheable, changes the canonical URL). */
    public static function permanentRedirect(string $url): self
    {
        return self::redirect($url, 301);
    }

    /**
     * Redirect back to where the request came from. Pass the request's Referer
     * (e.g. $request->header('referer')); falls back to $fallback when absent.
     */
    public static function back(?string $referer, string $fallback = '/', int $status = 302): self
    {
        return self::redirect($referer !== null && $referer !== '' ? $referer : $fallback, $status);
    }

    private static function error(string $code, string $message, int $status): self
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** JSONP — wraps a JSON payload in a JavaScript callback invocation. */
    public static function jsonp(string $callback, mixed $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';

        return new self(
            sprintf('/**/%s(%s);', $callback, $json),
            $status,
            ['Content-Type' => 'application/javascript'],
        );
    }

    /**
     * Stream output produced by a callback (no in-memory body).
     *
     * @param array<string, string> $headers
     */
    public static function stream(callable $callback, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        $response->streamCallback = \Closure::fromCallable($callback);

        return $response;
    }

    /**
     * Stream a download produced by a callback (no file on disk).
     *
     * @param array<string, string> $headers
     */
    public static function streamDownload(callable $callback, string $name, array $headers = []): self
    {
        $response = self::stream($callback, 200, $headers);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            str_replace('%', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)),
        ));

        return $response;
    }

    /**
     * Force a file download from disk.
     *
     * @param array<string, string> $headers
     */
    public static function download(\SplFileInfo|string $file, ?string $name = null, array $headers = []): self
    {
        return self::fileResponse($file, $name, $headers, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    /** Serve a file inline (rendered in-browser). */
    public static function file(\SplFileInfo|string $file, ?string $name = null): self
    {
        return self::fileResponse($file, $name, [], ResponseHeaderBag::DISPOSITION_INLINE);
    }

    /** @param array<string, string> $headers */
    private static function fileResponse(\SplFileInfo|string $file, ?string $name, array $headers, string $disposition): self
    {
        $path = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
        $name ??= basename($path);

        $response = new self('', 200, $headers);
        $response->filePath = $path;
        if (! $response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/octet-stream');
        }
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $disposition,
            $name,
            str_replace('%', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)),
        ));

        return $response;
    }

    // ── Sending (SAPI) ──────────────────────────────────────────────────────────

    public function send(bool $flush = true): static
    {
        if ($this->streamCallback !== null) {
            if (! headers_sent()) {
                $this->sendHeaders();
            }
            ($this->streamCallback)();

            return $this;
        }

        if ($this->filePath !== null) {
            if (! headers_sent()) {
                if (! $this->headers->has('Content-Length') && is_file($this->filePath)) {
                    $this->headers->set('Content-Length', (string) filesize($this->filePath));
                }
                $this->sendHeaders();
            }
            if (is_file($this->filePath)) {
                readfile($this->filePath);
            }

            return $this;
        }

        return parent::send($flush);
    }

    /**
     * Materialise the body as a string (used by Swoole adapters that read
     * status()/headers()/body()/cookies() instead of calling send()).
     */
    public function body(): string
    {
        if ($this->filePath !== null) {
            return is_file($this->filePath) ? (file_get_contents($this->filePath) ?: '') : '';
        }

        if ($this->streamCallback !== null) {
            ob_start();
            ($this->streamCallback)();

            return (string) ob_get_clean();
        }

        return (string) $this->getContent();
    }

    // ── Transport-agnostic emission (Swoole adapters) ────────────────────────────

    /** True when this response streams a file from disk (use sendfile()). */
    public function isFile(): bool
    {
        return $this->filePath !== null;
    }

    /** True when this response streams output from a callback. */
    public function isStreamed(): bool
    {
        return $this->streamCallback !== null;
    }

    /** Absolute path of the file to stream, or null for non-file responses. */
    public function filePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * Emit a streamed response in chunks via $writer, without buffering the whole
     * body in memory. For non-streamed responses, $writer receives the full body
     * once. Lets a Swoole adapter pipe output through $res->write().
     *
     * @param callable(string): void $writer
     */
    public function streamTo(callable $writer): void
    {
        if ($this->streamCallback === null) {
            $writer($this->body());

            return;
        }

        ob_start(static function (string $buffer) use ($writer): string {
            if ($buffer !== '') {
                $writer($buffer);
            }

            return '';
        }, 8192);

        ($this->streamCallback)();

        ob_end_flush();
    }
}
