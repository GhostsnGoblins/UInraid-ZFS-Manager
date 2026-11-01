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

if [[ "${1:-}" == "snapshot" ]]; then
  exit 0
fi

if [[ "${1:-}" == "list" && "$*" == *"-t snapshot"* && "$*" == *"@uzm_manual_"* ]]; then
  exit 1
fi

if [[ "${1:-}" == "list" && "$*" == *"-t snapshot"* ]]; then
  printf 'tank/data@manual\t1735689600\t4096\t1024\t-\n'
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
[[ "$snapshot_output" == $'tank/data@manual\t1735689600\t4096\t1024\t-' ]]

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

printf 'Backend-Tests erfolgreich.\n'
