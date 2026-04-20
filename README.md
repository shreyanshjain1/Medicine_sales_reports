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


## Abuse protection
- Forgot-password requests now use lightweight email/IP throttling.
- Login now includes an additional per-IP network throttle on top of failed-login checks.
- Offline report sync batches are rate-limited and capped per request.


## Protected setup and developer utilities
- `public/setup.php` now requires an explicit setup key, environment-safe exposure, and POST confirmation before applying `database/schema.sql`.
- `public/tools/diagnose.php` requires a manager session, a configured dev-tool key, and environment-safe exposure. Diagnostic output defaults to a summary view and masks user emails.
- `public/tools/reset_password.php` remains deprecated and returns HTTP 410.


## Repo health and API contract
- API endpoints under `public/api/` use `api_json_success()` and `api_json_error()` wrappers for a consistent JSON contract.
- CI checks validate helper bootstrap references, include targets, and API contract usage.


## Route smoke checks
- CI now checks for brittle legacy route references so nested pages keep using `url()` and `api_url()` helpers after the folder restructure.
