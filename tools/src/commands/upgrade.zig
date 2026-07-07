//! `hkm upgrade [--check]` — check for and apply kernel updates.
//!
//!   hkm upgrade --check   # compare the installed version to the latest release
//!   hkm upgrade           # git checkout → pull + composer; packaged → guidance
//!
//! "Latest" is the highest v* tag on the kernel repo, discovered with
//! `git ls-remote` (no API token, works for the public repo). The header is the
//! Sentinel banner + current version.

const std = @import("std");
const banner = @import("../lib/banner.zig");
const kernel = @import("../lib/kernel.zig");
const run_cmd = @import("run.zig");
const util = @import("../lib/util.zig");
const prompt = @import("../lib/prompt.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Ver = struct {
    major: u32 = 0,
    minor: u32 = 0,
    patch: u32 = 0,

    fn parse(s: []const u8) Ver {
        var t = s;
        if (t.len > 0 and (t[0] == 'v' or t[0] == 'V')) t = t[1..];
        // Ignore any pre-release / build suffix (-dev, -rc1, +meta).
        if (std.mem.indexOfAny(u8, t, "-+")) |i| t = t[0..i];
        var v: Ver = .{};
        var it = std.mem.splitScalar(u8, t, '.');
        v.major = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        v.minor = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        v.patch = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        return v;
    }

    fn order(a: Ver, b: Ver) std.math.Order {
        if (a.major != b.major) return std.math.order(a.major, b.major);
        if (a.minor != b.minor) return std.math.order(a.minor, b.minor);
        return std.math.order(a.patch, b.patch);
    }
};

const Latest = union(enum) {
    tag: []const u8, // highest v* tag found
    none, // repo reachable but has no release tags yet
    unreachable_, // git failed / offline
};

/// Query the remote repo for the highest v* tag.
fn latestTag(allocator: std.mem.Allocator, io: Io, env: *EnvMap) Latest {
    const url = std.fmt.allocPrint(allocator, "https://github.com/{s}.git", .{banner.repo()}) catch return .unreachable_;
    const res = std.process.run(allocator, io, .{
        .argv = &.{ "git", "ls-remote", "--tags", "--refs", url },
        .environ_map = env,
    }) catch return .unreachable_;
    switch (res.term) {
        .exited => |c| if (c != 0) return .unreachable_,
        else => return .unreachable_,
    }

    var best: ?[]const u8 = null;
    var best_ver: Ver = .{};
    var lines = std.mem.splitScalar(u8, res.stdout, '\n');
    while (lines.next()) |line| {
        const marker = "refs/tags/";
        const idx = std.mem.indexOf(u8, line, marker) orelse continue;
        const tag = std.mem.trim(u8, line[idx + marker.len ..], " \t\r");
        if (tag.len == 0) continue;
        const v = Ver.parse(tag);
        if (best == null or v.order(best_ver) == .gt) {
            best = allocator.dupe(u8, tag) catch continue;
            best_ver = v;
        }
    }
    return if (best) |b| .{ .tag = b } else .none;
}

/// Kernel root (the dir holding composer.json + install.sh) from the resolved
/// CLI path `<root>/bin/hkm`.
fn kernelRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap) ?[]const u8 {
    const r = kernel.resolve(allocator, io, env) catch return null;
    const bin = std.fs.path.dirname(r.path) orelse return null; // <root>/bin
    return std.fs.path.dirname(bin); // <root>
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var check_only = false;
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--check") or std.mem.eql(u8, a, "-c")) check_only = true;
    }

    banner.print();

    const current = Ver.parse(banner.version());

    prompt.muted("checking for updates…");
    const latest = switch (latestTag(allocator, io, env)) {
        .tag => |t| t,
        .none => {
            prompt.item("repo", banner.repo());
            prompt.ok("no releases published yet — nothing to update to.");
            return 0;
        },
        .unreachable_ => {
            prompt.warn("could not reach the update server (offline or git missing).");
            prompt.item("repo", banner.repo());
            return 1;
        },
    };
    const latest_ver = Ver.parse(latest);

    prompt.item("installed", banner.version());
    prompt.item("latest", latest);

    switch (current.order(latest_ver)) {
        .eq => {
            prompt.ok("you are on the latest version.");
            return 0;
        },
        .gt => {
            prompt.ok("your version is newer than the latest release (dev build).");
            return 0;
        },
        .lt => {
            prompt.warn("an update is available.");
        },
    }

    if (check_only) {
        prompt.item("to update", "run: hkm upgrade");
        return 0;
    }

    // Perform the update.
    const root = kernelRoot(allocator, io, env) orelse {
        prompt.err("could not locate the kernel install (set HKM_KERNEL_HOME).");
        return 1;
    };
    const git_dir = try std.fs.path.join(allocator, &.{ root, ".git" });

    if (util.fileExists(io, git_dir)) {
        // Git checkout install → pull + re-resolve composer deps.
        prompt.section("Updating (git)");
        var pull = [_][]const u8{ "git", "-C", root, "pull", "--ff-only", "--tags" };
        _ = run_cmd.spawnWait(io, env, &pull) catch {};
        const installer = try std.fs.path.join(allocator, &.{ root, "install.sh" });
        if (util.fileExists(io, installer)) {
            var sh = [_][]const u8{ "sh", installer };
            _ = run_cmd.spawnWait(io, env, &sh) catch {};
        }
        prompt.ok("kernel updated. Verify with: hkm doctor");
        return 0;
    }

    // Packaged install (.deb/.app/zip): guide the reinstall from the release.
    prompt.section("Update a packaged install");
    prompt.item("download", try std.fmt.allocPrint(allocator, "https://github.com/{s}/releases/latest", .{banner.repo()}));
    prompt.item("Linux (.deb)", "sudo apt install ./hkm-kernel_<version>_amd64.deb");
    prompt.item("macOS/Windows", "extract the archive, then run install.sh / install.bat");
    prompt.item("note", "packaged installs are replaced by reinstalling the newer artifact");
    return 0;
}
