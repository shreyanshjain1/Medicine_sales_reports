#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "ERROR: $1" >&2
  exit 1
}

required_files=(
  "init.php"
  "README.md"
  "database/schema.sql"
  "database/install_fresh_latest.sql"
  "public/header.php"
  "public/footer.php"
  "public/dashboard.php"
  "public/assets/style.css"
  ".github/workflows/php-lint.yml"
)

for file in "${required_files[@]}"; do
  [[ -f "$file" ]] || fail "Missing required file: $file"
done

required_dirs=(
  "app"
  "app/bootstrap"
  "app/helpers"
  "app/services"
  "app/repositories"
  "public/admin"
  "public/api"
  "public/auth"
  "public/masters"
  "public/reports"
  "public/tasks"
  "database/migrations"
)

for dir in "${required_dirs[@]}"; do
  [[ -d "$dir" ]] || fail "Missing required directory: $dir"
done

if grep -RIn --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude='*.png' --exclude='*.jpg' --exclude='*.jpeg' --exclude='*.gif' -E '^(<<<<<<<|=======|>>>>>>>)' .; then
  fail "Merge conflict markers detected"
fi

legacy_public_files=(
  "public/admin_users.php"
  "public/reports.php"
  "public/report_add.php"
  "public/report_edit.php"
  "public/report_view.php"
  "public/doctors_master.php"
  "public/hospitals_master.php"
  "public/medicines_master.php"
  "public/task_edit.php"
  "public/task_view.php"
  "public/task_delete.php"
)

for file in "${legacy_public_files[@]}"; do
  if [[ -f "$file" ]]; then
    fail "Legacy root-level page still present after folder restructure: $file"
  fi
done

if [[ -f "public/assets/style.patch.css" ]]; then
  fail "Obsolete file still present: public/assets/style.patch.css"
fi

if [[ -f "MOVE_DELETE_GUIDE.txt" ]]; then
  fail "Obsolete migration guide still present: MOVE_DELETE_GUIDE.txt"
fi

shopt -s nullglob
root_upgrades=(database/upgrade_v*.sql)
if (( ${#root_upgrades[@]} > 0 )); then
  printf 'Found root-level upgrade SQL files:\n%s\n' "${root_upgrades[*]}" >&2
  fail "Versioned upgrade SQL files should live under database/migrations"
fi

migrations=(database/migrations/upgrade_v*.sql)
if (( ${#migrations[@]} == 0 )); then
  fail "No migration files found under database/migrations"
fi

echo "Repo health checks passed."
