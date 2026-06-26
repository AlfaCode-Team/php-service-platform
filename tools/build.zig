const std = @import("std");

pub fn build(b: *std.Build) void {
    const target = b.standardTargetOptions(.{});
    // A plain `zig build` is Debug (~13MB of debug info — fine for hacking).
    // For distribution build a release: `zig build --release=small` → ~230KB,
    // `--release=safe` → ~4MB (keeps runtime safety checks), `--release=fast`.
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

    // `zig build docs` — generate the .tex from the Markdown single source, then
    // typeset it into a PDF. Requires python3 + a LaTeX toolchain (pdflatex).
    // Run pdflatex twice so the TOC resolves, then prune the LaTeX aux files.
    // Opt-in: a plain `zig build` does not run it.
    const docs_dir = b.path("docs");

    // 1. Markdown → LaTeX (single source of truth is hkm-cli-usage.md).
    const gen_tex = b.addSystemCommand(&.{ "python3", "build-docs.py" });
    gen_tex.setCwd(docs_dir);

    const pdf_pass1 = b.addSystemCommand(&.{ "pdflatex", "-interaction=nonstopmode", "-halt-on-error", "hkm-cli-usage.tex" });
    pdf_pass1.setCwd(docs_dir);
    pdf_pass1.step.dependOn(&gen_tex.step);

    const pdf_pass2 = b.addSystemCommand(&.{ "pdflatex", "-interaction=nonstopmode", "-halt-on-error", "hkm-cli-usage.tex" });
    pdf_pass2.setCwd(docs_dir);
    pdf_pass2.step.dependOn(&pdf_pass1.step);

    const docs_clean = b.addSystemCommand(&.{
        "rm", "-f",
        "hkm-cli-usage.aux", "hkm-cli-usage.log", "hkm-cli-usage.out", "hkm-cli-usage.toc",
    });
    docs_clean.setCwd(docs_dir);
    docs_clean.step.dependOn(&pdf_pass2.step);

    const docs_step = b.step("docs", "Build the CLI usage PDF (needs pdflatex)");
    docs_step.dependOn(&docs_clean.step);
}
