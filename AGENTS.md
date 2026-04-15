# Repository Guidelines

## Project Structure & Module Organization
`balmo.php` is the plugin bootstrap: it defines constants, registers the autoloader, creates database tables, and wires WordPress hooks. Keep PHP classes under `includes/` with PSR-4 style paths that match the `Balmo\...` namespace. Use `includes/Admin/` for wp-admin screens, `includes/Service/` for CSV import and mapping logic, and `includes/Cron/` for scheduled import flow. Store admin-only frontend assets in `assets/` (`admin.js`, `admin.css`).

## Build, Test, and Development Commands
This plugin does not expose Composer, npm, or PHPUnit scripts in the current checkout. Use targeted local checks instead:

```powershell
php -l .\balmo.php
php -l .\includes\Service\ProductImporter.php
```

Run the plugin inside a local WordPress + WooCommerce install and validate from the Balmo admin screens. After changing cron or batch logic, test both manual import and scheduled import paths.

## Coding Style & Naming Conventions
Follow the existing PHP style: 4-space indentation, braces on the next line for classes/functions, typed properties where supported, and early returns for guard clauses. Class names use `PascalCase`; methods use `camelCase`; constants use `UPPER_SNAKE_CASE`. Keep WordPress capability checks, sanitization, escaping, and translation helpers (`__()`, `esc_html__()`) in place. Match new file names to their class names, for example `includes/Service/CsvFetcher.php`.

## Testing Guidelines
There is no automated test suite here, so rely on syntax checks and manual verification. Exercise CSV upload, column mapping, formula handling, product create/update behavior, and log/history views. For scheduler changes, verify WP-Cron registration and that import batches advance without leaving rows stuck in `pending` or `running`.

## Commit & Pull Request Guidelines
Git history is not available in this plugin folder, so no local commit convention can be inferred. Use short, imperative commit subjects such as `Add CSV delimiter validation` and keep each commit scoped to one change. PRs should summarize the user-visible behavior, note any database or cron impact, include manual test steps, and add screenshots for admin UI changes.

## Security & Configuration Tips
Treat CSV URLs, uploaded files, and mapped values as untrusted input. Sanitize on input, escape on output, and preserve capability checks around admin actions. Do not commit environment-specific paths, secrets, or exported customer/product data.
