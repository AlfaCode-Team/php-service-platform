<?php

declare(strict_types=1);

namespace Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\I18n\Translator;

/**
 * Minimal, dependency-free validation engine.
 *
 * Produces the kernel's existing ValidationException (field => messages) so
 * controllers/DTOs get the framework's standard 422 error shape without each
 * one hand-rolling checks.
 *
 * Message resolution order per failed rule:
 *   1. custom override messages["{field}.{rule}"]
 *   2. Translator "validation.{rule}" (when a Translator is provided)
 *   3. built-in English default
 *
 * Usage:
 *   Validator::make($request->all(), [
 *       'email'    => 'required|email',
 *       'age'      => 'required|integer|min:18',
 *       'password' => 'required|string|min:8|confirmed',
 *   ])->validate();   // throws ValidationException on failure, returns validated data on success
 *
 * Supported rules:
 *   required, nullable, string, integer, numeric, boolean, array, email, url,
 *   min:n, max:n, between:a,b, in:a,b,c, regex:/.../, same:field, different:field,
 *   confirmed
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /** Built-in English defaults; :field and rule params are interpolated. */
    private const DEFAULTS = [
        'required'  => 'The :field field is required.',
        'string'    => 'The :field field must be a string.',
        'integer'   => 'The :field field must be an integer.',
        'numeric'   => 'The :field field must be numeric.',
        'boolean'   => 'The :field field must be true or false.',
        'array'     => 'The :field field must be an array.',
        'email'     => 'The :field field must be a valid email address.',
        'url'       => 'The :field field must be a valid URL.',
        'min'       => 'The :field field must be at least :min.',
        'max'       => 'The :field field must not be greater than :max.',
        'between'   => 'The :field field must be between :min and :max.',
        'in'        => 'The selected :field is invalid.',
        'regex'     => 'The :field field format is invalid.',
        'same'      => 'The :field field must match :other.',
        'different' => 'The :field field must be different from :other.',
        'confirmed' => 'The :field field confirmation does not match.',
    ];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages custom "field.rule" => message overrides
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly ?Translator $translator = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages
     */
    public static function make(array $data, array $rules, array $messages = [], ?Translator $translator = null): self
    {
        return new self($data, $rules, $messages, $translator);
    }

    public function fails(): bool
    {
        $this->run();
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    /**
     * Validate and return only the validated fields; throws on failure.
     *
     * @return array<string,mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }
        return $validated;
    }

    private function run(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            $present = array_key_exists($field, $this->data) && $value !== '' && $value !== null;

            // nullable short-circuits all other rules when the field is absent/empty.
            if (!$present && in_array('nullable', $rules, true)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable' || $rule === '') {
                    continue;
                }
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name !== 'required' && !$present) {
                    continue; // value rules skip absent fields; required handles presence
                }

                $replace = $this->validateRule($name, $value, $param, $field);
                if ($replace !== null) {
                    $this->addError($field, $name, $replace);
                }
            }
        }
    }

    /**
     * Returns null when the rule passes, or an array of message replacements
     * (the rule failed).
     *
     * @return array<string,string|int|float>|null
     */
    private function validateRule(string $rule, mixed $value, ?string $param, string $field): ?array
    {
        $ok = match ($rule) {
            'required'  => $this->present($value),
            'string'    => is_string($value),
            'integer'   => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric'   => is_numeric($value),
            'boolean'   => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array'     => is_array($value),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'min'       => $this->size($value) >= (float) $param,
            'max'       => $this->size($value) <= (float) $param,
            'between'   => $this->between($value, $param),
            'in'        => in_array((string) $value, explode(',', (string) $param), true),
            'regex'     => $param !== null && @preg_match($param, (string) $value) === 1,
            'same'      => $value === ($this->data[$param] ?? null),
            'different' => $value !== ($this->data[$param] ?? null),
            'confirmed' => $value === ($this->data[$field . '_confirmation'] ?? null),
            default     => true, // unknown rule — pass rather than fail hard
        };

        return $ok ? null : $this->replacements($rule, $param);
    }

    /** @return array<string,string|int|float> */
    private function replacements(string $rule, ?string $param): array
    {
        $replace = [];
        switch ($rule) {
            case 'min':
            case 'max':
                $replace[$rule] = (string) $param;
                break;
            case 'between':
                [$a, $b] = array_pad(explode(',', (string) $param), 2, '');
                $replace['min'] = $a;
                $replace['max'] = $b;
                break;
            case 'same':
            case 'different':
                $replace['other'] = (string) $param;
                break;
            case 'in':
                $replace['values'] = (string) $param;
                break;
        }
        return $replace;
    }

    private function present(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && $value !== '';
    }

    private function size(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }
        return (float) mb_strlen((string) $value);
    }

    private function between(mixed $value, ?string $param): bool
    {
        [$a, $b] = array_pad(explode(',', (string) $param), 2, null);
        $n = $this->size($value);
        return $n >= (float) $a && $n <= (float) $b;
    }

    /** @param array<string,string|int|float> $replace */
    private function addError(string $field, string $rule, array $replace): void
    {
        $replace['field'] = $field;
        $this->errors[$field][] = $this->message($field, $rule, $replace);
    }

    /** @param array<string,string|int|float> $replace */
    private function message(string $field, string $rule, array $replace): string
    {
        $custom = $this->messages["{$field}.{$rule}"] ?? null;
        if ($custom !== null) {
            return $this->interpolate($custom, $replace);
        }

        if ($this->translator !== null && $this->translator->has("validation.{$rule}")) {
            return $this->translator->get("validation.{$rule}", $replace);
        }

        return $this->interpolate(self::DEFAULTS[$rule] ?? "The {$field} field is invalid.", $replace);
    }

    /** @param array<string,string|int|float> $replace */
    private function interpolate(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(':' . $key, (string) $value, $line);
        }
        return $line;
    }
}
