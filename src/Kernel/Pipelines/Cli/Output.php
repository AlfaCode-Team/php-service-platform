<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli;

/**
 * @deprecated This class is no longer used by CliPipeline.
 *             Use Symfony\Component\Console\Output\OutputInterface (available via
 *             \AlfacodeTeam\PhpIoCli\AbstractCommand::$output) for console output.
 */
final class Output
{
    private const COLORS = [
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'gray'   => "\033[90m",
    ];
    private const RESET = "\033[0m";

    public function __construct(
        private readonly bool $decorated = true
    ) {}

    public function writeln(string $text, ?string $color = null): void
    {
        if ($color !== null && $this->decorated && isset(self::COLORS[$color])) {
            $text = self::COLORS[$color] . $text . self::RESET;
        }
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public function info(string $text): void    { $this->writeln($text, 'cyan'); }
    public function success(string $text): void  { $this->writeln($text, 'green'); }
    public function warning(string $text): void  { $this->writeln($text, 'yellow'); }
    public function error(string $text): void    { fwrite(STDERR, self::wrapErr($text) . PHP_EOL); }

    private static function wrapErr(string $text): string
    {
        return self::COLORS['red'] . $text . self::RESET;
    }
}
