# Migrations

This folder contains the maintained incremental upgrade history for the current app generation.

## Rules
- New upgrades belong here only.
- Name files as `upgrade_vNN.sql`.
- Keep each file idempotent where practical.
- After adding a new migration, regenerate the bundled SQL entry files in the database root.

## Current range
This folder currently starts at `upgrade_v13.sql` because older upgrade history has been moved into `../archive/`.
