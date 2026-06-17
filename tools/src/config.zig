const std = @import("std");

fn printHelp() void {
    std.debug.print(
        "hkm-config\\n" ++
            "Usage:\\n" ++
            "  hkm-config print\\n" ++
            "  hkm-config set-kernel-home <path>\\n" ++
            "  hkm-config set-autoload <path-to-vendor/autoload.php>\\n",
        .{},
    );
}

fn configPath(allocator: std.mem.Allocator, env_map: *std.process.Environ.Map) ![]const u8 {
    if (env_map.get("HOME")) |home| {
        return try std.fmt.allocPrint(allocator, "{s}/.config/hkm/config.env", .{home});
    }
    return error.MissingHome;
}

fn ensureConfigDir(path: []const u8) !void {
    const dir = std.fs.path.dirname(path) orelse return;
    const io = std.Io.Threaded.global_single_threaded.io();
    try std.Io.Dir.createDirPath(std.Io.Dir.cwd(), io, dir);
}

pub fn main(init: std.process.Init.Minimal) !void {
    var arena_allocator: std.heap.ArenaAllocator = .init(std.heap.page_allocator);
    defer arena_allocator.deinit();
    const allocator = arena_allocator.allocator();
    var env_map = try init.environ.createMap(allocator);
    defer env_map.deinit();

    const args = try init.args.toSlice(allocator);

    if (args.len < 2) {
        printHelp();
        return;
    }

    const action = args[1];
    const cfg = try configPath(allocator, &env_map);

    if (std.mem.eql(u8, action, "print")) {
        std.debug.print("{s}\\n", .{cfg});
        return;
    }

    if (args.len < 3) {
        printHelp();
        return;
    }

    const value = args[2];
    try ensureConfigDir(cfg);

    const io = std.Io.Threaded.global_single_threaded.io();
    var file = try std.Io.Dir.createFile(std.Io.Dir.cwd(), io, cfg, .{ .truncate = true });
    defer file.close(io);
    var buffer: [512]u8 = undefined;
    var writer = file.writer(io, &buffer);

    if (std.mem.eql(u8, action, "set-kernel-home")) {
        try writer.interface.writeAll("HKM_KERNEL_HOME=");
        try writer.interface.writeAll(value);
        try writer.interface.writeAll("\n");
        try writer.flush();
        return;
    }

    if (std.mem.eql(u8, action, "set-autoload")) {
        try writer.interface.writeAll("HKM_GLOBAL_AUTOLOAD=");
        try writer.interface.writeAll(value);
        try writer.interface.writeAll("\n");
        try writer.flush();
        return;
    }

    printHelp();
}
