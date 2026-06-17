const std = @import("std");

pub fn build(b: *std.Build) void {
    const target = b.standardTargetOptions(.{});
    const optimize = b.standardOptimizeOption(.{});

    const launcher = b.addExecutable(.{
        .name = "hkm",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/main.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });
    b.installArtifact(launcher);

    const config_tool = b.addExecutable(.{
        .name = "hkm-config",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/config.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });
    b.installArtifact(config_tool);

    const run_launcher = b.addRunArtifact(launcher);

    const run_step = b.step("run", "Run hkm launcher");
    run_step.dependOn(&run_launcher.step);
}
