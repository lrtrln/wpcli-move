# Changelog

## 1.0.0

- Added push/pull support for WordPress core files with `--wp`.
- Added full-site sync with `--all`.
- Added `wp-content/languages` sync with `--languages`.
- Added `.env` interpolation for `move.yml`.
- Added local config defaults from common WordPress environment variables.
- Added `wp move init` to generate starter configuration.
- Added optional WP-CLI alias support and `init --wpcli-aliases`.
- Added WordPress version guards before core/full syncs.
- Added confirmation prompts and `--yes` for destructive operations.
- Added stronger documentation warnings for production use.

## 0.1.0

- Initial WP-CLI command for moving WordPress files and databases between environments.
