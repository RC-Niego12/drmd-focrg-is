# Disaster Response Operations Management Integrated System (DROMIS)

Laravel 12, React, Inertia.js, MySQL, Tailwind CSS, and RBAC foundation for DRMD Food and Non-Food Item inventory, request processing, dispatch monitoring, near-expiry distribution, and DROMIC reporting.

## Included Modules

- RROS inventory, warehouse, request approval, dispatch, near-expiry, and transaction monitoring foundation.
- DRRS request encoding, assessment form, response letter, and near-expiry distribution records.
- DRIMS request monitoring and DROMIC report creation with Google Sheet link fields.
- Google Sheet warehouse inventory import with receipt, issuance, delivery, transport, encoder/editor, status, and remarks mapping.
- Normalized MySQL migrations, role/permission seed data, audit logs, notifications, attachments, and support tables.
- Inertia React dashboard and workflow pages with responsive Tailwind styling and dark mode.

## Setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer run dev
```

`composer run dev` starts the Laravel LAN server, queue worker, production-style
asset watcher, and secured Socket.IO gateway together. The watcher continuously
rebuilds same-origin assets without creating `public/hot`, so phones and tablets
do not become dependent on a development-only hostname or port. If Laravel is
already served by Herd, `npm run dev` starts the asset watcher and background
services. Use `npm run dev:hot` only for an intentional desktop-only HMR session;
stop it and run `npm run build` before testing PWA or LAN/mobile access.

Seeded users all use the password `password`:

- `rros@example.test`
- `drrs@example.test`
- `drims@example.test`

## Verification Run

The following checks passed locally:

```bash
php -l app config database routes tests
composer validate --strict
npm install
npm run build
```

`composer install` resolved the lock file but could not download packages because GitHub connections from this machine were reset. Rerun `composer install` once GitHub access is stable, then run:

```bash
php artisan test
```

## Google Sheet Inventory Import

The FO CARAGA warehouse inventory Google Sheet can be synchronized with:

```bash
php artisan inventory:import-google-sheet
```

Or with an explicit public sheet URL:

```bash
php artisan inventory:import-google-sheet "https://docs.google.com/spreadsheets/d/{sheet-id}/edit?gid={gid}"
```

Imported rows are stored in `warehouse_sheet_imports` for audit and are normalized into:

- `warehouses`
- `inventory_items`
- `inventory_batches`
- `inventory_transactions`

Open `/inventory/process-flow` in the app to view the mapping and the add/release transaction process.

The inventory balance screen follows the Warehouse Inventory Tool stock-card behavior:

```text
Current Balance = Receipt - Issuance
```

Balances are grouped by warehouse, item, and `Brand / Description`, so item variants such as `Family Food Pack - Prepacked` and `Family Food Pack - Produced - Vacuum` remain separate rows.
