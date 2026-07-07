const std = @import("std");
const new_cmd = @import("commands/new.zig");
const update_cmd = @import("commands/update.zig");
const run_cmd = @import("commands/run.zig");
const list_cmd = @import("commands/list.zig");
const plugins_cmd = @import("commands/plugins.zig");
const ui_cmd = @import("commands/ui.zig");
const cli_cmd = @import("commands/cli.zig");
const doctor_cmd = @import("commands/doctor.zig");
const kernel = @import("lib/kernel.zig");
const prompt = @import("lib/prompt.zig");

fn printHelp() void {
    prompt.intro("hkm launcher");

    prompt.section("Usage");
    prompt.item("hkm new <path> [opts]", "scaffold a new PhpServicePlatform project");
    prompt.item("hkm run [path|name]", "run a project locally (PHP dev server)");
    prompt.item("hkm cli [command]", "run a project's console interactively");
    prompt.item("hkm worker [args]", "run a project's queue worker");
    prompt.item("hkm list", "list registered projects (alias: ls)");
    prompt.item("hkm plugins [path|name]", "analyse a project's enabled plugins/modules");
    prompt.item("hkm ui [sync|list|link|clean]", "federate enabled plugins' UIs into the frontend");
    prompt.item("hkm update <path|name>", "refresh a project's kernel registry entry");
    prompt.item("hkm doctor", "diagnose the local environment");
    prompt.item("hkm help", "show this help");
    prompt.blank();

    prompt.section("Environment");
    prompt.item("HKM_PHP_BIN", "override php binary (default: php)");
    prompt.item("HKM_CLI_PATH", "override target php CLI script");
    prompt.item("HKM_GLOBAL_AUTOLOAD", "override global autoload path");
    prompt.item("PSP_PROJECTS_DIR", "dir holding the kernel projects.json registry");
    prompt.item("HKM_KERNEL_HOME", "kernel root (registry at <root>/projects/projects.json)");

    prompt.outro("Run 'hkm <command> --help' for command details");
}

fn envGet(allocator: std.mem.Allocator, map: *std.process.Environ.Map, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
}

fn findCliPath(allocator: std.mem.Allocator, io: std.Io, env_map: *std.process.Environ.Map) ![]const u8 {
    return kernel.findCliPath(allocator, io, env_map);
}

fn phpBin(allocator: std.mem.Allocator, env_map: *std.process.Environ.Map) ![]const u8 {
    if (try envGet(allocator, env_map, "HKM_PHP_BIN")) |v| {
        return v;
    }
    return try allocator.dupe(u8, "php");
}

pub fn main(init: std.process.Init.Minimal) !void {
    var arena_allocator: std.heap.ArenaAllocator = .init(std.heap.page_allocator);
    defer arena_allocator.deinit();
    const allocator = arena_allocator.allocator();
    // global_single_threaded uses a `.failing` allocator, which makes
    // std.process.spawn OOM (it allocates argv/env before fork). Use a real
    // allocator-backed Threaded io so spawning child processes works.
    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();
    var env_map = try init.environ.createMap(allocator);
    defer env_map.deinit();

    const args = try init.args.toSlice(allocator);

    if (args.len <= 1) {
        printHelp();
        return;
    }

    const cmd = args[1];
    if (std.mem.eql(u8, cmd, "help") or std.mem.eql(u8, cmd, "--help") or std.mem.eql(u8, cmd, "-h")) {
        printHelp();
        return;
    }

    // `new` / `update` are handled natively in Zig (no PHP required).
    if (std.mem.eql(u8, cmd, "new")) {
        const code = try new_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "update")) {
        const code = try update_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "run") or std.mem.eql(u8, cmd, "serve")) {
        const code = try run_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "list") or std.mem.eql(u8, cmd, "ls")) {
        const code = try list_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "plugins") or std.mem.eql(u8, cmd, "modules")) {
        const code = try plugins_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "ui")) {
        const code = try ui_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "cli")) {
        const code = try cli_cmd.run(allocator, io, &env_map, args, false);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "worker")) {
        const code = try cli_cmd.run(allocator, io, &env_map, args, true);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "doctor")) {
        const code = try doctor_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }

    const php = try phpBin(allocator, &env_map);
    const cli = try findCliPath(allocator, io, &env_map);

    var child_argv: std.ArrayList([]const u8) = .empty;
    defer child_argv.deinit(allocator);

    try child_argv.append(allocator, php);
    try child_argv.append(allocator, cli);

    // pass-through remaining arguments
    var i: usize = 1;
    while (i < args.len) : (i += 1) {
        try child_argv.append(allocator, args[i]);
    }

    var child = try std.process.spawn(io, .{
        .argv = child_argv.items,
        .environ_map = &env_map,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    });

    const term = try child.wait(io);
    switch (term) {
        .exited => |code| std.process.exit(code),
        else => std.process.exit(1),
    }
}
