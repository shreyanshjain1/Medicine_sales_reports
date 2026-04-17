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
│   ├── ISSUE_TEMPLATE/
│   ├── workflows/
│   └── pull_request_template.md
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
│   ├── legacy_upgrade_bundle.sql
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
├── storage/
│   └── logs/
├── uploads/
│   ├── attachments/
│   └── signatures/
├── config.example.php
├── init.php
└── README.md
```

## Database layout
This repo now supports a clearer database flow:
- `database/schema.sql` — main schema source of truth for fresh installs
- `database/install_fresh_latest.sql` — convenience copy of the latest fresh-install schema
- `database/install_with_demo_seed.sql` — fresh install plus optional demo seed
- `database/update_latest_bundle.sql` — one consolidated update bundle for older installs
- `database/migrations/` — versioned upgrade files kept for patch history
- `database/archive/` — older archived SQL files kept only for historical reference

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

## CI & Repository Checks

The repo now includes lightweight GitHub Actions checks for:
- PHP syntax linting
- repo structure and cleanup validation
- include/require smoke checks for missing file references
- API contract smoke checks for standardized JSON helpers

These checks are intentionally lightweight so the project stays easy to maintain on a plain PHP/MySQL stack while still catching common regression issues early.
