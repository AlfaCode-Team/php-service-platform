<?php

declare(strict_types=1);

namespace Plugins\I18n\Support;

use Plugins\I18n\Translator;

/**
 * Request-scoped holder that lets the global translation helpers (__/trans/
 * trans_choice) reach the active Translator without a container reference.
 *
 * The value is bound by LocaleStage at the start of each HTTP request and
 * cleared in a finally block once the response is produced, so nothing leaks
 * between requests. Under OpenSwoole each worker serves one request at a time
 * through the pipeline; the stage's set/clear pair keeps the binding scoped to
 * the in-flight request. When no translator is bound (CLI/worker, or the I18n
 * module not loaded for a route) the helpers degrade gracefully.
 */
final class Lang
{
    private static ?Translator $translator = null;

    public static function bind(Translator $translator): void
    {
        self::$translator = $translator;
    }

    public static function clear(): void
    {
        self::$translator = null;
    }

    public static function translator(): ?Translator
    {
        return self::$translator;
    }
}
