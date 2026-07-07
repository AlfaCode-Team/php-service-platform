# hkm — PhpServicePlatform CLI (Zig)

Native launcher + project tooling for the AlfacodeTeam PhpServicePlatform.
Builds two binaries: `hkm` (the launcher/CLI) and `hkm-config`.

## Layout

```
tools/
├── build.zig              # build graph (defines the hkm + hkm-config binaries)
├── .zig-version           # pinned Zig toolchain
└── src/
    ├── main.zig           # hkm entry: parses argv, dispatches to a command
    ├── config.zig         # hkm-config entry (separate binary)
    ├── commands/          # one file per subcommand — each exposes `run(...)`
    │   ├── new.zig        #   hkm new     — scaffold a project
    │   ├── run.zig        #   hkm run     — serve / swoole / cli / worker (+ --pick)
    │   ├── list.zig       #   hkm list    — list registered projects
    │   └── update.zig     #   hkm update  — refresh a registry entry
    ├── lib/               # shared modules used by the commands
    │   ├── prompt.zig     #   terminal UI: intro/note/select/text/confirm…
    │   ├── registry.zig   #   projects.json read / list / upsert
    │   └── util.zig       #   path / string / filesystem helpers
    └── templates/         # scaffolding templates (read at runtime, NOT embedded)
```

### Design

- **`main.zig` only routes.** It maps a command word to `commands/<cmd>.run(...)`
  and renders the root help. No business logic lives here.
- **Every command exposes the same entry signature** so dispatch stays uniform:
  ```zig
  pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap,
             args: []const []const u8) !u8   // returns the process exit code
  ```
- **`lib/` holds anything shared.** Commands import it via `../lib/<name>.zig`.
  Keep cross-command logic here, not duplicated across commands.
- **Templates are read from disk** (see `commands/new.zig` resolution order), so
  they can be edited without recompiling.

## Build

```sh
zig build                  # Debug (~13MB, full safety + debug info) — for dev
zig build --release=safe   # ~4MB, keeps runtime safety checks — recommended ship
zig build --release=small  # ~230KB, smallest (no safety checks)
```

Binaries land in `zig-out/bin/{hkm,hkm-config}`.

## Adding a command

1. Create `src/commands/<name>.zig` with the standard `run(...)` signature.
   Import shared helpers with `@import("../lib/prompt.zig")` /
   `@import("../lib/registry.zig")`.
2. In `src/main.zig`: add `const <name>_cmd = @import("commands/<name>.zig");`,
   a dispatch branch (`if (std.mem.eql(u8, cmd, "<name>")) ...`), and a
   `prompt.item(...)` line in `printHelp()`.
3. Give the command its own `printHelp()` shown on `--help` / bad args.
