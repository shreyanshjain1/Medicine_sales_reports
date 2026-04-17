# Medicine Sales CRM

A business-focused PHP + MySQL CRM-style field reporting platform for medical representatives, district managers, and managers. The project is built for shared hosting while still covering approvals, notifications, performance tracking, exports, digests, drafts, review workflows, and admin operations.

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8%2FMariaDB-4479A1?logo=mysql&logoColor=white)
![Workflow](https://img.shields.io/badge/Workflow-CRM%20Style-7c3aed)
![Status](https://img.shields.io/badge/Status-Operations%20Ready-0f766e)

## What this project is
Medicine Sales CRM is an internal operations platform for field teams. It centralizes visit reporting, manager approvals, task scheduling, territory performance, notifications, exports, digests, and summary generation in a single procedural PHP application with a cleaner internal architecture.

## Highlights
- role-based access for manager, district manager, and employee
- report submission with attachments, signatures, drafts, duplicate warnings, and quality checks
- approval queue, approval SLA tracking, and report activity timelines
- dashboard KPIs, performance tracking, and territory views
- notification center, email-ready workflow hooks, and digest presets
- master-data pages for doctors, hospitals, and medicines with import/export support
- manager summary and digest builder for leadership updates
- shared-hosting-friendly structure without a heavy framework dependency

## Modules
- Dashboard
- Reports
- Approval Queue
- Approval SLA
- Performance
- Notifications
- Activity Logs
- Manager Summary
- Digest Builder
- Doctors / Hospitals / Medicines Masters
- Users and Tasks
- Profile, Password Security, and Settings

## Project structure
```text
Medicine_sales_reports-main/
├── .github/
├── app/
│   ├── bootstrap/
│   ├── components/
│   ├── helpers/
│   ├── repositories/
│   └── services/
├── database/
│   ├── archive/
│   ├── migrations/
│   ├── schema.sql
│   ├── install_fresh_latest.sql
│   ├── install_with_demo_seed.sql
│   ├── update_latest_bundle.sql
│   ├── seed_demo.sql
│   └── README.md
├── public/
│   ├── admin/
│   ├── api/
│   ├── assets/
│   │   ├── js/
│   │   └── styles/
│   ├── auth/
│   ├── masters/
│   ├── partials/
│   ├── reports/
│   ├── tasks/
│   ├── tools/
│   ├── dashboard.php
│   ├── footer.php
│   ├── header.php
│   ├── index.php
│   ├── logout.php
│   ├── manifest.webmanifest
│   ├── notifications.php
│   ├── offline.html
│   ├── setup.php
│   └── sw.js
├── scripts/
│   └── db/
├── storage/
├── uploads/
├── config.example.php
├── init.php
└── README.md
```

## Database layout
Use these files as the main entry points:
- `database/schema.sql` — canonical latest schema
- `database/install_fresh_latest.sql` — generated fresh-install bundle
- `database/install_with_demo_seed.sql` — generated fresh-install + demo seed bundle
- `database/update_latest_bundle.sql` — generated upgrade bundle from `database/migrations/`

Supporting files:
- `database/seed_demo.sql` — demo data only
- `database/migrations/` — versioned upgrades only
- `database/archive/` — historical archived SQL only
- `database/legacy_upgrade_bundle.sql` — deprecated backward-reference file

## Setup
### Fresh install
1. Copy `config.example.php` to `config.php`
2. Set your database credentials and secrets in `config.php`
3. Import `database/install_fresh_latest.sql`
4. Ensure these folders are writable:
   - `uploads/attachments`
   - `uploads/signatures`
   - `storage/logs`
5. Open `public/`

### Demo / local test install
1. Copy `config.example.php` to `config.php`
2. Set your database credentials and secrets
3. Import `database/install_with_demo_seed.sql`
4. Open `public/`

### Existing install
1. Back up your current database and files
2. Copy in the updated project files
3. Import `database/update_latest_bundle.sql`
4. Keep `config.php` intact

## Database maintenance workflow
When the schema changes:
1. update `database/schema.sql`
2. add a new versioned upgrade file in `database/migrations/`
3. run:

```bash
php scripts/db/rebuild_consolidated_sql.php
```

This regenerates the three convenience SQL entry files so the database folder stays consistent.

## Architecture notes
- `init.php` is now a thin bootstrap entry point
- `app/bootstrap/` loads shared helpers, components, services, repositories, and runtime boot steps
- `app/helpers/` holds cross-cutting app helpers
- `app/services/` holds business-logic helpers for larger modules
- `app/repositories/` holds schema/bootstrap persistence helpers
- the app bootstraps missing `uploads/` and `storage/` folders automatically on startup

## Notes
- `setup.php` should only be enabled intentionally in controlled environments
- runtime logs belong in `storage/logs/`, not in tracked public files
- `public/assets/style.patch.css` is obsolete after the design-system consolidation and should not be kept

## Why this version is stronger
This repo now looks more like a maintainable business application instead of a patch-stacked demo: cleaner install path, thinner bootstrap flow, more intentional folder structure, consolidated database entry files, and better separation between app code, uploads, and runtime logs.
