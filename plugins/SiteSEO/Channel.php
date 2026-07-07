<?php

namespace Plugins\SiteSEO;


class Channel
{
    protected static array $shared = [];
    protected static array $meta = [];

    // === Shared data API ===

    public static function share(string $key, mixed $value): void
    {
        static::$shared[$key] = $value;
    }

    public static function mergeShared(array $data): void
    {
        static::$shared = array_merge(static::$shared, $data);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::$shared[$key] ?? $default;
    }

    public static function all(): array
    {
        return static::$shared;
    }

    public static function forget(string $key): void
    {
        unset(static::$shared[$key]);
    }

    // === Meta / SEO ===

    public static function setMeta(string $key, string $value): void
    {
        static::$meta[$key] = $value;
    }

    public static function setMetaBulk(array $data): void
    {
        static::$meta = array_merge(static::$meta, $data);
    }

    public static function getMeta(string $key, ?string $default = null): ?string
    {
        return static::$meta[$key] ?? $default;
    }

    public static function allMeta(): array
    {
        return static::$meta;
    }

    public static function renderMeta(callable|null $titleCallback = null): void
    {

        // Title
        if (!empty(static::$meta['title'])) {
            $title = static::$meta['title'];
            if ($titleCallback) {
                $title = $titleCallback($title);
            }
            static::$meta['title']  = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        
        do_action("head", static::$meta);
    }

    // === Flash messages ===

    protected static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        static::ensureSession();
        $_SESSION['__channel_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        static::ensureSession();
        if (isset($_SESSION['__channel_flash'][$key])) {
            $value = $_SESSION['__channel_flash'][$key];
            unset($_SESSION['__channel_flash'][$key]); // one-time
            return $value;
        }
        return $default;
    }

    public static function allFlash(): array
    {
        static::ensureSession();
        $all = $_SESSION['__channel_flash'] ?? [];
        $_SESSION['__channel_flash'] = []; // clear after read
        return $all;
    }

    // === Error bags ===

    public static function errorBag(string $name, array $errors): void
    {
        static::ensureSession();
        $_SESSION['__channel_errors'][$name] = $errors;
    }

    public static function getErrors(string $name): array
    {
        static::ensureSession();
        return $_SESSION['__channel_errors'][$name] ?? [];
    }

    public static function allErrorBags(): array
    {
        static::ensureSession();
        return $_SESSION['__channel_errors'] ?? [];
    }

    public static function clearErrorBag(string $name): void
    {
        static::ensureSession();
        unset($_SESSION['__channel_errors'][$name]);
    }

    // === Bootstrap script for client ===

    public static function renderSharedScript(): string
    {
        $payload = [
            'props' => static::$shared,
            'meta' => static::$meta,
            'flash' => static::allFlash(),
            'errors' => static::allErrorBags(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "<script>window.__BOOTSTRAP_DATA__ = $json;</script>";
    }
}
