#!/usr/bin/env bash
# Manual smoke test for docker/8.4/pg-cluster.sh against a *built* m3u-editor
# image. Exercises the actual image's Postgres binaries and the real script
# (not a reimplementation), driven directly via `docker run --entrypoint`
# rather than booting the full app - this is about the cluster
# upgrade/rollback mechanics, not Laravel.
#
# Usage: docker/8.4/tests/pg-upgrade-smoke.sh [image]
#   (default image: m3ue-pgtest:latest)
#
# Not run in CI - requires `docker`, builds/destroys throwaway volumes, and
# takes a few minutes. Run by hand after touching pg-cluster.sh, the
# Dockerfile's Postgres packages, or db-init.sh/supervisord.conf's use of
# POSTGRES_BIN_DIR.
set -u

IMAGE="${1:-m3ue-pgtest:latest}"
FAILED=0
TMP_SCRIPT="$(mktemp)"
trap 'rm -f "${TMP_SCRIPT}"' EXIT

pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; FAILED=1; }

# Runs a script (read from stdin, written as-is to a temp file so none of
# its $VAR references are touched by this script's own shell) inside the
# image, with the given volume mounted at the Postgres data path and the
# given extra `-e` args. The script itself does the chown/source/call -
# nothing here string-interpolates container-side variables.
run_in_image() {
    local volume="$1"
    shift
    local extra_env=("$@")
    cat > "${TMP_SCRIPT}"
    docker run --rm \
        -v "${volume}:/var/lib/postgresql/data" \
        -v "${TMP_SCRIPT}:/smoke.sh:ro" \
        --tmpfs /tmp:exec \
        -e PG_MOUNT=/var/lib/postgresql/data \
        -e WWWUSER=m3ue -e WWWGROUP=m3ue \
        -e PG_PASSWORD=root -e PG_PORT=5432 \
        -e postgres_log_file=/tmp/postgres.log \
        "${extra_env[@]}" \
        --entrypoint bash "${IMAGE}" /smoke.sh
}

echo "== 0) unversioned PATH default is pinned to 17 =="
version=$(docker run --rm --entrypoint postgres "${IMAGE}" --version)
case "${version}" in
    *" 17."*) pass "PATH postgres is 17 (${version})" ;;
    *) fail "PATH postgres is NOT 17: ${version}" ;;
esac

prelude() {
    cat <<'EOF'
set -e
touch "${postgres_log_file}"
chown -R "${WWWUSER}:${WWWGROUP}" "${PG_MOUNT}" "${postgres_log_file}"
chmod -R 700 "${PG_MOUNT}"
. /var/www/html/docker/8.4/pg-cluster.sh
EOF
}

echo "== 1) fresh install defaults to 17 =="
docker volume rm -f smoke-fresh17 >/dev/null 2>&1
docker volume create smoke-fresh17 >/dev/null
out=$( { prelude; echo 'pg_cluster_prepare; echo "RESULT version=$POSTGRES_ACTIVE_VERSION path=$PGDATA"'; } \
    | run_in_image smoke-fresh17 -e POSTGRES_VERSION=17 2>&1)
echo "${out}" | grep -q "RESULT version=17" && pass "fresh install is PG17" || fail "fresh install: ${out}"
docker volume rm -f smoke-fresh17 >/dev/null

echo "== 2) fresh install can go straight to 18 =="
docker volume rm -f smoke-fresh18 >/dev/null 2>&1
docker volume create smoke-fresh18 >/dev/null
out=$( { prelude; echo 'pg_cluster_prepare; echo "RESULT version=$POSTGRES_ACTIVE_VERSION path=$PGDATA"'; } \
    | run_in_image smoke-fresh18 -e POSTGRES_VERSION=18 2>&1)
echo "${out}" | grep -q "RESULT version=18 path=/var/lib/postgresql/data$" && pass "fresh install can start directly on 18" || fail "fresh install at 18: ${out}"
docker volume rm -f smoke-fresh18 >/dev/null

echo "== 3) upgrade 17 -> 18 preserves data, keeps 17 retained, runs post-upgrade stats =="
docker volume rm -f smoke-upgrade >/dev/null 2>&1
docker volume create smoke-upgrade >/dev/null

{ prelude; echo 'pg_cluster_prepare'; } | run_in_image smoke-upgrade -e POSTGRES_VERSION=17 >/dev/null

{ prelude; cat <<'EOF'
su-exec "${WWWUSER}" /usr/libexec/postgresql17/pg_ctl -D "${PG_MOUNT}" -w -o "-c listen_addresses='' -k /tmp -p ${PG_PORT}" start >/dev/null
su-exec "${WWWUSER}" /usr/libexec/postgresql17/psql -h /tmp -p "${PG_PORT}" -U postgres -c "CREATE DATABASE m3ue" >/dev/null
su-exec "${WWWUSER}" /usr/libexec/postgresql17/psql -h /tmp -p "${PG_PORT}" -U postgres -d m3ue -c "CREATE EXTENSION pg_trgm; CREATE TABLE t(id int); INSERT INTO t SELECT * FROM generate_series(1,1000);" >/dev/null
su-exec "${WWWUSER}" /usr/libexec/postgresql17/pg_ctl -D "${PG_MOUNT}" -w -m fast stop >/dev/null
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=17 >/dev/null

out=$( { prelude; cat <<'EOF'
pg_cluster_prepare
echo "RESULT version=$POSTGRES_ACTIVE_VERSION post_upgrade=$POSTGRES_POST_UPGRADE"
cat "${PG_MOUNT}/.m3ue-postgres-status.json"
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=18 2>&1)
echo "${out}" | grep -q "RESULT version=18 post_upgrade=true" && pass "upgrade reports active=18, post_upgrade flag set" || fail "upgrade result: ${out}"
echo "${out}" | grep -q '"result": "upgraded"' && pass "status file records upgraded" || fail "status file: ${out}"
# The status file's own active_version/active_path fields (read by the
# System Health page), not just the exported shell vars checked above -
# these come from a separate code path and have gone stale independently before.
echo "${out}" | grep -q '"active_version": "18"' && pass "status file active_version is 18, not stale" || fail "status file active_version is stale: ${out}"
echo "${out}" | grep -q '"active_path": "/var/lib/postgresql/data/pg18"' && pass "status file active_path points at pg18" || fail "status file active_path is stale: ${out}"

out=$( { prelude; cat <<'EOF'
su-exec "${WWWUSER}" /usr/libexec/postgresql18/pg_ctl -D "${PG_MOUNT}/pg18" -w -o "-c listen_addresses='' -k /tmp -p ${PG_PORT}" start >/dev/null
echo "ROWS=$(su-exec "${WWWUSER}" /usr/libexec/postgresql18/psql -h /tmp -p "${PG_PORT}" -U postgres -d m3ue -tAc "SELECT count(*) FROM t")"
su-exec "${WWWUSER}" /usr/libexec/postgresql18/pg_ctl -D "${PG_MOUNT}/pg18" -w -m fast stop >/dev/null
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=18 2>&1)
echo "${out}" | grep -q "ROWS= *1000" && pass "all 1000 rows present on 18" || fail "row count check: ${out}"

out=$( { prelude; echo '[ -f "${PG_MOUNT}/PG_VERSION" ] && [ "$(cat "${PG_MOUNT}/PG_VERSION")" = "17" ] && echo RETAINED_17_OK'; } \
    | run_in_image smoke-upgrade -e POSTGRES_VERSION=18 2>&1)
echo "${out}" | grep -q RETAINED_17_OK && pass "retained 17 cluster still present at the root" || fail "retained 17 cluster missing: ${out}"

echo "== 3b) an ordinary restart on the same version keeps the upgrade history, doesn't reset it to none =="
out=$( { prelude; cat <<'EOF'
pg_cluster_prepare
cat "${PG_MOUNT}/.m3ue-postgres-status.json"
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=18 2>&1)
echo "${out}" | grep -q '"result": "upgraded"' && pass "status still records upgraded after a steady-state restart" || fail "upgrade history was reset to none: ${out}"
echo "${out}" | grep -q '"from": "17"' && pass "status still records from=17" || fail "status lost from=17: ${out}"

echo "== 4) lowering POSTGRES_VERSION without POSTGRES_ROLLBACK stays on the newer cluster, with a warning =="
out=$( { prelude; cat <<'EOF'
pg_cluster_prepare
echo "RESULT version=$POSTGRES_ACTIVE_VERSION"
cat "${PG_MOUNT}/.m3ue-postgres-status.json"
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=17 2>&1)
echo "${out}" | grep -q "RESULT version=18" && pass "stays on 18 without POSTGRES_ROLLBACK" || fail "unexpected downgrade: ${out}"
echo "${out}" | grep -q 'POSTGRES_ROLLBACK' && pass "warning mentions POSTGRES_ROLLBACK" || fail "missing rollback warning: ${out}"

echo "== 5) POSTGRES_ROLLBACK=true rolls back to 17, retaining the abandoned 18 cluster =="
out=$( { prelude; cat <<'EOF'
pg_cluster_prepare
echo "RESULT version=$POSTGRES_ACTIVE_VERSION path=$PGDATA"
cat "${PG_MOUNT}/.m3ue-postgres-status.json"
EOF
} | run_in_image smoke-upgrade -e POSTGRES_VERSION=17 -e POSTGRES_ROLLBACK=true 2>&1)
echo "${out}" | grep -q "RESULT version=17 path=/var/lib/postgresql/data$" && pass "rolled back to 17 at the mount root" || fail "rollback result: ${out}"
echo "${out}" | grep -q '"active_version": "17"' && pass "status file active_version is 17 after rollback, not stale" || fail "status file active_version is stale after rollback: ${out}"

out=$( { prelude; echo 'ls "${PG_MOUNT}" | grep -q "pg18.rolled-back-" && echo ROLLED_BACK_OK'; } \
    | run_in_image smoke-upgrade -e POSTGRES_VERSION=17 2>&1)
echo "${out}" | grep -q ROLLED_BACK_OK && pass "abandoned 18 cluster retained as pg18.rolled-back-*" || fail "rolled-back 18 cluster not found: ${out}"
docker volume rm -f smoke-upgrade >/dev/null

echo "== 6) disk-full preflight refuses the upgrade and leaves 17 untouched =="
TMP_SCRIPT2="$(mktemp)"
{ prelude; cat <<'EOF'
pg_cluster_prepare
POSTGRES_VERSION=18 pg_cluster_prepare
echo "RESULT version=$POSTGRES_ACTIVE_VERSION"
[ -d "${PG_MOUNT}/pg18" ] && echo "PARTIAL_PG18_PRESENT" || echo "NO_PARTIAL_PG18"
cat "${PG_MOUNT}/.m3ue-postgres-status.json"
EOF
} > "${TMP_SCRIPT2}"
out=$(docker run --rm --tmpfs /var/lib/postgresql/data:size=90m \
    -v "${TMP_SCRIPT2}:/smoke.sh:ro" \
    -e PG_MOUNT=/var/lib/postgresql/data -e WWWUSER=m3ue -e WWWGROUP=m3ue \
    -e PG_PASSWORD=root -e PG_PORT=5432 -e postgres_log_file=/tmp/postgres.log \
    -e POSTGRES_VERSION=17 --entrypoint bash "${IMAGE}" /smoke.sh 2>&1)
rm -f "${TMP_SCRIPT2}"
echo "${out}" | grep -q "RESULT version=17" && pass "stayed on 17 after a disk-full preflight failure" || fail "disk-full case: ${out}"
echo "${out}" | grep -qi '"result": "failed"' && pass "status file records the failed attempt" || fail "status not recorded as failed: ${out}"
echo "${out}" | grep -q "PARTIAL_PG18_PRESENT" && fail "a partial pg18 directory was left behind" || pass "no partial pg18 directory left behind"

echo
if [ "${FAILED}" -eq 0 ]; then
    echo "All checks passed."
else
    echo "One or more checks FAILED - see above."
fi
exit "${FAILED}"
