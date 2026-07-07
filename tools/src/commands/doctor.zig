//! `hkm doctor` — diagnose the local environment before install / first run.
//!
//! Verifies the machine can actually run a PhpServicePlatform project:
//!   • a `php` binary is on PATH (or HKM_PHP_BIN) and is >= 8.4
//!   • every REQUIRED PHP extension is loaded
//!   • reports OPTIONAL extensions (redis, swoole, pdo drivers …) as hints
//!   • at least one PDO driver is present
//!   • the kernel autoload is resolvable (packaged install or --dev checkout)
//!
//! The extension/version checks are delegated to PHP itself (a `php -r` preflight
//! script) so they reflect the EXACT runtime a project will use — not a guess.
//! Exit code is 0 only when PHP is present, new enough, and no required
//! extension is missing; otherwise 1 (CI-friendly gate before `hkm run`).

const std = @import("std");
const run_cmd = @import("run.zig");
const kernel = @import("../lib/kernel.zig");
const prompt = @import("../lib/prompt.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// The PHP preflight. Kept as a single `-r` program so `hkm doctor` needs no
/// files on disk. Prints a human table and exits non-zero on a hard failure.
const preflight =
    \\$reqPhp = '8.4.1';
    \\$okPhp  = version_compare(PHP_VERSION, $reqPhp, '>=');
    \\printf("  php %-13s runtime %s (need >= %s) [%s]\n", '', PHP_VERSION, $reqPhp, $okPhp ? 'OK' : 'FAIL');
    \\$required = ['json','mbstring','ctype','tokenizer','filter','pdo','openssl','curl','fileinfo'];
    \\$missing = [];
    \\foreach ($required as $e) {
    \\    $has = extension_loaded($e);
    \\    if (!$has) $missing[] = $e;
    \\    printf("  ext-%-11s %s\n", $e, $has ? 'OK' : 'MISSING <-- required');
    \\}
    \\$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    \\$hasDriver = (bool) array_intersect(['mysql','pgsql','sqlite','sqlsrv'], $drivers);
    \\printf("  pdo-driver     %s (%s)\n", $hasDriver ? 'OK' : 'MISSING <-- need one', $drivers ? implode(',', $drivers) : 'none');
    \\$optional = [
    \\  'redis'      => 'RedisCache plugin (cache + queue)',
    \\  'swoole'     => 'OpenSwoole HTTP server (api face)',
    \\  'openswoole' => 'OpenSwoole HTTP server (api face)',
    \\  'gd'         => 'image processing',
    \\  'intl'       => 'i18n / locale formatting',
    \\  'zip'        => 'archive support',
    \\  'sodium'     => 'modern crypto (recommended)',
    \\];
    \\echo "\n  optional:\n";
    \\foreach ($optional as $e => $why) {
    \\    printf("  ext-%-11s %-9s %s\n", $e, extension_loaded($e) ? 'present' : 'absent', $why);
    \\}
    \\$hardFail = !$okPhp || $missing || !$hasDriver;
    \\echo "\n";
    \\if ($hardFail) { echo "  RESULT: FAIL — resolve the required items above.\n"; }
    \\else           { echo "  RESULT: OK — environment satisfies the framework requirements.\n"; }
    \\exit($hardFail ? 1 : 0);
;

fn phpBin(allocator: std.mem.Allocator, env: *EnvMap) ![]const u8 {
    if (env.get("HKM_PHP_BIN")) |v| return allocator.dupe(u8, v);
    return allocator.dupe(u8, "php");
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    _ = args;
    prompt.intro("hkm doctor");

    prompt.section("Platform");
    prompt.item("os", @tagName(@import("builtin").os.tag));
    prompt.item("arch", @tagName(@import("builtin").cpu.arch));

    // Show WHERE the launcher will find the kernel PHP CLI, and whether it
    // actually exists — the #1 thing to confirm on a portable/.app/zip install.
    prompt.section("Kernel");
    const k = try kernel.resolve(allocator, io, env);
    prompt.item("cli path", k.path);
    prompt.item("resolved via", kernel.sourceLabel(k.source));
    prompt.item("present", if (k.exists) "yes" else "NO — set HKM_KERNEL_HOME or reinstall");

    const php = try phpBin(allocator, env);

    prompt.section("PHP runtime & extensions");

    // Run the preflight with stdout/stderr inherited so PHP prints the table.
    var argv = [_][]const u8{ php, "-d", "display_errors=stderr", "-r", preflight };
    const code = run_cmd.spawnWait(io, env, &argv) catch |e| {
        prompt.err("could not execute the PHP binary — is PHP installed and on PATH?");
        prompt.item("tried", php);
        prompt.item("override", "set HKM_PHP_BIN=/full/path/to/php");
        prompt.blank();
        prompt.section("Install PHP >= 8.4");
        prompt.item("Debian/Ubuntu", "sudo apt install php8.4-cli php8.4-{mbstring,curl,pdo,mysql,xml}");
        prompt.item("macOS (brew)", "brew install php");
        prompt.item("Windows", "winget install PHP.PHP  (or https://windows.php.net)");
        prompt.item("detail", @errorName(e));
        return 1;
    };

    prompt.blank();
    if (code == 0) {
        prompt.ok("environment is ready — you can run `hkm run` / `hkm new`.");
    } else {
        prompt.warn("environment is INCOMPLETE — install the items marked required above.");
        prompt.item("Debian/Ubuntu", "sudo apt install php8.4-{mbstring,curl,openssl,pdo,mysql,sqlite3}");
        prompt.item("macOS (brew)", "brew install php   # bundles the common extensions");
    }
    return code;
}
