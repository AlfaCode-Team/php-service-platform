<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

/**
 * UserAgent — immutable, parsed view of a User-Agent header (clean rewrite of
 * the 0.3 UserAgent class, with no external config files or vendor deps).
 *
 * Parsing is best-effort heuristic matching, deliberately small: it identifies
 * the broad platform, browser + version, and the bot/mobile flags that gate the
 * vast majority of server-side decisions. It is NOT a full device-detection
 * library and should not be used for security decisions.
 *
 * Build one with UserAgent::fromRequest($request) or UserAgent::parse($string).
 */
final class UserAgent
{
    /** @var array<string, string> platform name => match needle (lower-case) */
    private const PLATFORMS = [
        'Windows'   => 'windows',
        'Android'   => 'android',
        'iOS'       => 'iphone',
        'iPadOS'    => 'ipad',
        'macOS'     => 'macintosh',
        'Linux'     => 'linux',
        'Chrome OS' => 'cros',
    ];

    /** Browser tokens in priority order (first match wins). */
    private const BROWSERS = [
        'Edge'    => '/edg(?:e|ios|a)?\/([\d.]+)/i',
        'Opera'   => '/(?:opera|opr)\/([\d.]+)/i',
        'Firefox' => '/firefox\/([\d.]+)/i',
        'Chrome'  => '/(?:chrome|crios)\/([\d.]+)/i',
        'Safari'  => '/version\/([\d.]+).*safari/i',
    ];

    private function __construct(
        private readonly string $raw,
        private readonly string $platform,
        private readonly string $browser,
        private readonly string $version,
        private readonly bool $robot,
        private readonly bool $mobile,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::parse($request->userAgent() ?? '');
    }

    public static function parse(string $ua): self
    {
        $lower = strtolower($ua);

        $platform = '';
        foreach (self::PLATFORMS as $name => $needle) {
            if (str_contains($lower, $needle)) {
                $platform = $name;
                break;
            }
        }

        $browser = '';
        $version = '';
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $ua, $m) === 1) {
                $browser = $name;
                $version = $m[1] ?? '';
                break;
            }
        }

        $robot = $ua !== '' && preg_match('/bot|crawl|slurp|spider|mediapartners|facebookexternalhit|curl|wget|python-requests/i', $ua) === 1;
        $mobile = preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua) === 1;

        return new self($ua, $platform, $browser, $version, $robot, $mobile);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function browser(): string
    {
        return $this->browser;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isRobot(): bool
    {
        return $this->robot;
    }

    public function isMobile(): bool
    {
        return $this->mobile;
    }

    /** A real browser: a known browser token and not a bot. */
    public function isBrowser(): bool
    {
        return $this->browser !== '' && !$this->robot;
    }

    public function isEmpty(): bool
    {
        return $this->raw === '';
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
