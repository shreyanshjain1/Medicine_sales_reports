# Database files

## Recommended files
- `schema.sql` — main source-of-truth schema for the current application state
- `install_fresh_latest.sql` — consolidated fresh-install schema file
- `install_with_demo_seed.sql` — consolidated schema plus demo/test seed data
- `update_latest_bundle.sql` — one bundled upgrade path for older installs

## Supporting files
- `seed_demo.sql` — optional demo seed only
- `legacy_upgrade_bundle.sql` — older convenience bundle kept for backward reference
- `migrations/` — versioned SQL upgrade files kept for patch history
- `archive/` — older archived SQL files that are no longer part of the main install path

## Recommended usage
### Fresh install
Import `install_fresh_latest.sql`

### Demo / local install
Import `install_with_demo_seed.sql`

### Existing install upgrade
Back up the database first, then apply `update_latest_bundle.sql`
