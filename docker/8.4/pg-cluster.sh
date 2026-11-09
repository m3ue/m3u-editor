#!/usr/bin/env bash
# Embedded Postgres version management: picks which major version to boot,
# and upgrades an existing cluster in place when the user opts into a newer
# one via POSTGRES_VERSION. Sourced from start-container (not executed
# directly) so it shares that script's exported vars (PG_MOUNT, WWWUSER,
# WWWGROUP, PG_PASSWORD, PG_PORT, postgres_log_file) and so the smoke test
# can call its functions individually.
#
# Layout inside the single mounted volume ($PG_MOUNT, e.g. /var/lib/postgresql/data):
#   $PG_MOUNT/PG_VERSION          - the original ("root") cluster. Every
#                                    install starts here; a fresh install's
#                                    first initdb always lands at the root
#                                    regardless of which version is requested.
#   $PG_MOUNT/pg<N>/               - a cluster created by upgrading to major N.
#                                    Only counts as usable once it carries the
#                                    commit marker below - a pg<N>/ without
#                                    one is a crashed/killed upgrade attempt
#                                    and is removed on the next boot.
#   $PG_MOUNT/pg<N>/.m3ue-cluster-complete
#                                    - written last, after pg_upgrade and
#                                      config copy both succeed. Nothing
#                                      before this point has touched the old
#                                      cluster's data, so a crash/kill/OOM at
#                                      any earlier step leaves the old
#                                      cluster exactly as it was.
#   $PG_MOUNT/pg<N>.rolled-back-<ts>/
#                                    - a once-active pg<N> cluster set aside
#                                      by an explicit POSTGRES_ROLLBACK=true.
#                                      Never deleted automatically - it is
#                                      the only copy of any writes made after
#                                      the upgrade that produced it.
#
# The "active" cluster is whichever of the above (root or a complete pg<N>)
# has the highest version number - there is normally exactly one candidate,
# except right after an upgrade, when both the root and the new pg<N> exist
# and the root becomes the retained rollback copy.
#
# pg_upgrade runs in --copy mode (the default), never --link: --copy leaves
# the old cluster's files completely intact and independently bootable, at
# the cost of roughly duplicating the cluster's on-disk size during the
# upgrade. --link is faster and uses less disk, but hard-links the old
# cluster's files so the moment the new cluster starts, the old one is no
# longer safe to boot - that trades away the rollback guarantee this exists
# to provide.

pg_cluster_log() {
    echo "[pg-cluster] $*"
}

# Installed server generations, e.g. "17 18", sorted ascending.
pg_cluster_installed_versions() {
    local dir version
    for dir in /usr/libexec/postgresql[0-9]*; do
        [ -x "${dir}/postgres" ] || continue
        version="${dir##*/postgresql}"
        echo "${version}"
    done | sort -n
}

pg_cluster_bin_dir() {
    echo "/usr/libexec/postgresql$1"
}

pg_cluster_version_installed() {
    [ -x "$(pg_cluster_bin_dir "$1")/postgres" ]
}

# Reads $PG_MOUNT/PG_VERSION if the root cluster exists. Empty otherwise.
# Always returns 0 - "no root cluster yet" is a normal result, not a
# failure, and this is called from a command substitution assignment that
# would otherwise trip set -e in anything stricter than start-container.
pg_cluster_root_version() {
    if [ -f "${PG_MOUNT}/PG_VERSION" ]; then
        cat "${PG_MOUNT}/PG_VERSION"
    fi
    return 0
}

# Removes any pg<N>/ left behind by an upgrade that never reached its commit
# marker - a crash, OOM kill, or power loss during pg_upgrade itself. Safe at
# any time because a directory without the marker was never promoted to
# active and nothing else references it.
pg_cluster_cleanup_incomplete() {
    local dir
    for dir in "${PG_MOUNT}"/pg[0-9]*; do
        [ -d "${dir}" ] || continue
        case "$(basename "${dir}")" in
            *.rolled-back-*) continue ;;
        esac
        if [ ! -f "${dir}/.m3ue-cluster-complete" ]; then
            pg_cluster_log "Removing incomplete cluster directory ${dir} (previous upgrade did not finish)."
            rm -rf "${dir}"
        fi
    done
}

# Sets ACTIVE_VERSION and ACTIVE_PATH to the highest-version usable cluster,
# or both empty if there is none (fresh install).
pg_cluster_find_active() {
    ACTIVE_VERSION=""
    ACTIVE_PATH=""

    local root_version
    root_version="$(pg_cluster_root_version)"
    if [ -n "${root_version}" ]; then
        ACTIVE_VERSION="${root_version}"
        ACTIVE_PATH="${PG_MOUNT}"
    fi

    local dir version
    for dir in "${PG_MOUNT}"/pg[0-9]*; do
        [ -d "${dir}" ] || continue
        case "$(basename "${dir}")" in
            *.rolled-back-*) continue ;;
        esac
        [ -f "${dir}/.m3ue-cluster-complete" ] || continue
        version="$(basename "${dir}" | sed 's/^pg//')"
        if [ -z "${ACTIVE_VERSION}" ] || [ "${version}" -gt "${ACTIVE_VERSION}" ]; then
            ACTIVE_VERSION="${version}"
            ACTIVE_PATH="${dir}"
        fi
    done
}

# Reads the upgrade sub-object from any existing status file into
# PREV_RESULT/PREV_FROM/PREV_TO/PREV_AT/PREV_MESSAGE ("none"/empty when
# there is no file yet). A boot that has nothing new to report passes these
# straight back to pg_cluster_write_status instead of a fresh "none", so the
# last real upgrade/rollback stays visible across every ordinary restart
# that follows it - not just the one boot it happened on.
pg_cluster_read_previous_upgrade() {
    PREV_RESULT="none"
    PREV_FROM=""
    PREV_TO=""
    PREV_AT=""
    PREV_MESSAGE=""

    local status_file="${PG_MOUNT}/.m3ue-postgres-status.json"
    [ -f "${status_file}" ] || return 0

    local value
    value=$(sed -n 's/.*"result": "\([^"]*\)".*/\1/p' "${status_file}" | head -1)
    [ -n "${value}" ] && PREV_RESULT="${value}"
    PREV_FROM=$(sed -n 's/.*"from": "\([^"]*\)".*/\1/p' "${status_file}" | head -1)
    PREV_TO=$(sed -n 's/.*"to": "\([^"]*\)".*/\1/p' "${status_file}" | head -1)
    PREV_AT=$(sed -n 's/.*"at": "\([^"]*\)".*/\1/p' "${status_file}" | head -1)
    PREV_MESSAGE=$(sed -n 's/.*"message": "\(.*\)".*/\1/p' "${status_file}" | head -1)
}

# $6 (at) is optional - a fresh event (upgraded/failed/rolled_back) omits it
# to stamp "now"; a steady-state boot passes PREV_AT through so the
# timestamp keeps reflecting when the recorded event actually happened.
pg_cluster_write_status() {
    local result="$1" from="$2" to="$3" message="$4" warning="$5" at="${6:-$(date -u +%FT%TZ)}"
    local status_file="${PG_MOUNT}/.m3ue-postgres-status.json"
    local tmp
    tmp="$(mktemp "${PG_MOUNT}/.m3ue-postgres-status.json.XXXXXX")"

    # Values here are all our own paths/versions/fixed strings except
    # $message, which can echo command output - escape it for JSON.
    local escaped_message
    escaped_message=$(printf '%s' "${message}" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' ')

    cat > "${tmp}" <<JSON
{
  "active_version": "${ACTIVE_VERSION}",
  "active_path": "${ACTIVE_PATH}",
  "requested_version": "${POSTGRES_VERSION}",
  "upgrade": {
    "result": "${result}",
    "from": "${from}",
    "to": "${to}",
    "at": "${at}",
    "message": "${escaped_message}"
  },
  "warning": "${warning}"
}
JSON
    chown "${WWWUSER}:${WWWGROUP}" "${tmp}"
    mv "${tmp}" "${status_file}"
}

# Disk headroom check before touching anything. pg_upgrade --copy duplicates
# the old cluster's relation files (not WAL), so requiring free space >= the
# old cluster's own size plus a buffer is already conservative.
pg_cluster_check_disk_space() {
    local old_path="$1"
    local old_size_kb free_kb required_kb

    old_size_kb=$(du -sk --exclude='pg[0-9]*' --exclude='.m3ue-postgres-status.json*' "${old_path}" 2>/dev/null | cut -f1)
    free_kb=$(df -Pk "${PG_MOUNT}" | awk 'NR==2{print $4}')

    if [ -z "${old_size_kb}" ] || [ -z "${free_kb}" ]; then
        pg_cluster_log "WARNING: could not determine disk usage/free space, proceeding without the preflight check."
        return 0
    fi

    required_kb=$((old_size_kb + old_size_kb / 10 + 51200))

    if [ "${free_kb}" -lt "${required_kb}" ]; then
        UPGRADE_ERROR="Not enough disk space to upgrade: need ~$((required_kb / 1024)) MB free, have $((free_kb / 1024)) MB."
        return 1
    fi

    return 0
}

# Starts the old cluster briefly (no TCP, unix socket only) to run crash
# recovery if needed and read its locale/checksum settings, then stops it
# cleanly. pg_upgrade requires a cleanly-stopped source cluster.
pg_cluster_read_old_cluster_facts() {
    local old_bin="$1" old_path="$2"

    if ! su-exec "${WWWUSER}" "${old_bin}/pg_ctl" -D "${old_path}" -w -l "${postgres_log_file}" \
        -o "-c listen_addresses='' -c unix_socket_directories=/run/postgresql -p ${PG_PORT}" start >/dev/null 2>&1; then
        UPGRADE_ERROR="Could not start the existing Postgres cluster to read its settings. Check ${postgres_log_file}."
        return 1
    fi

    # lc_collate/lc_ctype are fixed per-database at creation time, not
    # session GUCs - SHOW lc_collate/SHOW lc_ctype don't exist and error.
    # Reading them off the postgres database's pg_database row reflects
    # what initdb chose for this cluster.
    OLD_ENCODING=$(su-exec "${WWWUSER}" "${old_bin}/psql" -U postgres -h /run/postgresql -p "${PG_PORT}" -tAc "SHOW server_encoding" postgres 2>/dev/null)
    OLD_LC_COLLATE=$(su-exec "${WWWUSER}" "${old_bin}/psql" -U postgres -h /run/postgresql -p "${PG_PORT}" -tAc "SELECT datcollate FROM pg_database WHERE datname = current_database()" postgres 2>/dev/null)
    OLD_LC_CTYPE=$(su-exec "${WWWUSER}" "${old_bin}/psql" -U postgres -h /run/postgresql -p "${PG_PORT}" -tAc "SELECT datctype FROM pg_database WHERE datname = current_database()" postgres 2>/dev/null)

    su-exec "${WWWUSER}" "${old_bin}/pg_ctl" -D "${old_path}" -w -m fast stop >/dev/null 2>&1

    if [ -z "${OLD_ENCODING}" ] || [ -z "${OLD_LC_COLLATE}" ] || [ -z "${OLD_LC_CTYPE}" ]; then
        UPGRADE_ERROR="Could not read the existing cluster's encoding/locale settings."
        return 1
    fi

    OLD_CHECKSUMS=$("${old_bin}/pg_controldata" "${old_path}" 2>/dev/null | awk -F: '/Data page checksum version/ { gsub(/ /, "", $2); print $2 }')
    return 0
}

# Performs the upgrade. On success sets NEW_ACTIVE_PATH and returns 0; on any
# failure sets UPGRADE_ERROR, removes the partial new cluster, and returns 1
# -- the caller keeps booting the old (untouched) cluster either way.
pg_cluster_upgrade() {
    local old_version="$1" old_path="$2" new_version="$3"
    local old_bin new_bin new_path work_dir checksum_flag pwfile

    old_bin="$(pg_cluster_bin_dir "${old_version}")"
    new_bin="$(pg_cluster_bin_dir "${new_version}")"
    new_path="${PG_MOUNT}/pg${new_version}"
    work_dir="/tmp/pg-upgrade-${new_version}"

    pg_cluster_log "Upgrading embedded Postgres ${old_version} -> ${new_version} (this can take a while for large databases)..."

    if ! pg_cluster_check_disk_space "${old_path}"; then
        pg_cluster_log "FAILED: ${UPGRADE_ERROR}"
        return 1
    fi

    if ! pg_cluster_read_old_cluster_facts "${old_bin}" "${old_path}"; then
        pg_cluster_log "FAILED: ${UPGRADE_ERROR}"
        return 1
    fi

    rm -rf "${new_path}" "${work_dir}"
    mkdir -p "${new_path}" "${work_dir}"
    chown "${WWWUSER}:${WWWGROUP}" "${new_path}" "${work_dir}"
    chmod 700 "${new_path}"

    checksum_flag="--no-data-checksums"
    [ "${OLD_CHECKSUMS:-0}" != "0" ] && checksum_flag="--data-checksums"

    pwfile="/tmp/.pg_pwfile_upgrade_${new_version}"
    echo "${PG_PASSWORD}" > "${pwfile}"
    chown "${WWWUSER}:${WWWGROUP}" "${pwfile}" && chmod 600 "${pwfile}"

    if ! su-exec "${WWWUSER}" "${new_bin}/initdb" --username=postgres --pwfile="${pwfile}" \
        --encoding="${OLD_ENCODING}" --lc-collate="${OLD_LC_COLLATE}" --lc-ctype="${OLD_LC_CTYPE}" \
        "${checksum_flag}" -D "${new_path}" >>"${postgres_log_file}" 2>&1; then
        UPGRADE_ERROR="initdb for the new cluster failed. Check ${postgres_log_file}."
        rm -f "${pwfile}"
        rm -rf "${new_path}" "${work_dir}"
        pg_cluster_log "FAILED: ${UPGRADE_ERROR}"
        return 1
    fi
    rm -f "${pwfile}"

    if ! su-exec "${WWWUSER}" sh -c "cd '${work_dir}' && '${new_bin}/pg_upgrade' --copy -b '${old_bin}' -B '${new_bin}' -d '${old_path}' -D '${new_path}' -U postgres --check" >>"${postgres_log_file}" 2>&1; then
        UPGRADE_ERROR="pg_upgrade compatibility check failed. Check ${postgres_log_file}."
        rm -rf "${new_path}" "${work_dir}"
        pg_cluster_log "FAILED: ${UPGRADE_ERROR}"
        return 1
    fi

    if ! su-exec "${WWWUSER}" sh -c "cd '${work_dir}' && '${new_bin}/pg_upgrade' --copy -b '${old_bin}' -B '${new_bin}' -d '${old_path}' -D '${new_path}' -U postgres" >>"${postgres_log_file}" 2>&1; then
        UPGRADE_ERROR="pg_upgrade failed. Check ${postgres_log_file}. The existing database is untouched."
        rm -rf "${new_path}" "${work_dir}"
        pg_cluster_log "FAILED: ${UPGRADE_ERROR}"
        return 1
    fi

    # pg_hba.conf/pg_ident.conf hold custom auth rules, not covered by
    # pg_upgrade; postgresql.auto.conf tuning settings are intentionally not
    # carried forward since the new major may have removed/renamed params.
    cp "${old_path}/pg_hba.conf" "${new_path}/pg_hba.conf" 2>/dev/null
    cp "${old_path}/pg_ident.conf" "${new_path}/pg_ident.conf" 2>/dev/null
    chown "${WWWUSER}:${WWWGROUP}" "${new_path}/pg_hba.conf" "${new_path}/pg_ident.conf" 2>/dev/null

    # Commit point: written last, after everything above succeeded. Nothing
    # before this line was visible to pg_cluster_find_active on a later boot.
    su-exec "${WWWUSER}" sh -c "date -u +%FT%TZ > '${new_path}/.m3ue-cluster-complete.tmp' && mv '${new_path}/.m3ue-cluster-complete.tmp' '${new_path}/.m3ue-cluster-complete'"

    rm -rf "${work_dir}"
    pg_cluster_log "Upgrade to ${new_version} complete. Previous ${old_version} cluster retained at ${old_path} for rollback."

    NEW_ACTIVE_PATH="${new_path}"
    return 0
}

# Only reached when POSTGRES_ROLLBACK=true and the requested version is
# older than what's active. Supports rolling back exactly one step, to the
# root cluster an upgrade retained - there is no reverse pg_upgrade, so this
# is a real downgrade: any writes made after the upgrade stay only in the
# pg<N>.rolled-back-<ts> directory this produces, never merged back in.
pg_cluster_rollback() {
    local active_version="$1" active_path="$2" target_version="$3"
    local root_version rolled_back_path

    root_version="$(pg_cluster_root_version)"
    if [ "${active_path}" = "${PG_MOUNT}" ] || [ "${root_version}" != "${target_version}" ]; then
        ROLLBACK_WARNING="POSTGRES_ROLLBACK=true requested a rollback to ${target_version}, but no retained cluster for that version exists. Staying on ${active_version}."
        return 1
    fi

    rolled_back_path="${active_path}.rolled-back-$(date -u +%Y%m%d%H%M%S)"
    mv "${active_path}" "${rolled_back_path}"
    pg_cluster_log "Rolled back from ${active_version} to ${target_version}. The ${active_version} cluster is retained at ${rolled_back_path} (not deleted - it holds any writes made after the upgrade)."

    NEW_ACTIVE_PATH="${PG_MOUNT}"
    return 0
}

# Main entry point: decides what to boot, exports PGDATA/POSTGRES_BIN_DIR/
# POSTGRES_ACTIVE_VERSION/PG_CLIENT_VERSION/POSTGRES_POST_UPGRADE for
# supervisord, db-init.sh and the app, and writes the status file the System
# Health page reads.
pg_cluster_prepare() {
    local requested="${POSTGRES_VERSION:-17}"
    local rollback_flag="${POSTGRES_ROLLBACK:-false}"
    local warning=""

    if ! pg_cluster_version_installed "${requested}"; then
        pg_cluster_log "WARNING: POSTGRES_VERSION=${requested} is not installed in this image. Ignoring it."
        warning="POSTGRES_VERSION=${requested} is not installed in this image; it was ignored."
        requested=""
    fi

    pg_cluster_cleanup_incomplete
    pg_cluster_find_active

    if [ -z "${ACTIVE_VERSION}" ]; then
        # Fresh install: no PGDATA yet at all.
        requested="${requested:-17}"
        pg_cluster_log "Initializing a new Postgres ${requested} data directory in ${PG_MOUNT}..."
        local pwfile="/tmp/.pg_pwfile"
        echo "${PG_PASSWORD}" > "${pwfile}"
        chown "${WWWUSER}:${WWWGROUP}" "${pwfile}" && chmod 600 "${pwfile}"
        if ! su-exec "${WWWUSER}" "$(pg_cluster_bin_dir "${requested}")/initdb" --username=postgres --pwfile="${pwfile}" -D "${PG_MOUNT}"; then
            echo "FATAL: initdb failed. Check permissions on ${PG_MOUNT}."
            rm -f "${pwfile}"
            exit 1
        fi
        rm -f "${pwfile}"
        ACTIVE_VERSION="${requested}"
        ACTIVE_PATH="${PG_MOUNT}"
        pg_cluster_write_status "none" "" "" "" "${warning}"
    elif [ -n "${requested}" ] && [ "${requested}" -gt "${ACTIVE_VERSION}" ]; then
        local previous_version="${ACTIVE_VERSION}"
        if pg_cluster_upgrade "${ACTIVE_VERSION}" "${ACTIVE_PATH}" "${requested}"; then
            ACTIVE_VERSION="${requested}"
            ACTIVE_PATH="${NEW_ACTIVE_PATH}"
            export POSTGRES_POST_UPGRADE="true"
            # Written after ACTIVE_VERSION/ACTIVE_PATH update above - the status
            # file's own active_version/active_path fields come from those
            # globals, so writing before the update would record the pre-upgrade
            # cluster as still "active" despite having just moved off it.
            pg_cluster_write_status "upgraded" "${previous_version}" "${requested}" "" "${warning}"
        else
            pg_cluster_write_status "failed" "${previous_version}" "${requested}" "${UPGRADE_ERROR}" "${warning}"
        fi
    elif [ -n "${requested}" ] && [ "${requested}" -lt "${ACTIVE_VERSION}" ]; then
        if [ "${rollback_flag}" = "true" ]; then
            local previous_version="${ACTIVE_VERSION}"
            if pg_cluster_rollback "${ACTIVE_VERSION}" "${ACTIVE_PATH}" "${requested}"; then
                ACTIVE_VERSION="${requested}"
                ACTIVE_PATH="${NEW_ACTIVE_PATH}"
                pg_cluster_write_status "rolled_back" "${previous_version}" "${requested}" "" "${warning}"
            else
                warning="${ROLLBACK_WARNING}"
                pg_cluster_log "WARNING: ${warning}"
                pg_cluster_read_previous_upgrade
                pg_cluster_write_status "${PREV_RESULT}" "${PREV_FROM}" "${PREV_TO}" "${PREV_MESSAGE}" "${warning}" "${PREV_AT}"
            fi
        else
            warning="POSTGRES_VERSION=${requested} is older than the running ${ACTIVE_VERSION} cluster. Staying on ${ACTIVE_VERSION} - set POSTGRES_VERSION=${ACTIVE_VERSION} to clear this warning, or POSTGRES_ROLLBACK=true alongside POSTGRES_VERSION=${requested} to actually roll back (this discards any writes made after the upgrade to ${ACTIVE_VERSION})."
            pg_cluster_log "WARNING: ${warning}"
            pg_cluster_read_previous_upgrade
            pg_cluster_write_status "${PREV_RESULT}" "${PREV_FROM}" "${PREV_TO}" "${PREV_MESSAGE}" "${warning}" "${PREV_AT}"
        fi
    else
        # Steady state: requested matches what's already active, nothing to
        # do. Carry the last real upgrade/rollback forward instead of
        # resetting it to "none" - this is the common case on every ordinary
        # restart once an install is settled on a version, so overwriting
        # history here would erase it almost as soon as it was written.
        pg_cluster_read_previous_upgrade
        pg_cluster_write_status "${PREV_RESULT}" "${PREV_FROM}" "${PREV_TO}" "${PREV_MESSAGE}" "${warning}" "${PREV_AT}"
    fi

    export PGDATA="${ACTIVE_PATH}"
    export POSTGRES_BIN_DIR="$(pg_cluster_bin_dir "${ACTIVE_VERSION}")"
    export POSTGRES_ACTIVE_VERSION="${ACTIVE_VERSION}"
    export PG_CLIENT_VERSION="${PG_CLIENT_VERSION:-${ACTIVE_VERSION}}"
}
