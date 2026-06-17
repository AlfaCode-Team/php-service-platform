# php-service-platform

Documentation has been organized under [docs/README.md](docs/README.md).

Native global installation (Linux/macOS/Windows):

1. Create a release tag (CI builds installers):
   - `git tag v1.0.0 && git push origin v1.0.0`
2. Install from release artifacts:
   - Linux: `psp-kernel_<version>_amd64.deb`
   - Windows: `psp-kernel-<version>-windows-x86_64.zip`
   - macOS: `psp-kernel-<version>-macos-universal.tar.gz`
3. Scaffold and run a project anywhere:
   - `psp new /absolute/path/to/my-project --project=admin`
   - `php /absolute/path/to/my-project/app/cli/run.php list`

Notes:

- The native launcher reads kernel location from `PSP_KERNEL_HOME` or `PSP_CLI_PATH`.
- Optional override for generated project autoload: `PSP_GLOBAL_AUTOLOAD=/path/to/vendor/autoload.php`.
- Full packaging and install instructions: [packaging/README.md](packaging/README.md).

Composer-based global install remains supported for development:

- `composer global require alfacode-team/php-service-platform`

Native system installers (no Composer required for end users):

- Debian/Kali apt package scaffolding: `packaging/apt/`
- Windows `.exe` bundle scaffolding: `packaging/windows/`
- macOS `.app` bundle scaffolding: `packaging/macos/`
- Zig launcher/config utility: `tools/psp-launcher-zig/`

Key locations:

- Commands reports: [docs/reports/commands](docs/reports/commands)
- Database reports: [docs/reports/database](docs/reports/database)
- Enterprise reports: [docs/reports/enterprise](docs/reports/enterprise)
- Infrastructure reports: [docs/reports/infrastructure](docs/reports/infrastructure)
- Migrations reports: [docs/reports/migrations](docs/reports/migrations)
- Deployment guides: [docs/guides](docs/guides)
- AI context: [docs/ai-context](docs/ai-context)
