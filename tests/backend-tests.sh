#!/bin/bash

set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
backend="$project_root/src/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager"
test_root="$(mktemp -d)"
trap 'rm -rf "$test_root"' EXIT

mkdir -p "$test_root/bin"
mock_log="$test_root/zfs-arguments.log"

cat > "$test_root/bin/zfs" <<'MOCK'
#!/bin/bash
set -u
printf '%s\n' "$*" >> "$MOCK_ZFS_LOG"
target="${*: -1}"

if [[ "${1:-}" == "snapshot" ]]; then
  exit 0
fi

if [[ "${1:-}" == "destroy" ]]; then
  [[ "$target" == 'tank/data@uzm_manual_20250105T000000Z' ]] && exit 1
  exit 0
fi

if [[ "${1:-}" == "get" && "$*" == *"org.unraidzfsmanager:kind"* ]]; then
  if [[ "$target" == 'tank/data@uzm_manual_20250102T000000Z' ]]; then
    printf 'external\tlocal\n'
  else
    printf 'manual\tlocal\n'
  fi
  exit 0
fi

if [[ "${1:-}" == "get" && "$*" == *"-o value clones"* ]]; then
  if [[ "$target" == 'tank/data@uzm_manual_20250104T000000Z' ]]; then
    printf 'tank/clone\n'
  else
    printf -- '-\n'
  fi
  exit 0
fi

if [[ "${1:-}" == "holds" ]]; then
  if [[ "$target" == 'tank/data@uzm_manual_20250103T000000Z' ]]; then
    printf '%s\ttest-hold\t1735689600\n' "$target"
  fi
  exit 0
fi

if [[ "${1:-}" == "list" && "$*" == *"-t snapshot"* && "$*" == *"-o name "* ]]; then
  case "$target" in
    tank/data@uzm_manual_2025010[1-5]T000000Z|media@auto-2026-10-10_00-00)
      printf '%s\n' "$target"
      exit 0
      ;;
  esac
  exit 1
fi

if [[ "${1:-}" == "list" && "$*" == *"-t snapshot"* ]]; then
  printf 'tank/data@uzm_manual_20250101T000000Z\t1735689600\t4096\t1024\t-\tmanual\n'
  exit 0
fi

if [[ "${1:-}" == "list" && "$*" == *"-o name tank/data"* ]]; then
  printf 'tank/data\n'
  exit 0
fi

if [[ "${1:-}" == "list" ]]; then
  printf 'tank/data\tfilesystem\t4096\t8192\t1024\t/mnt/tank/data\n'
  exit 0
fi

exit 1
MOCK
chmod 755 "$test_root/bin/zfs"

cat > "$test_root/bin/flock" <<'MOCK'
#!/bin/bash
exit 0
MOCK
chmod 755 "$test_root/bin/flock"

export PATH="$test_root/bin:$PATH"
export MOCK_ZFS_LOG="$mock_log"
export ZSM_LOG_FILE="$test_root/plugin.log"
export ZSM_LOCK_FILE="$test_root/plugin.lock"

dataset_output="$("$backend" list-datasets)"
[[ "$dataset_output" == $'tank/data\tfilesystem\t4096\t8192\t1024\t/mnt/tank/data' ]]

snapshot_output="$("$backend" list-snapshots)"
[[ "$snapshot_output" == $'tank/data@uzm_manual_20250101T000000Z\t1735689600\t4096\t1024\t-\tmanual' ]]

before_invalid="$(wc -l < "$mock_log")"
set +e
invalid_output="$("$backend" create 'tank/data;touch_bad' 2>&1)"
invalid_status=$?
set -e
[[ $invalid_status -eq 64 ]]
[[ "$invalid_output" == *"Ungültiger Dataset-Name"* ]]
after_invalid="$(wc -l < "$mock_log")"
[[ "$before_invalid" == "$after_invalid" ]]

created_snapshot="$("$backend" create 'tank/data')"
[[ "$created_snapshot" =~ ^tank/data@uzm_manual_[0-9]{8}T[0-9]{6}Z$ ]]
grep -Fq 'snapshot -o org.unraidzfsmanager:kind=manual tank/data@uzm_manual_' "$mock_log"
grep -Fq 'action=create dataset=tank/data snapshot=tank/data@uzm_manual_' "$test_root/plugin.log"

before_invalid_delete="$(wc -l < "$mock_log")"
set +e
invalid_delete_output="$("$backend" delete 'tank/data@uzm_manual_20250101T000000Z;touch_bad' 2>&1)"
invalid_delete_status=$?
set -e
[[ $invalid_delete_status -eq 64 ]]
[[ "$invalid_delete_output" == *"Nur eindeutig benannte Plugin-Snapshots"* ]]
after_invalid_delete="$(wc -l < "$mock_log")"
[[ "$before_invalid_delete" == "$after_invalid_delete" ]]

set +e
foreign_output="$("$backend" delete 'tank/data@uzm_manual_20250102T000000Z' 2>&1)"
foreign_status=$?
set -e
[[ $foreign_status -eq 77 ]]
[[ "$foreign_output" == *"nicht eindeutig als manueller Plugin-Snapshot"* ]]
! grep -Fq 'destroy tank/data@uzm_manual_20250102T000000Z' "$mock_log"
grep -Fq 'action=delete mode=plugin snapshot=tank/data@uzm_manual_20250102T000000Z result=blocked reason=not_plugin_owned' "$test_root/plugin.log"

set +e
clone_output="$("$backend" delete 'tank/data@uzm_manual_20250104T000000Z' 2>&1)"
clone_status=$?
set -e
[[ $clone_status -eq 78 ]]
[[ "$clone_output" == *"abhängige Klone"* ]]
! grep -Fq 'destroy tank/data@uzm_manual_20250104T000000Z' "$mock_log"

set +e
hold_output="$("$backend" delete 'tank/data@uzm_manual_20250103T000000Z' 2>&1)"
hold_status=$?
set -e
[[ $hold_status -eq 79 ]]
[[ "$hold_output" == *"ZFS-Holds"* ]]
! grep -Fq 'destroy tank/data@uzm_manual_20250103T000000Z' "$mock_log"

deleted_snapshot="$("$backend" delete 'tank/data@uzm_manual_20250101T000000Z')"
[[ "$deleted_snapshot" == 'tank/data@uzm_manual_20250101T000000Z' ]]
grep -Fq 'destroy tank/data@uzm_manual_20250101T000000Z' "$mock_log"
grep -Fq 'action=delete mode=plugin snapshot=tank/data@uzm_manual_20250101T000000Z result=success' "$test_root/plugin.log"

set +e
failed_delete_output="$("$backend" delete 'tank/data@uzm_manual_20250105T000000Z' 2>&1)"
failed_delete_status=$?
set -e
[[ $failed_delete_status -eq 74 ]]
[[ "$failed_delete_output" == *"ZFS konnte den Snapshot nicht löschen"* ]]
grep -Fq 'action=delete mode=plugin snapshot=tank/data@uzm_manual_20250105T000000Z result=failed reason=zfs_destroy_failed' "$test_root/plugin.log"

before_wrong_confirmation="$(wc -l < "$mock_log")"
set +e
wrong_confirmation_output="$("$backend" delete-external 'media@auto-2026-10-10_00-00' 'media@wrong' 2>&1)"
wrong_confirmation_status=$?
set -e
[[ $wrong_confirmation_status -eq 64 ]]
[[ "$wrong_confirmation_output" == *"stimmt nicht exakt überein"* ]]
after_wrong_confirmation="$(wc -l < "$mock_log")"
[[ "$before_wrong_confirmation" == "$after_wrong_confirmation" ]]

before_invalid_external="$(wc -l < "$mock_log")"
set +e
invalid_external_output="$("$backend" delete-external 'media@auto%' 'media@auto%' 2>&1)"
invalid_external_status=$?
set -e
[[ $invalid_external_status -eq 64 ]]
[[ "$invalid_external_output" == *"Ungültiger Snapshot-Name"* ]]
after_invalid_external="$(wc -l < "$mock_log")"
[[ "$before_invalid_external" == "$after_invalid_external" ]]

external_snapshot="media@auto-2026-10-10_00-00"
deleted_external="$("$backend" delete-external "$external_snapshot" "$external_snapshot")"
[[ "$deleted_external" == "$external_snapshot" ]]
grep -Fq 'destroy media@auto-2026-10-10_00-00' "$mock_log"
grep -Fq 'action=delete mode=external snapshot=media@auto-2026-10-10_00-00 result=success' "$test_root/plugin.log"

printf 'Backend-Tests erfolgreich.\n'
