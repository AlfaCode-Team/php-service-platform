//! Sentinel ASCII banner + version header, shared by `hkm --version` and the
//! update commands. Kept dependency-free (just std.debug.print + ANSI).

const std = @import("std");
const build_info = @import("build_info");

const cyan = "\x1b[36m";
const bold = "\x1b[1m";
const dim = "\x1b[2m";
const reset = "\x1b[0m";

/// The framework's kernel is "Sentinel" — see docs/ai-context/00_SENTINEL_OVERVIEW.
const art =
    \\  ██████╗ ███████╗███╗   ██╗████████╗██╗███╗   ██╗███████╗██╗
    \\  ██╔════╝ ██╔════╝████╗  ██║╚══██╔══╝██║████╗  ██║██╔════╝██║
    \\  ███████╗ █████╗  ██╔██╗ ██║   ██║   ██║██╔██╗ ██║█████╗  ██║
    \\  ╚════██║ ██╔══╝  ██║╚██╗██║   ██║   ██║██║╚██╗██║██╔══╝  ██║
    \\  ██████╔╝ ███████╗██║ ╚████║   ██║   ██║██║ ╚████║███████╗███████╗
    \\  ╚═════╝  ╚══════╝╚═╝  ╚═══╝   ╚═╝   ╚═╝╚═╝  ╚═══╝╚══════╝╚══════╝
;

pub fn version() []const u8 {
    return build_info.version;
}

pub fn repo() []const u8 {
    return build_info.repo;
}

/// Full banner: ASCII art + version + tagline. Used as the header of the
/// version/update commands.
pub fn print() void {
    std.debug.print("\n{s}{s}{s}\n", .{ cyan, art, reset });
    std.debug.print("  {s}Sentinel{s} {s}· PhpServicePlatform kernel{s}\n", .{ bold, reset, dim, reset });
    std.debug.print("  {s}version {s}{s}{s}\n\n", .{ dim, reset, build_info.version, reset });
}

/// One-line version, for `hkm --version` piped/scripted use.
pub fn printShort() void {
    std.debug.print("hkm (Sentinel) {s}\n", .{build_info.version});
}
