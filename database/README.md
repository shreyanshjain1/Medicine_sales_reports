# Database folder

This folder is intentionally organized around **three main entry points** and two supporting subfolders.

## Use these files

### 1) Fresh install
- `install_fresh_latest.sql`
- Best for a brand-new production or staging database.

### 2) Fresh install with demo data
- `install_with_demo_seed.sql`
- Best for local demos, screenshots, and testing sample flows.

### 3) Existing database upgrade
- `update_latest_bundle.sql`
- Best for upgrading an older database after taking a backup.

## Canonical source files
- `schema.sql` — the latest full schema and the real source of truth.
- `seed_demo.sql` — optional demo/test seed data only.

## Supporting folders
- `migrations/` — versioned incremental upgrades that are still relevant to the current maintained history.
- `archive/` — older historical SQL kept only for reference and rollback research.

## Recommended workflow

### Fresh production install
Import `install_fresh_latest.sql`.

### Fresh demo/local install
Import `install_with_demo_seed.sql`.

### Upgrade an existing install
1. Back up the database.
2. Review `migrations/` if you want the granular history.
3. Apply `update_latest_bundle.sql`.

## Maintenance rules
- Update `schema.sql` whenever the latest full schema changes.
- Add each new incremental update under `migrations/` only.
- Regenerate the bundled install/update files after schema or migration changes.
- Do not add new `upgrade_v*.sql` files to the database root.
- Keep `archive/` for old reference material only.

## Folder summary

```text
.database/
  README.md
  schema.sql
  seed_demo.sql
  install_fresh_latest.sql
  install_with_demo_seed.sql
  update_latest_bundle.sql
  migrations/
    README.md
    upgrade_v13.sql
    ...
  archive/
    README.md
    full_schema.sql
    legacy_upgrade_bundle.sql
    upgrade_v2.sql
    ...
```
