# Medicine Sales CRM

A business-focused PHP + MySQL CRM-style reporting platform built for medical representatives, district managers, and managers. The system is designed to centralize field visit reporting, approval workflows, team oversight, exports, and operational visibility in a shared-hosting-friendly stack.

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white)
![Status](https://img.shields.io/badge/Status-Business%20Ready-0f766e)
![Architecture](https://img.shields.io/badge/Architecture-Procedural%20PHP-1f2937)
![Workflow](https://img.shields.io/badge/Workflow-Reports%20%26%20Approvals-7c3aed)

## Overview
Medicine Sales CRM helps sales teams log doctor visits, capture field activity, manage tasks, route reports for approval, and export filtered business data for management review. It is intentionally built in procedural PHP so it remains easy to maintain, deploy, and extend on cPanel, Apache, XAMPP, and other simple shared-hosting setups.

This version focuses on three outcomes:
- cleaner CRM-style UI
- safer production behavior
- stronger manager workflow visibility

## Core capabilities
- Role-based access for `manager`, `district_manager`, and `employee`
- Report creation and editing with attachments and signatures
- Approval queue for manager-side review
- Dashboard with KPIs, charts, and calendar visibility
- Task and visit scheduling flow
- CSV exports with business-friendly filters
- PWA/offline groundwork with sync support
- Audit and status history foundation

## What makes this project strong
- Business-oriented workflow instead of a generic CRUD demo
- Shared-hosting friendly stack without heavy framework lock-in
- CRM-like layout and manager-first reporting flow
- Upgrade-safe SQL path for fresh installs and existing deployments
- Security cleanup around setup and developer tools

## Security and stability upgrades in this version
- `setup.php` is disabled unless explicitly allowed in config
- setup requires a private setup key
- public password-reset debug script is deprecated
- diagnostics require manager session plus dev-tool key access
- login attempt tracking and throttling support included
- audit log and report status history tables included
- report attachment path handling made consistent

## Screens / modules
- Dashboard
- Reports
- Report View
- Report Add / Edit
- Approval Queue
- Export Center
- User Administration
- Task / Visit Scheduling
- Profile Settings

## Tech stack
- PHP 8+
- MySQL / MariaDB
- Vanilla JavaScript
- Chart.js
- Toast UI Calendar
- Select2
- Bootstrap-style utility approach via custom CSS

## Project structure
```text
Medicine_sales_reports-main/
├── .github/
│   └── workflows/
│       └── php-lint.yml
├── config.example.php
├── init.php
├── database/
│   ├── full_schema.sql
│   └── upgrade_v2.sql
├── public/
│   ├── approvals.php
│   ├── dashboard.php
│   ├── exports.php
│   ├── report_add.php
│   ├── report_edit.php
│   ├── report_view.php
│   ├── reports.php
│   ├── admin_users.php
│   ├── admin_tasks.php
│   ├── setup.php
│   ├── tools/
│   │   ├── diagnose.php
│   │   └── reset_password.php
│   └── assets/
└── uploads/
    ├── attachments/
    └── signatures/
```

## Setup
### Fresh install
1. Copy `config.example.php` to `config.php`
2. Update database credentials and secrets in `config.php`
3. Import `database/full_schema.sql`
4. Make sure these folders are writable:
   - `uploads/attachments`
   - `uploads/signatures`
5. Open `public/`

### Existing project upgrade
1. Back up your current database and project files
2. Copy the updated project files into your repo
3. Import `database/upgrade_v2.sql`
4. Copy `config.example.php` only if you need a new template reference
5. Verify login, approvals, exports, and report creation

## Security notes
- Keep `ALLOW_SETUP` off in production
- Change `CSRF_SECRET`, `SETUP_KEY`, and `DEV_TOOL_KEY`
- Do not expose temporary debug utilities publicly
- Remove stray log files from web-accessible folders

## SQL files
### `database/full_schema.sql`
Use for fresh installs.

### `database/upgrade_v2.sql`
Use for patching an existing deployment.

## GitHub workflow included
This patch adds a GitHub Actions workflow:
- `PHP Lint` on every push to `main`
- `PHP Lint` on every pull request

It runs `php -l` against all PHP files so broken syntax gets caught before merge.

## Why this repo stands out
This is not just a school CRUD project. It is a role-aware internal business platform aimed at real operations: field reporting, approvals, district oversight, exports, auditability, and deployment practicality.

## Roadmap direction
Recommended next additions:
- doctor master CRUD
- medicine master CRUD
- hospital / clinic master CRUD
- password change / forgot-password flow
- notification center
- target tracking and approval aging widgets
