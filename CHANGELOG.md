# Changelog

## Unreleased

- Public GitHub copy is now published from release snapshots.

- **GitHub publishing enabled** (`MMWP_PUBLISH_ON_MAIN`); screenshot tooling docs moved to `docs/maintainers/screenshots.md`, and `.publishignore` keeps maintainer files out of the GitHub mirror. No Version / Stable tag change.

- **Slug corrected to the one wordpress.org assigned: `mammoth-acceptance-logs`.** Correction of
  the unreleased 1.0.0 (Version and Stable tag unchanged). Main file, text domain (PHP, JS,
  `block.json`, phpcs), `MMWP_SLUG`, `.pot` (now `languages/mammoth-acceptance-logs.pot`),
  privacy exporter/eraser keys and wordpress.org links use the new slug. Display name and all
  stored option, meta, table, cron and hook names (`mmwp_...`) are unchanged. `.distignore`
  root-only entries are now anchored with a leading `/`.
- **`.wordpress-org/` holds only wordpress.org assets.** It is synced 1:1 to SVN `/assets`, so
  `assets.conf` and the `src/` sources (incl. the GitHub social preview) moved to
  `docs/brand-assets/` (excluded from the zip); `bin/build-assets.sh` reads them from there.
- **Docs-only:** removed internal working notes from the public tree (untracked and git-ignored; no release).
- **wordpress.org pre-review fixes.** (1) The inline `<style>` blocks in the PDF-only views
  `certificate.php` and `terms-snapshot.php` are kept (dompdf input, never a WordPress page) and
  now say why. (2) `readme.txt` no longer claims a declined version is recorded: "skip for now"
  only sets a 12-hour transient and fires `mmwp_terms_skipped`; also corrected the receipt
  recipient claim and a dangling "Behind a proxy or CDN" reference. (3) Admin notices: removed
  the composer/dompdf notice shown to plugin administrators from `autoload-fallback.php`, and
  confined the manager setup warning to the plugin's own settings screen; the only notice
  elsewhere is the acceptance prompt for in-scope users who must accept. (4) README.md gains a
  "Download and install" section.
- **wordpress.org screenshots.** Added `.wordpress-org/screenshot-1..4.png` and the dev-only
  `bin/screenshots/` tooling (seed + Playwright capture) to regenerate them; both are
  excluded from the zip. No plugin code changed.
- **Rename to the MammothWP naming standard.** Display name is now
  "MammothWP Acceptance Logs"; slug, plugin folder, main file
  (`mammothwp-acceptance-logs.php`), zip, text domain and `MMWP_SLUG` are
  `mammothwp-acceptance-logs`. Stored option, meta, table, cron and hook names,
  the PHP namespace, the `mmwp_` prefixes and the Composer package name are
  unchanged. The uninstall confirmation phrase is now "MammothWP Acceptance
  Logs"; the previous phrase "Mammoth Acceptance Logs" is still accepted.
  Plugin Check's `trademarked_term` warnings for "wp" in "MammothWP" are
  allowed by the shared CI template (`MMWP_PCP_ALLOW`). Dev dependencies
  updated (`mammothwp/wp-release` with `--allow` support, support, wp-kit,
  wp-self-test). The rename deactivates the plugin on existing installs
  (the folder changes); reactivate it.
- **Fix: Settings screen fatal** (`Class "UninstallPolicy" not found`). Views have
  no namespace, so the unqualified reference resolved globally. `SettingsPage`
  and `AcceptanceLogPage` now pass everything the views need (including the
  uninstall choices and confirmation phrase, and the log page slug); views no
  longer call domain classes. Audited every file under `src/*/views/`.
- **Tests: admin screens are rendered.** New `Admin\ScreenProbe` renders the
  settings and acceptance-log screens through their page hooks under output
  buffering; used by a PHPUnit suite and by the `wp mmwp self-test` smoke case.
- **Static analysis now covers views** (`src/*/views/*` is no longer excluded
  from PHPStan), so an undefined class there fails CI.
- CI: Plugin Check runs via mmwp-release in the shared template.

## 1.0.0 — first wordpress.org release

Rebranded and rebuilt to the Mammoth WP standard; earlier internal builds are
migrated automatically.

- **Identity.** "Mammoth Acceptance Logs", slug and text domain
  `mammoth-acceptance-logs`, namespace `MammothWP\AcceptanceLogs`, Composer
  package `mammothwp/acceptance-logs`. The slug is the `MMWP_ACCEPTANCE_LOGS_SLUG`
  constant.
- **Prefixes.** All globals use `mmwp_`; CSS and handles `mmwp-`; REST namespace
  `mmwp/v1`; WP-CLI `wp mmwp terms`; tables `{prefix}mmwp_terms_*`.
- **Migration.** `Upgrade\Upgrader` (revision in `mmwp_terms_upgrade`) renames
  tables in place, moves options, user meta and block names, clears old cron
  hooks. Signatures are untouched. Old `wp-config.php` constants keep working.
- **Uninstall asks, default keeps.** Deleting the plugin from the Plugins screen
  opens an accessible dialog: keep the acceptance evidence (recommended) or
  delete it permanently (plugin name must be typed). The choice is the
  `mmwp_terms_uninstall_data` option, also editable in Settings, and read by
  `uninstall.php`; the filter `mmwp_terms_drop_data_on_uninstall` overrides it.
  Settings are always removed; the signing key goes only with the evidence.
  `wp mmwp terms purge [--dry-run] [--force]` deletes evidence on demand
  (reports only without `--force`).
- **Support.** Bundles `mammothwp/support`: the shared Mammoth WP → Support
  screen, recipient in `mammothwp-support.json`. Nothing is sent without a click;
  the site report is opt-in and previewed.
- **wp-kit.** REST, WP-CLI and the Abilities API are declared once
  (`Api\TermsResource`); added a read-only, redacted, manager-gated
  `list-acceptances` operation. Replaces the hand-written REST controller and
  CLI command. `wp ... publish` takes `--content`; `--file` / STDIN was dropped.
  The old `mmwp_terms_rest_can_*` filters are replaced by wp-kit's
  `mmwp_kit_operation_capability`.
- **wp-self-test 2.0** (`wp mmwp self-test <slug>`). In-site smoke suite (`self-test/manifest.json`) and a
  PHPUnit suite with `bin/install-wp-tests.sh`.
- **Admin.** Inline styles moved to `assets/css/admin.css`.
- **Tooling.** `phpcs.xml.dist`, `phpstan.neon.dist` (level 6), `.gitlab-ci.yml`
  using the shared plugin pipeline, `.distignore`, `mmwp-release build`.
- **Docs.** `docs/` (index, usage, hooks, privacy), `HOOKS.md`, new `readme.txt`
  and `README.md`, brand assets in `.wordpress-org/`.
