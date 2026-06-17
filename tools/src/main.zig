const std = @import("std");

fn printHelp() void {
    std.debug.print(
        "\nhkm launcher (zig)\n" ++
            "Usage:\n" ++
            "  hkm new <path> [--project=<name>]\n" ++
            "  hkm doctor\n" ++
            "  hkm help\n" ++
            "\nEnvironment:\n" ++
            "  HKM_PHP_BIN         override php binary (default: php)\n" ++
            "  HKM_CLI_PATH        override target php CLI script\n" ++
            "  HKM_GLOBAL_AUTOLOAD override global autoload path\n\n",
        .{},
    );
}

fn envGet(allocator: std.mem.Allocator, map: *std.process.Environ.Map, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
}

fn findCliPath(allocator: std.mem.Allocator, env_map: *std.process.Environ.Map) ![]const u8 {
    if (try envGet(allocator, env_map, "HKM_CLI_PATH")) |v| {
        return v;
    }

    if (try envGet(allocator, env_map, "HKM_KERNEL_HOME")) |kernel_home| {
        return try std.fmt.allocPrint(allocator, "{s}/bin/hkm", .{kernel_home});
    }

    // Default for packaged installs (Linux/macOS)
    return try std.fmt.allocPrint(allocator, "/opt/hkm-kernel/bin/hkm", .{});
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
    const io = std.Io.Threaded.global_single_threaded.io();
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

    const php = try phpBin(allocator, &env_map);
    const cli = try findCliPath(allocator, &env_map);

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
