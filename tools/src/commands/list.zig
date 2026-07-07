//! `hkm list` — show every project registered in the kernel registry.
//!
//!   hkm list          # list registered projects (name, path, domains)
//!   hkm ls            # alias

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    _ = args;

    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.err("Kernel registry not found. Set PSP_PROJECTS_DIR or HKM_KERNEL_HOME.");
        return 1;
    };

    const entries = try registry.list(allocator, io, jsonPath);

    prompt.intro("hkm projects");
    if (entries.len == 0) {
        prompt.muted("No projects registered yet.");
        prompt.note("Scaffold one with:  hkm new <path>");
        prompt.outro(try std.fmt.allocPrint(allocator, "registry: {s}", .{jsonPath}));
        return 0;
    }

    for (entries) |e| {
        prompt.item(e.name, e.path);
        if (e.domains.len > 0) {
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{try util.joinList(allocator, e.domains)}));
        }
    }
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} project(s)  ·  {s}", .{ entries.len, jsonPath }));
    return 0;
}
