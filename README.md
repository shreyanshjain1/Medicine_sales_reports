# Medicine Sales CRM

A business-focused PHP + MySQL CRM-style field reporting platform for medical representatives, district managers, and managers. The project is built to run cleanly on shared hosting while still covering approvals, notifications, performance tracking, exports, digests, and review workflows.

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8%2FMariaDB-4479A1?logo=mysql&logoColor=white)
![Workflow](https://img.shields.io/badge/Workflow-CRM%20Style-7c3aed)
![Status](https://img.shields.io/badge/Status-Operations%20Ready-0f766e)

## What this project is
Medicine Sales CRM is an internal operations platform for field teams. It centralizes visit reporting, manager approvals, task scheduling, territory performance, notifications, exports, and summary generation in a single procedural PHP application.

## Highlights
- role-based access for manager, district manager, and employee
- report submission with attachments, signatures, duplicate warnings, and quality checks
- approval queue, approval SLA tracking, and report activity timelines
- dashboard KPIs, performance tracking, and territory views
- notification center and email-ready workflow hooks
- master-data pages for doctors, hospitals, and medicines
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
- Profile and Password Security

## Project structure
```text
Medicine_sales_reports-main/
├── .github/
│   └── workflows/
│       └── php-lint.yml
├── config.example.php
├── init.php
├── database/
│   ├── schema.sql
│   ├── seed_demo.sql
│   ├── legacy_upgrade_bundle.sql
│   ├── README.md
│   └── upgrade_v*.sql
├── public/
│   ├── activity_logs.php
│   ├── admin_tasks.php
│   ├── admin_users.php
│   ├── approval_sla.php
│   ├── approvals.php
│   ├── change_password.php
│   ├── dashboard.php
│   ├── digest_builder.php
│   ├── doctors_master.php
│   ├── event_add.php
│   ├── exports.php
│   ├── forgot_password.php
│   ├── header.php
│   ├── hospitals_master.php
│   ├── index.php
│   ├── manager_summary.php
│   ├── medicines_master.php
│   ├── notifications.php
│   ├── performance.php
│   ├── profile.php
│   ├── report_add.php
│   ├── report_edit.php
│   ├── report_view.php
│   ├── reports.php
│   ├── reset_password.php
│   ├── setup.php
│   ├── task_edit.php
│   ├── tools/
│   └── assets/
├── storage/
│   └── logs/
└── uploads/
    ├── attachments/
    └── signatures/
```

## Database layout
This repo now includes a cleaner database structure:
- `database/schema.sql` is the single consolidated schema for fresh installs
- `database/seed_demo.sql` is an optional seed template
- `database/legacy_upgrade_bundle.sql` is a convenience reference for older installs
- legacy `upgrade_v*.sql` files are still kept for backward compatibility and patch history

## Setup
### Fresh install
1. Copy `config.example.php` to `config.php`
2. Set your database credentials and secrets in `config.php`
3. Import `database/schema.sql`
4. Ensure these folders are writable:
   - `uploads/attachments`
   - `uploads/signatures`
   - `storage/logs`
5. Open `public/`

### Existing install
1. Back up your current database and files
2. Copy in the updated project files
3. Prefer aligning older databases to the consolidated structure manually, or use the legacy upgrade SQL files if needed
4. Keep `config.php` intact

## Notes
- `setup.php` now reads from the consolidated schema file instead of duplicating table definitions inline
- the app bootstraps missing `uploads/` and `storage/` folders automatically on startup
- `public/error_log` should not be committed; runtime logs belong in `storage/logs/`

## Why this version is stronger
This repo now looks more like a maintainable business application instead of a patch-stacked demo: cleaner install path, cleaner repo structure, consolidated database source-of-truth, and better separation between app files, uploads, and runtime logs.


## Project Structure

```text
public/
  admin/       # approvals, exports, admin users/tasks, activity, summaries, KPI pages
  api/         # JSON/data endpoints used by dashboard, mobile/offline sync, async UI
  auth/        # account/profile/password recovery screens
  masters/     # doctors, hospitals, medicines master-data management
  reports/     # report listing, creation, editing, review view
  tasks/       # task create/edit/view/delete workflow
  assets/      # CSS, JS, icons
  tools/       # locked-down diagnostics / dev utilities
  dashboard.php
  index.php
  logout.php
  notifications.php
  setup.php
  sw.js
  manifest.webmanifest
  offline.html
```
