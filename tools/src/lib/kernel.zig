//! Kernel location resolution shared by the launcher passthrough (main.zig) and
//! `hkm doctor`. Given the environment, returns the path to the kernel's PHP CLI
//! (`<kernel>/bin/hkm`) that the launcher invokes as `php <cli> …`.

const std = @import("std");
const util = @import("util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// How the kernel CLI path was determined — surfaced by `hkm doctor`.
pub const Source = enum { cli_path_env, kernel_home_env, self_located, default };

pub const Resolved = struct {
    path: []const u8,
    source: Source,
    /// true when the resolved path actually exists on disk.
    exists: bool,
};

fn envGet(allocator: std.mem.Allocator, map: *EnvMap, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
}

/// Resolve the kernel PHP CLI path with full provenance (for diagnostics).
pub fn resolve(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !Resolved {
    // 1. Explicit overrides always win.
    if (try envGet(allocator, env, "HKM_CLI_PATH")) |v| {
        return .{ .path = v, .source = .cli_path_env, .exists = util.fileExists(io, v) };
    }
    if (try envGet(allocator, env, "HKM_KERNEL_HOME")) |home| {
        const p = try std.fs.path.join(allocator, &.{ home, "bin", "hkm" });
        return .{ .path = p, .source = .kernel_home_env, .exists = util.fileExists(io, p) };
    }

    // 2. Self-locate the kernel RELATIVE to this launcher's own executable, so a
    //    portable/zip/.app install needs no env var. Candidates cover every
    //    bundle layout produced by tools/bundle.sh:
    //      macOS .app:  <dir>/hkm  +  ../Resources/opt/hkm-kernel/bin/hkm
    //      Windows zip: <dir>/hkm.exe + hkm-kernel/bin/hkm
    //      portable:    <dir>/hkm  +  ../opt/hkm-kernel/bin/hkm
    if (std.process.executableDirPathAlloc(io, allocator)) |dir| {
        const rels = [_][]const []const u8{
            &.{ dir, "..", "Resources", "opt", "hkm-kernel", "bin", "hkm" },
            &.{ dir, "hkm-kernel", "bin", "hkm" },
            &.{ dir, "..", "opt", "hkm-kernel", "bin", "hkm" },
            &.{ dir, "..", "lib", "hkm-kernel", "bin", "hkm" },
        };
        for (rels) |parts| {
            const cand = try std.fs.path.join(allocator, parts);
            if (util.fileExists(io, cand)) {
                return .{ .path = cand, .source = .self_located, .exists = true };
            }
        }
    } else |_| {}

    // 3. Default for a system package install (Linux .deb → /opt/hkm-kernel).
    const def = try std.fs.path.join(allocator, &.{ "/opt", "hkm-kernel", "bin", "hkm" });
    return .{ .path = def, .source = .default, .exists = util.fileExists(io, def) };
}

/// Convenience wrapper used by the launcher passthrough — just the path.
pub fn findCliPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap) ![]const u8 {
    return (try resolve(allocator, io, env)).path;
}

pub fn sourceLabel(s: Source) []const u8 {
    return switch (s) {
        .cli_path_env => "HKM_CLI_PATH override",
        .kernel_home_env => "HKM_KERNEL_HOME override",
        .self_located => "self-located (relative to launcher)",
        .default => "default (/opt/hkm-kernel)",
    };
}
