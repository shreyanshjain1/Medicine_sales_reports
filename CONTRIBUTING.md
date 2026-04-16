# Contributing

## Branching
Use focused feature branches such as `fix/feature-name` or `refactor/module-name`.

## Pull requests
Keep PRs small and business-focused. Include a clear summary, changed files, DB impact, and manual test notes.

## Database changes
Whenever schema changes are introduced:
1. Update `database/schema.sql`
2. Add a matching `database/upgrade_vXX.sql` file
3. Mention the migration in the PR description

## UI changes
Preserve the current CRM-style layout and reuse shared UI/form helpers where possible.

## Coding notes
- Prefer prepared statements
- Preserve role/scope checks
- Keep API responses consistent
- Avoid introducing new root-level clutter in `public/`
