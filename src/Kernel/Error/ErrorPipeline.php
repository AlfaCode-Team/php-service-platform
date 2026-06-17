<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts\NotifierContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers\FileNotifier;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * ErrorPipeline — classifies a failure and routes it to the right notifiers.
 *
 * Configured fluently in bootstrap/app.php:
 *
 *   ErrorPipeline::notifiers([
 *       new SlackNotifier(...), new DatabaseErrorLogger(...),
 *   ])
 *   ->fallback(new FileNotifier(logs_path('errors.log')))
 *   ->rules([
 *       'critical' => ['slack', 'database', 'file'],
 *       'warning'  => ['database', 'file'],
 *       'info'     => ['file'],
 *   ]);
 * 
 * Guarantees:
 *   - The fallback notifier ALWAYS runs, regardless of rules.
 *   - A failing notifier never aborts the others.
 *   - consume() never throws.
 */
final class ErrorPipeline
{
    /** @var array<string, NotifierContract> name => notifier */
    private array $notifiers = [];

    private ?NotifierContract $fallback = null;

    /** @var array<string, list<string>> severity => notifier names */
    private array $rules = [
        ErrorClassifier::CRITICAL => [],
        ErrorClassifier::WARNING  => [],
        ErrorClassifier::INFO     => [],
    ];

    /** @param NotifierContract[] $notifiers */
    public static function notifiers(array $notifiers): self
    {
        $pipeline = new self();
        foreach ($notifiers as $notifier) {
            $pipeline->notifiers[$notifier->name()] = $notifier;
        }
        return $pipeline;
    }

    /** A sane default used when the project does not configure a pipeline. */
    public static function default(): self
    {
        $file = new FileNotifier(Paths::logs('errors.log'), rotateDaily: true);
        return self::notifiers([$file])
            ->fallback($file)
            ->rules([
                ErrorClassifier::CRITICAL => ['file'],
                ErrorClassifier::WARNING  => ['file'],
                ErrorClassifier::INFO     => ['file'],
            ]);
    }

    public function fallback(NotifierContract $notifier): self
    {
        $this->fallback = $notifier;
        $this->notifiers[$notifier->name()] = $notifier;
        return $this;
    }

    /** @param array<string, list<string>> $rules */
    public function rules(array $rules): self
    {
        foreach ($rules as $severity => $names) {
            $this->rules[$severity] = $names;
        }
        return $this;
    }

    /**
     * Consume an error: run every notifier mapped to its severity, then the
     * guaranteed fallback. Never throws.
     */
    public function consume(ErrorContext $context): void
    {
        $names = $this->rules[$context->severity] ?? [];
        $ran   = [];

        foreach ($names as $name) {
            $notifier = $this->notifiers[$name] ?? null;
            if ($notifier === null) {
                continue;
            }
            $this->safeNotify($notifier, $context);
            $ran[$name] = true;
        }

        // The fallback always runs, even if it was already covered by the rules
        // it is idempotent enough (append a line) — but avoid double-writing.
        if ($this->fallback !== null && !isset($ran[$this->fallback->name()])) {
            $this->safeNotify($this->fallback, $context);
        }
    }

    private function safeNotify(NotifierContract $notifier, ErrorContext $context): void
    {
        try {
            $notifier->notify($context);
        } catch (\Throwable) {
            // Notifiers must not throw; if one does, swallow it so the rest run.
        }
    }
}
