<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli;

/**
 * @deprecated This class is no longer used by CliPipeline.
 *             Use Symfony\Component\Console\Input\InputInterface (available via
 *             \AlfacodeTeam\PhpIoCli\AbstractCommand::$input) for argument access.
 */
final class Arguments
{
    /** @var list<string> */
    private array $positional = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @param list<string> $argv raw args (excluding the command name) */
    public function __construct(array $argv)
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if (str_contains($body, '=')) {
                    [$k, $v] = explode('=', $body, 2);
                    $this->options[$k] = $v;
                } else {
                    $this->options[$body] = true;
                }
            } elseif (str_starts_with($arg, '-')) {
                $this->options[substr($arg, 1)] = true;
            } else {
                $this->positional[] = $arg;
            }
        }
    }

    public function get(int $index, ?string $default = null): ?string
    {
        return $this->positional[$index] ?? $default;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /** @return list<string> */
    public function positional(): array
    {
        return $this->positional;
    }
}
