//! `hkm-config` — inspect and repair the launcher's persistent configuration
//! (`~/.config/hkm/config.env`, loaded by `hkm` at startup).
//!
//!   hkm-config                     # check config; auto-configure if incomplete
//!   hkm-config check               # same as no-args
//!   hkm-config print               # show the config file path + contents
//!   hkm-config set-kernel-home <p> # pin HKM_KERNEL_HOME
//!   hkm-config set-autoload <p>    # pin HKM_GLOBAL_AUTOLOAD (vendor/autoload.php)
//!
//! "check" resolves the kernel (env → relative to this binary → /opt/hkm-kernel)
//! and, if the config file is missing or stale, writes HKM_KERNEL_HOME for you.

const std = @import("std");
const kernel = @import("lib/kernel.zig");
const userconfig = @import("lib/userconfig.zig");
const prompt = @import("lib/prompt.zig");
const util = @import("lib/util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub fn main(init: std.process.Init.Minimal) !void {
    var arena: std.heap.ArenaAllocator = .init(std.heap.page_allocator);
    defer arena.deinit();
    const allocator = arena.allocator();
    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();
    var env = try init.environ.createMap(allocator);
    defer env.deinit();

    // Make already-saved config visible to resolution below.
    userconfig.load(allocator, io, &env);

    const args = try init.args.toSlice(allocator);
    const action = if (args.len >= 2) args[1] else "check";

    if (std.mem.eql(u8, action, "print")) {
        const p = (try userconfig.path(allocator, &env)) orelse {
            prompt.err("cannot resolve config path (no HOME).");
            std.process.exit(1);
        };
        prompt.section("Config file");
        prompt.item("path", p);
        if (std.Io.Dir.cwd().readFileAlloc(io, p, allocator, .limited(64 * 1024))) |c| {
            prompt.blank();
            std.debug.print("{s}\n", .{c});
        } else |_| prompt.muted("(file does not exist yet — run `hkm-config` to create it)");
        return;
    }

    if (std.mem.eql(u8, action, "set-kernel-home")) {
        if (args.len < 3) return usage();
        try userconfig.set(allocator, io, &env, "HKM_KERNEL_HOME", args[2]);
        prompt.ok("HKM_KERNEL_HOME saved.");
        return;
    }
    if (std.mem.eql(u8, action, "set-autoload")) {
        if (args.len < 3) return usage();
        try userconfig.set(allocator, io, &env, "HKM_GLOBAL_AUTOLOAD", args[2]);
        prompt.ok("HKM_GLOBAL_AUTOLOAD saved.");
        return;
    }
    if (std.mem.eql(u8, action, "check") or std.mem.eql(u8, action, "configure")) {
        std.process.exit(try runCheck(allocator, io, &env));
    }

    usage();
}

fn usage() void {
    prompt.section("hkm-config");
    prompt.item("hkm-config", "check config; auto-configure if incomplete");
    prompt.item("hkm-config print", "show the config file path + contents");
    prompt.item("hkm-config set-kernel-home <p>", "pin the kernel root");
    prompt.item("hkm-config set-autoload <p>", "pin vendor/autoload.php");
}

fn runCheck(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !u8 {
    prompt.intro("hkm-config");

    const cfg = (try userconfig.path(allocator, env)) orelse {
        prompt.err("no HOME — cannot locate ~/.config/hkm/config.env");
        return 1;
    };
    prompt.section("Configuration");
    prompt.item("config file", cfg);
    prompt.item("exists", if (util.fileExists(io, cfg)) "yes" else "no (will create)");

    // 1. Locate the kernel.
    const home = (try kernel.resolveHome(allocator, io, env)) orelse {
        prompt.blank();
        prompt.err("no kernel found.");
        prompt.item("fix", "install the hkm-kernel package, or: hkm-config set-kernel-home <path>");
        return 1;
    };
    prompt.item("kernel home", home);

    // 2. Check kernel pieces.
    const autoload = try std.fs.path.join(allocator, &.{ home, "vendor", "autoload.php" });
    const projects = try std.fs.path.join(allocator, &.{ home, "projects", "projects.json" });
    const have_vendor = util.fileExists(io, autoload);
    const have_registry = util.fileExists(io, projects);
    prompt.item("vendor/autoload.php", if (have_vendor) "present" else "MISSING");
    prompt.item("projects registry", if (have_registry) "present" else "absent (no projects registered yet)");

    // 3. Ensure HKM_KERNEL_HOME is persisted and current.
    const saved = try userconfig.get(allocator, io, env, "HKM_KERNEL_HOME");
    var wrote = false;
    if (saved == null or !std.mem.eql(u8, saved.?, home)) {
        try userconfig.set(allocator, io, env, "HKM_KERNEL_HOME", home);
        wrote = true;
    }

    prompt.blank();
    if (!have_vendor) {
        prompt.warn("kernel dependencies are not installed.");
        const installer = try std.fs.path.join(allocator, &.{ home, "install.sh" });
        if (util.fileExists(io, installer)) {
            prompt.item("run", try std.fmt.allocPrint(allocator, "sh {s}", .{installer}));
        } else {
            prompt.item("run", try std.fmt.allocPrint(allocator, "cd {s} && composer install --no-dev", .{home}));
        }
        return 1;
    }

    if (wrote) {
        prompt.ok("configuration written — HKM_KERNEL_HOME pinned.");
    } else {
        prompt.ok("configuration is complete.");
    }
    prompt.muted("verify the runtime with: hkm doctor");
    return 0;
}
