#!/bin/bash

set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
backend="$project_root/src/usr/local/emhttp/plugins/zfs.snapshot.manager/scripts/zfs-manager"
test_root="$(mktemp -d)"
trap 'rm -rf "$test_root"' EXIT

mkdir -p "$test_root/bin"
mock_log="$test_root/zfs-arguments.log"
cron_log="$test_root/update-cron.log"
flock_log="$test_root/flock.log"
policy_id="0123456789abcdef0123456789abcdef"

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
  elif [[ "$target" == tank/data@uzm_auto_* || "$target" == tank/data/child@uzm_auto_* ]]; then
    printf 'automatic\tlocal\n'
  else
    printf 'manual\tlocal\n'
  fi
  exit 0
fi

if [[ "${1:-}" == "get" && "$*" == *"org.unraidzfsmanager:policy"* ]]; then
  if [[ "$target" =~ uzm_auto_([a-f0-9]{32})_ ]]; then
    printf '%s\tlocal\n' "${BASH_REMATCH[1]}"
  else
    printf -- '-\t-\n'
  fi
  exit 0
fi

if [[ "${1:-}" == "get" && "$*" == *"-o value clones"* ]]; then
  if [[ "$target" == 'tank/data@uzm_manual_20250104T000000Z' ]]; then
    printf 'tank/clone\n'
  elif [[ "$target" == 'tank/data/child@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z' ]]; then
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
    tank/data@uzm_manual_2025010[1-5]T000000Z|tank/data@uzm_auto_0123456789abcdef0123456789abcdef_20250101T000000Z|tank/data@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z|tank/data/child@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z|media@auto-2026-10-10_00-00)
      printf '%s\n' "$target"
      exit 0
      ;;
  esac
  exit 1
fi

if [[ "${1:-}" == "list" && "$*" == *"org.unraidzfsmanager:policy"* ]]; then
  printf 'tank/data@uzm_auto_0123456789abcdef0123456789abcdef_20250101T000000Z\t1735689600\tautomatic\t0123456789abcdef0123456789abcdef\n'
  printf 'tank/data@uzm_manual_20250101T000000Z\t1735689600\tmanual\t-\n'
  printf 'tank/data@uzm_auto_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_20250101T000000Z\t1735689600\tautomatic\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n'
  printf 'tank/data@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z\t1735689600\tautomatic\tcccccccccccccccccccccccccccccccc\n'
  printf 'tank/data/child@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z\t1735689600\tautomatic\tcccccccccccccccccccccccccccccccc\n'
  printf 'media@auto-2026-10-10_00-00\t1735689600\t-\t-\n'
  exit 0
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
printf '%s\n' "$*" >> "$MOCK_FLOCK_LOG"
exit 0
MOCK
chmod 755 "$test_root/bin/flock"

cat > "$test_root/bin/update_cron" <<'MOCK'
#!/bin/bash
printf 'called\n' >> "$MOCK_CRON_LOG"
exit 0
MOCK
chmod 755 "$test_root/bin/update_cron"

export PATH="$test_root/bin:$PATH"
export MOCK_ZFS_LOG="$mock_log"
export ZSM_LOG_FILE="$test_root/plugin.log"
export ZSM_LOCK_FILE="$test_root/plugin.lock"
export ZSM_CRON_LOCK_FILE="$test_root/plugin-cron.lock"
export ZSM_UPDATE_CRON_BIN="$test_root/bin/update_cron"
export MOCK_CRON_LOG="$cron_log"
export MOCK_FLOCK_LOG="$flock_log"

"$backend" refresh-cron
[[ "$(cat "$cron_log")" == 'called' ]]
grep -Fq 'action=cron-refresh result=success' "$test_root/plugin.log"

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
grep -Fxq -- '-n 9' "$flock_log"
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
[[ "$foreign_output" == *"nicht eindeutig als Plugin-Snapshot"* ]]
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

preview_output="$("$backend" preview-policy "$policy_id" 'tank/data' '3600')"
[[ "$preview_output" == 'tank/data@uzm_auto_0123456789abcdef0123456789abcdef_20250101T000000Z' ]]
! grep -Fq 'destroy tank/data@uzm_manual_20250101T000000Z' "$mock_log" || true

policy_output="$("$backend" run-policy "$policy_id" 'tank/data' '3600' '0')"
policy_snapshot="$(printf '%s\n' "$policy_output" | head -n 1)"
[[ "$policy_snapshot" =~ ^tank/data@uzm_auto_0123456789abcdef0123456789abcdef_[0-9]{8}T[0-9]{6}Z$ ]]
grep -Fxq -- '-w 60 9' "$flock_log"
grep -Fq 'snapshot -o org.unraidzfsmanager:kind=automatic -o org.unraidzfsmanager:policy=0123456789abcdef0123456789abcdef tank/data@uzm_auto_' "$mock_log"
grep -Fq 'destroy tank/data@uzm_auto_0123456789abcdef0123456789abcdef_20250101T000000Z' "$mock_log"
! grep -Fq 'destroy tank/data@uzm_auto_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_20250101T000000Z' "$mock_log"
grep -Fq 'action=create mode=automatic policy=0123456789abcdef0123456789abcdef dataset=tank/data' "$test_root/plugin.log"
grep -Fq 'action=delete mode=retention snapshot=tank/data@uzm_auto_0123456789abcdef0123456789abcdef_20250101T000000Z result=success' "$test_root/plugin.log"

recursive_policy_id="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
recursive_output="$("$backend" run-policy "$recursive_policy_id" 'tank/data' '3600' '1')"
recursive_snapshot="$(printf '%s\n' "$recursive_output" | head -n 1)"
[[ "$recursive_snapshot" =~ ^tank/data@uzm_auto_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb_[0-9]{8}T[0-9]{6}Z$ ]]
grep -Fq 'snapshot -r -o org.unraidzfsmanager:kind=automatic -o org.unraidzfsmanager:policy=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb tank/data@uzm_auto_' "$mock_log"
grep -Fq 'action=create mode=automatic policy=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb dataset=tank/data recursive=1' "$test_root/plugin.log"

set +e
recursive_cleanup_output="$("$backend" cleanup-policy 'cccccccccccccccccccccccccccccccc' 'tank/data' '3600' 2>&1)"
recursive_cleanup_status=$?
set -e
[[ $recursive_cleanup_status -eq 78 ]]
[[ "$recursive_cleanup_output" == *"abhängige Klone"* ]]
! grep -Fq 'destroy tank/data@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z' "$mock_log"
! grep -Fq 'destroy tank/data/child@uzm_auto_cccccccccccccccccccccccccccccccc_20250101T000000Z' "$mock_log"

samba_root="$test_root/samba"
mkdir -p "$samba_root"
samba_log="$test_root/samba.log"
extra_config="$samba_root/smb-extra.conf"
candidate_config="$samba_root/smb-snapshots.conf.new"
active_config="$samba_root/smb-snapshots.conf"
export ZSM_SAMBA_CONFIG_DIR="$samba_root"
export ZSM_SAMBA_EXTRA_CONFIG="$extra_config"
export ZSM_SAMBA_LOCK_FILE="$test_root/samba.lock"
export MOCK_SAMBA_LOG="$samba_log"

cat > "$test_root/bin/testparm" <<'MOCK'
#!/bin/bash
printf 'testparm %s\n' "$*" >> "$MOCK_SAMBA_LOG"
[[ -z "${MOCK_TESTPARM_FAIL:-}" ]]
MOCK
cat > "$test_root/bin/smbcontrol" <<'MOCK'
#!/bin/bash
printf 'smbcontrol %s\n' "$*" >> "$MOCK_SAMBA_LOG"
MOCK
cat > "$test_root/bin/pidof" <<'MOCK'
#!/bin/bash
[[ -z "${MOCK_SMBD_STOPPED:-}" ]]
MOCK
chmod 755 "$test_root/bin/testparm" "$test_root/bin/smbcontrol" "$test_root/bin/pidof"

"$backend" list-filesystems >/dev/null
grep -Fq 'list -H -p -t filesystem -o guid,name,mountpoint,mounted -s name' "$mock_log"

# Ohne Freigaben bleibt eine fremde smb-extra.conf unberuehrt.
printf '[global]\n  custom = 1' > "$extra_config"
original_extra="$(cat "$extra_config")"
printf '# leer\n' > "$candidate_config"
"$backend" apply-samba-config
[[ "$(cat "$extra_config")" == "$original_extra" ]]
[[ ! -e "$active_config" && ! -e "$candidate_config" ]]
[[ ! -e "$samba_log" ]] || ! grep -Fq 'reload-config' "$samba_log"

printf '# Test\n\n[data-snapshots]\n\tpath = /mnt/tank/data/.zfs/snapshot\n' > "$candidate_config"
"$backend" apply-samba-config
[[ -f "$active_config" && ! -e "$candidate_config" ]]
[[ "$(grep -c 'BEGIN zfs.snapshot.manager' "$extra_config")" == '1' ]]
grep -Fxq "include = $active_config" "$extra_config"
grep -Fq 'custom = 1' "$extra_config"
grep -Fq 'smbcontrol smbd reload-config' "$samba_log"

# Wiederholtes Anwenden erzeugt keinen zweiten Block und trennt alte Verbindungen.
printf '[other-snapshots]\n\tpath = /mnt/tank/other/.zfs/snapshot\n' > "$candidate_config"
"$backend" apply-samba-config
[[ "$(grep -c 'BEGIN zfs.snapshot.manager' "$extra_config")" == '1' ]]
grep -Fq 'smbcontrol smbd close-share data-snapshots' "$samba_log"

# Eine abgelehnte Konfiguration veraendert die aktive nicht.
active_before="$(cat "$active_config")"
printf '[broken]\n\tbad = yes\n' > "$candidate_config"
set +e
rejected_output="$(MOCK_TESTPARM_FAIL=1 "$backend" apply-samba-config 2>&1)"
rejected_status=$?
set -e
[[ $rejected_status -eq 78 ]]
[[ "$rejected_output" == *"abgelehnt"* ]]
[[ "$(cat "$active_config")" == "$active_before" && ! -e "$candidate_config" ]]

# Ein beschaedigter Block wird nicht entfernt, damit keine fremden Zeilen verloren gehen.
good_extra="$(cat "$extra_config")"
sed -i '/END zfs.snapshot.manager/d' "$extra_config"
printf '[foreign]\n' >> "$extra_config"
broken_extra="$(cat "$extra_config")"
set +e
"$backend" remove-samba-config >/dev/null 2>&1
broken_status=$?
set -e
[[ $broken_status -eq 74 ]]
[[ "$(cat "$extra_config")" == "$broken_extra" ]]
printf '%s\n' "$good_extra" > "$extra_config"

# Laeuft smbd nicht, ist die Konfiguration trotzdem aktiv.
: > "$samba_log"
printf '[other-snapshots]\n\tpath = /mnt/tank/other/.zfs/snapshot\n' > "$candidate_config"
MOCK_SMBD_STOPPED=1 "$backend" apply-samba-config
! grep -Fq 'reload-config' "$samba_log"

"$backend" remove-samba-config
[[ ! -e "$active_config" ]]
! grep -Fq 'zfs.snapshot.manager' "$extra_config"
grep -Fq 'custom = 1' "$extra_config"
grep -Fq 'smbcontrol smbd close-share other-snapshots' "$samba_log"

printf 'Backend-Tests erfolgreich.\n'
