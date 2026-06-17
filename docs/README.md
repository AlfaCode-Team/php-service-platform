# HKMCODE Documentation Index

This project keeps long-form reports and implementation notes under `docs/`.

## Core Context

- `docs/ai-context/` — architecture and AI context references
- `CLAUDE.md` — master project context for Claude
- `.github/copilot-instructions.md` — Copilot instruction set

## Reports

- `docs/reports/commands/` — commands architecture, analysis, implementation notes
- `docs/reports/database/` — database module docs, quickstart, checklists
- `docs/reports/enterprise/` — enterprise analysis and implementation artifacts
- `docs/reports/infrastructure/` — repository/infrastructure refactor notes
- `docs/reports/migrations/` — let-migrate related summaries

## Guides

- `docs/guides/SAFE_DEPLOYMENTS_GUIDE.md` — deployment safety runbooks

## Notes

- Root folder should stay focused on source/runtime files.
- New reports should be placed in the matching `docs/reports/*` area, not project root.

## Global Kernel Mode

The package can be installed as a native system runtime while projects live
anywhere on disk.

- Preferred: build and publish native artifacts via release tag:
  - `git tag v1.0.0 && git push origin v1.0.0`
  - CI workflow: `.github/workflows/release.yml`
- Install from artifacts (`.deb`/Windows zip/macOS app), then scaffold project anywhere:
  - `psp new /path/to/project --project=admin`
- Project-side bridge loader: `app/bootstrap/kernel-autoload.php`
- Optional Composer global install (dev workflow):
  - `composer global require alfacode-team/php-service-platform`

For full platform install steps and APT repository setup, see:

- `packaging/README.md`

Global autoload resolution order in generated projects:

1. `PSP_GLOBAL_AUTOLOAD` env var
2. local `vendor/autoload.php` (if present)
3. `COMPOSER_HOME/vendor/autoload.php`
4. Linux/macOS defaults (`~/.config/composer` then `~/.composer`)
5. Windows defaults (`%APPDATA%/Composer` then `%USERPROFILE%/.composer`)
