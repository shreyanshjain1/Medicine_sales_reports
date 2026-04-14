# Medicine Sales CRM

A business-ready PHP + MySQL reporting platform for medical representatives, district managers, and managers. This refreshed version adds a cleaner CRM-style UI, safer setup flow, stronger access controls, filtered exports, approval queue workflow, audit logging, login-rate limiting, and complete SQL files for clean installs and upgrades.

## Stack
- PHP 8+
- MySQL / MariaDB
- Vanilla JS + Chart.js + Toast UI Calendar
- Select2 for searchable task dropdowns
- Shared-hosting friendly structure for cPanel / XAMPP / Apache

## Key upgrades in this revamp
- Cleaner CRM-like interface with KPI cards, better spacing, and business dashboard layout
- Secured setup flow using `ALLOW_SETUP` and `SETUP_KEY`
- Removed dangerous public password reset and weak diagnostics behavior
- Added filtered export center
- Added approval queue page
- Added approval history tracking in `report_status_history`
- Added `audit_logs` helper and logging for key actions
- Added login attempt tracking and 15-minute throttling after repeated failures
- Fixed report attachment path consistency
- Fixed task attendee clearing in `task_edit.php`
- Added complete SQL files for fresh install and upgrade patching

## Project structure
```text
Medicine_sales_reports-main/
├── config.example.php
├── init.php
├── database/
│   ├── full_schema.sql
│   └── upgrade_v2.sql
├── public/
│   ├── index.php
│   ├── dashboard.php
│   ├── reports.php
│   ├── report_add.php
│   ├── report_edit.php
│   ├── report_view.php
│   ├── approvals.php
│   ├── exports.php
│   ├── admin_users.php
│   ├── admin_tasks.php
│   ├── setup.php
│   ├── tools/
│   └── assets/
└── uploads/
    ├── attachments/
    └── signatures/
```

## Installation
1. Upload the project to your server.
2. Copy `config.example.php` to `config.php`.
3. Update database credentials, `BASE_URL`, secrets, and security toggles in `config.php`.
4. Import one of the SQL files:
   - Fresh install: `database/full_schema.sql`
   - Existing install upgrade: `database/upgrade_v2.sql`
5. Create writable folders:
   - `uploads/attachments`
   - `uploads/signatures`
6. Temporarily set `ALLOW_SETUP = true` only if you need the browser setup helper.
7. Open:
   - `http://your-domain/path/public/`
8. Turn `ALLOW_SETUP` back to `false` immediately after setup.

## Important security notes
- Do **not** leave `ALLOW_SETUP` enabled in production.
- Change `CSRF_SECRET`, `SETUP_KEY`, and `DEV_TOOL_KEY` before deployment.
- `public/tools/reset_password.php` is intentionally deprecated.
- `public/tools/diagnose.php` now requires manager session, dev mode, and key-based access.

## Roles
### Employee
- Create and edit own reports
- View own tasks and reports
- Submit attachments and signatures

### District Manager
- View own data plus assigned employees
- Review employee reports in approval queue
- See charts and rep activity summaries

### Manager
- Full reporting visibility
- Approval queue and filtered export center
- User administration and temporary password resets
- Task and workflow oversight

## Database files
### `database/full_schema.sql`
Use this for a clean installation. It contains:
- `users`
- `reports`
- `events`
- `event_attendees`
- `report_status_history`
- `audit_logs`
- `login_attempts`
- minimal `doctors_masterlist`

### `database/upgrade_v2.sql`
Use this for upgrading an existing deployment. It adds missing indexes, workflow history, audit logs, and login-attempt tracking.

## Recommended production checklist
- [ ] Set strong secrets in `config.php`
- [ ] Import SQL and verify all tables exist
- [ ] Confirm file upload folders are writable
- [ ] Disable setup mode after first run
- [ ] Test login, report submission, approval queue, and CSV export
- [ ] Remove any leftover public debug or log files from the web root

## Notes
- The project still uses procedural PHP so it stays easy to deploy on low-cost hosting.
- The UI revamp focuses on fast business usability, not framework migration.
- Existing PWA/offline report sync files were kept in place.
