# Database files

## Primary entry files
These are the only SQL files most people should care about:

- `schema.sql` — **canonical source of truth** for the latest schema.
- `install_fresh_latest.sql` — generated convenience copy for fresh installs.
- `install_with_demo_seed.sql` — generated fresh install plus demo seed.
- `update_latest_bundle.sql` — generated bundled update path for older installs.

## Supporting files
- `seed_demo.sql` — demo/test data only.
- `migrations/upgrade_v*.sql` — versioned migration history.
- `archive/` — older archived SQL that is no longer part of the main install path.
- `legacy_upgrade_bundle.sql` — deprecated legacy bundle kept only for backward reference.

## Recommended usage
### Fresh install
Import `install_fresh_latest.sql`

### Demo or local install
Import `install_with_demo_seed.sql`

### Existing install upgrade
Back up the database first, then apply `update_latest_bundle.sql`

## Canonical workflow for future updates
1. Update `schema.sql` when the latest full schema changes.
2. Add a new versioned migration under `migrations/` for incremental upgrades.
3. Run:

```bash
php scripts/db/rebuild_consolidated_sql.php
```

That regenerates:
- `install_fresh_latest.sql`
- `install_with_demo_seed.sql`
- `update_latest_bundle.sql`

## Cleanup direction
To keep this folder clean over time:
- do **not** add new `upgrade_v*.sql` files to the database root
- keep versioned upgrades only under `database/migrations/`
- keep `legacy_upgrade_bundle.sql` only until you no longer need backward-reference compatibility
