# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Balmo is a WordPress plugin for WooCommerce that automates bulk product imports from CSV files. It handles large catalogs (20,000+ products) via batch/async processing with scheduled recurring imports.

**Requirements:** PHP 7.4+, WordPress 5.0+, WooCommerce 4.0+, Action Scheduler (bundled with WooCommerce).

## Commands

No Composer, npm, or PHPUnit scripts. Syntax checks only:

```bash
php -l ./balmo.php
php -l ./includes/Service/ProductImporter.php
```

Testing is manual via a local WordPress + WooCommerce install. After changing cron or batch logic, verify both manual import and scheduled import paths through the Balmo admin screens.

## Architecture

**Entry point:** `balmo.php` — defines constants, PSR-4 autoloader (`Balmo\*` → `includes/`), DB schema on activation, and all WP hook registrations.

**Key constants (balmo.php):**
- `BALMO_BATCH_SIZE` = 200 rows/batch (full/sync imports)
- `BALMO_ASYNC_BATCH_SIZE` = 25 rows/batch (background/cron)
- `BALMO_BATCH_TIME_LIMIT` = 20 seconds
- `BALMO_HEARTBEAT_TTL` = 180 seconds (stall detection)

**Layer structure:**

```
includes/Admin/     — wp-admin screens + AJAX handlers
includes/Service/   — CSV fetch, validate, map, import logic
includes/Cron/      — WP-Cron schedule management
assets/             — admin.js (AJAX wizard + polling), admin.css
```

**Core classes:**
- `Balmo\Admin\ImportPage` — CSV upload/URL fetch wizard, preview, step-by-step AJAX flow
- `Balmo\Admin\MappingPage` — Column mapping profiles (stored as JSON in DB)
- `Balmo\Admin\SchedulerPage` — Recurring schedule CRUD
- `Balmo\Admin\HistoryPage` — Import log viewer with per-row error details
- `Balmo\Service\ProductImporter` — Batch processor: creates/updates WC products from mapped rows
- `Balmo\Service\ColumnMapper` — Maps CSV columns → WooCommerce fields, applies overrides and price formulas
- `Balmo\Service\CsvFetcher` — Download (URL) or store (upload) CSV to `wp-uploads/balmo/`
- `Balmo\Service\CsvValidator` — Auto-detects delimiter (`,;|\t`), validates headers/types
- `Balmo\Service\PriceFormula` — Safe tokenized formula evaluator (no `eval()`); supports `{price}`, `{vat}` vars and math functions
- `Balmo\Cron\ImportScheduler` — Registers WP-Cron schedules; `execute()` fetches CSV and enqueues batches

**Import flow:**
1. CsvFetcher → CsvValidator → preview in ImportPage
2. User selects/creates mapping profile in MappingPage
3. Import logged to `balmo_import_log` (status: `pending`)
4. Batches dispatched via Action Scheduler (`balmo_as_batch` action in `balmo.php`)
5. Each batch: ColumnMapper applies overrides/formula → ProductImporter creates/updates WC product → row errors → `balmo_import_errors`
6. Next batch auto-queued until all rows done; heartbeat transients detect stalls

**Database tables** (created on plugin activation):
- `{prefix}balmo_import_log` — one row per import run; tracks status, counts, offset, file path
- `{prefix}balmo_import_errors` — per-row errors with row data and message
- `{prefix}balmo_mappings` — mapping profiles with `column_map`, `overrides`, `price_formula` as JSON

## Coding Conventions

Follow AGENTS.md exactly:
- 4-space indent, braces on next line for classes/functions
- Typed properties, early returns for guard clauses
- `PascalCase` classes, `camelCase` methods, `UPPER_SNAKE_CASE` constants
- File name must match class name (e.g., `includes/Service/CsvFetcher.php`)
- Always sanitize input, escape output, check `manage_woocommerce` capability on all admin actions
- Use `__()` / `esc_html__()` with text domain `balmo` for all user-facing strings
- Treat CSV URLs, uploaded files, and mapped values as untrusted input
