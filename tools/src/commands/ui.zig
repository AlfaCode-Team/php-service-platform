//! `hkm ui` — federate enabled plugins' UIs into a project's frontend.
//!
//!   hkm ui [sync] [path|name]        mirror every enabled plugin's ui/ + glue
//!   hkm ui list [path|name]          show enabled plugins that ship a UI
//!   hkm ui link <plugin> [path|name] symlink a plugin ui/ for live co-dev
//!   hkm ui unlink <plugin> [path]    drop the symlink, restore a copied mirror
//!   hkm ui clean [path|name]         remove all generated mirrors + glue
//!
//! A plugin OWNS its UI under `plugins/<Name>/ui/` (independently developed and
//! tested there). A project ACTIVATES that UI only while the plugin is enabled in
//! its bootstrap. This command copies a read-only mirror into
//! `frontend/plugins/<slug>/` and regenerates the registry barrel, manifest and
//! tsconfig path aliases — deterministic, project-over-plugin, never touching the
//! project's own frontend source.

const std = @import("std");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");
const ui = @import("../lib/plugin_ui.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const UiPlugin = ui.UiPlugin;

const Action = enum { sync, list, link, unlink, clean, init };

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var action: Action = .sync;
    var force = false;
    var saw_action = false;

    var operands: std.ArrayList([]const u8) = .empty;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        } else if (std.mem.eql(u8, a, "--force") or std.mem.eql(u8, a, "-f")) {
            force = true;
        } else if (a.len > 0 and a[0] == '-') {
            // unknown flag — ignore
        } else if (!saw_action and operands.items.len == 0 and actionFromWord(a) != null) {
            action = actionFromWord(a).?;
            saw_action = true;
        } else {
            try operands.append(allocator, a);
        }
    }

    const ops = operands.items;
    switch (action) {
        .sync => return sync(allocator, io, env, op(ops, 0), force),
        .list => return list(allocator, io, env, op(ops, 0)),
        .init => return initCmd(allocator, io, env, op(ops, 0), force),
        .clean => return cleanCmd(allocator, io, env, op(ops, 0)),
        .link, .unlink => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm ui link|unlink <plugin> [path|name]");
                return 2;
            }
            return linkCmd(allocator, io, env, action, op(ops, 0), op(ops, 1));
        },
    }
}

fn op(l: []const []const u8, n: usize) []const u8 {
    return if (n < l.len) l[n] else "";
}

fn actionFromWord(a: []const u8) ?Action {
    if (std.mem.eql(u8, a, "init") or std.mem.eql(u8, a, "scaffold") or std.mem.eql(u8, a, "new")) return .init;
    if (std.mem.eql(u8, a, "sync") or std.mem.eql(u8, a, "build") or std.mem.eql(u8, a, "publish")) return .sync;
    if (std.mem.eql(u8, a, "list") or std.mem.eql(u8, a, "ls")) return .list;
    if (std.mem.eql(u8, a, "link")) return .link;
    if (std.mem.eql(u8, a, "unlink")) return .unlink;
    if (std.mem.eql(u8, a, "clean") or std.mem.eql(u8, a, "clear")) return .clean;
    return null;
}

fn requireRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !?[]const u8 {
    return (try services.resolveRoot(allocator, io, env, target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (target.len == 0) "." else target},
        ));
        return null;
    };
}

// ── sync ──────────────────────────────────────────────────────────────────────

fn sync(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, force: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;
    prompt.intro("hkm ui sync");

    var plugins: std.ArrayList(UiPlugin) = .empty;
    try ui.discover(allocator, io, env, root, &plugins);

    if (plugins.items.len == 0) {
        prompt.note("No enabled plugin ships a ui/ folder. Nothing to federate.");
        try ui.writeGlue(allocator, io, root, plugins.items);
        prompt.outro("Wrote empty registry (frontend/plugins/).");
        return 0;
    }

    var files: usize = 0;
    var linked: usize = 0;
    for (plugins.items) |p| {
        if (p.linked) {
            linked += 1;
            prompt.ok(try std.fmt.allocPrint(allocator, "{s}  →  {s} (linked, live)", .{ p.name, p.alias }));
            continue;
        }
        const n = try ui.syncPlugin(allocator, io, root, p, force);
        files += n;
        prompt.ok(try std.fmt.allocPrint(allocator, "{s}  →  {s}  ({d} file{s})", .{
            p.name, p.alias, n, if (n == 1) "" else "s",
        }));
    }

    try ui.writeGlue(allocator, io, root, plugins.items);

    prompt.blank();
    prompt.muted(try std.fmt.allocPrint(
        allocator,
        "mirrored {d} file(s) across {d} plugin(s){s} → frontend/plugins/",
        .{ files, plugins.items.len, if (linked > 0) " (some linked)" else "" },
    ));
    prompt.item("frontend/plugins/index.ts", "generated registry barrel");
    prompt.item("frontend/plugins/manifest.json", "machine-readable inventory");
    prompt.item("frontend/tsconfig.plugins.json", "path aliases for TS/bundler");
    prompt.outro("Extend your vite/tsconfig paths from tsconfig.plugins.json.");
    return 0;
}

// ── list ──────────────────────────────────────────────────────────────────────

fn list(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;
    prompt.intro("hkm ui list");

    var plugins: std.ArrayList(UiPlugin) = .empty;
    try ui.discover(allocator, io, env, root, &plugins);

    if (plugins.items.len == 0) {
        prompt.note("No enabled plugin ships a ui/ folder.");
        return 0;
    }

    for (plugins.items) |p| {
        const state = if (p.linked) "linked" else "copy ";
        prompt.item(
            try std.fmt.allocPrint(allocator, "{s} [{s}]", .{ p.name, state }),
            try std.fmt.allocPrint(allocator, "{s}  ({s}, v{s})", .{ p.alias, p.framework, p.version }),
        );
    }
    prompt.outro("Run `hkm ui sync` to (re)generate the project mirror + glue.");
    return 0;
}

// ── link / unlink ───────────────────────────────────────────────────────────

fn linkCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, action: Action, plugin: []const u8, target: []const u8) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    var plugins: std.ArrayList(UiPlugin) = .empty;
    try ui.discover(allocator, io, env, root, &plugins);

    var found: ?UiPlugin = null;
    for (plugins.items) |p| {
        if (util.eqlIgnoreCase(p.name, plugin) or util.eqlIgnoreCase(p.slug, plugin)) {
            found = p;
            break;
        }
    }
    const p = found orelse {
        prompt.err(try std.fmt.allocPrint(allocator, "'{s}' is not an enabled UI plugin in this project.", .{plugin}));
        return 1;
    };

    if (action == .link) {
        if (try ui.linkPlugin(allocator, io, root, p)) {
            prompt.ok(try std.fmt.allocPrint(allocator, "linked frontend/plugins/{s} → {s}", .{ p.slug, p.uiDir }));
        } else {
            prompt.warn("symlink unsupported here — falling back to a copied mirror.");
            _ = try ui.syncPlugin(allocator, io, root, p, true);
        }
    } else {
        if (try ui.unlinkPlugin(allocator, io, root, p.slug)) {
            const n = try ui.syncPlugin(allocator, io, root, p, true);
            prompt.ok(try std.fmt.allocPrint(allocator, "unlinked {s}; restored copied mirror ({d} file(s)).", .{ p.slug, n }));
        } else {
            prompt.note("Not linked — nothing to unlink.");
        }
    }

    // Refresh linked-state + glue.
    var refreshed: std.ArrayList(UiPlugin) = .empty;
    try ui.discover(allocator, io, env, root, &refreshed);
    try ui.writeGlue(allocator, io, root, refreshed.items);
    return 0;
}

// ── init ──────────────────────────────────────────────────────────────────────

fn initCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, force: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;
    prompt.intro("hkm ui init");

    var written: usize = 0;
    const ok = ui.initFrontend(allocator, io, env, root, force, &written) catch false;
    if (!ok) {
        prompt.err("Could not locate the frontend template (set HKM_KERNEL_HOME or HKM_TEMPLATES_DIR).");
        return 1;
    }

    if (written == 0) {
        prompt.note("frontend/ already scaffolded — nothing new written (use --force to overwrite).");
    } else {
        prompt.ok(try std.fmt.allocPrint(allocator, "scaffolded frontend/ ({d} file(s))", .{written}));
    }

    // Immediately federate any enabled plugin UIs so the project builds.
    var plugins: std.ArrayList(UiPlugin) = .empty;
    try ui.discover(allocator, io, env, root, &plugins);
    for (plugins.items) |p| {
        if (p.linked) continue;
        _ = try ui.syncPlugin(allocator, io, root, p, false);
        prompt.ok(try std.fmt.allocPrint(allocator, "federated {s} → {s}", .{ p.name, p.alias }));
    }
    try ui.writeGlue(allocator, io, root, plugins.items);

    prompt.blank();
    prompt.section("Next");
    prompt.item("cd frontend && npm install", "install project frontend deps");
    prompt.item("npm run dev", "start the vite dev server (surface: admin)");
    prompt.item("hkm ui sync", "re-federate plugin UIs after enabling a plugin");
    prompt.outro("Add an app: copy src/surfaces/admin → src/surfaces/<name>.");
    return 0;
}

// ── clean ─────────────────────────────────────────────────────────────────────

fn cleanCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;
    try ui.clean(allocator, io, root);
    prompt.ok("removed frontend/plugins/ and tsconfig.plugins.json");
    return 0;
}

fn printHelp() void {
    prompt.intro("hkm ui");
    prompt.section("Usage");
    prompt.item("hkm ui init [path|name]", "scaffold frontend/ from the template + federate UIs");
    prompt.item("hkm ui [sync] [path|name]", "mirror enabled plugins' ui/ + regenerate glue");
    prompt.item("hkm ui list [path|name]", "list enabled plugins that ship a UI");
    prompt.item("hkm ui link <plugin> [path]", "symlink a plugin ui/ for live co-development");
    prompt.item("hkm ui unlink <plugin> [path]", "drop the symlink, restore a copied mirror");
    prompt.item("hkm ui clean [path|name]", "remove all generated mirrors + glue");
    prompt.blank();
    prompt.section("Flags");
    prompt.item("--force, -f", "overwrite even a linked mirror on sync");
    prompt.blank();
    prompt.section("Convention");
    prompt.item("plugins/<Name>/ui/", "the plugin owns its UI (dev + test in place)");
    prompt.item("plugins/<Name>/ui/ui.json", "optional: alias, entry, framework overrides");
    prompt.item("frontend/plugins/<slug>/", "generated project mirror (do not edit)");
    prompt.outro("Federation activates only for plugins enabled in the bootstrap.");
}
