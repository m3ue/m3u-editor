#!/bin/bash
# generate-release-log.sh
#
# Generates release notes by finding the true version-bump commits on each branch
# via config/dev.php history (--first-parent). Does NOT rely on git tags.
#
# Usage:
#   ./tools/generate-release-log.sh                         # auto-detect: preview next release (unreleased changes)
#   ./tools/generate-release-log.sh v0.10.0-exp             # reproduce: show what was IN this release
#   ./tools/generate-release-log.sh v0.9.18-exp v0.10.0-exp # explicit: range between two named versions

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$(cd "$SCRIPT_DIR/.." && pwd)"

BRANCH=$(git rev-parse --abbrev-ref HEAD)

# ---------- Version-map helpers ----------
#
# build_version_map <remote_branch> <version_key>
#
# Scans config/dev.php history on the given branch (first-parent only, to stay
# on the branch mainline and ignore merged dev/feature commits).
# Emits one line per distinct version: "<version> <oldest-commit-with-that-version>"
# Output is newest-version-first.
#
build_version_map() {
    local remote_branch="$1"   # e.g. origin/experimental
    local version_key="$2"     # e.g. experimental_version

    local prev_ver="" last_hash="" hash ver

    while IFS= read -r hash; do
        ver=$(git show "${hash}:config/dev.php" 2>/dev/null \
              | grep "${version_key}" \
              | grep -oE "[0-9]+\.[0-9]+\.[0-9]+[-a-z]*" | head -1 || true)
        [[ -z "$ver" ]] && continue

        if [[ "$ver" != "$prev_ver" ]]; then
            # Emit the previous version's oldest commit when we transition away from it
            [[ -n "$prev_ver" && -n "$last_hash" ]] && echo "$prev_ver $last_hash"
            prev_ver="$ver"
        fi
        last_hash="$hash"
    done < <(git log "$remote_branch" --first-parent --format="%H" -- config/dev.php)

    # Emit the final (oldest) version
    [[ -n "$prev_ver" && -n "$last_hash" ]] && echo "$prev_ver $last_hash"
}

# lookup_version_commit <version_map_file> <version>
# Returns the oldest commit for a given version string.
lookup_version_commit() {
    local map_file="$1" target="$2"
    grep "^${target} " "$map_file" | awk '{print $2}' | head -1
}

# ---------- Determine branch config ----------

if [[ $# -ge 1 ]]; then
    # Infer branch/key from the version suffix
    first_arg="$1"
    if [[ "$first_arg" == *"-exp" ]]; then
        REMOTE_BRANCH="origin/experimental"
        VERSION_KEY="experimental_version"
        TAG_SUFFIX="-exp"
    elif [[ "$first_arg" == *"-dev" ]]; then
        REMOTE_BRANCH="origin/dev"
        VERSION_KEY="dev_version"
        TAG_SUFFIX="-dev"
    else
        echo "Error: version must end in -exp or -dev (e.g. v0.10.0-exp)" >&2
        exit 1
    fi
else
    if [[ "$BRANCH" == "experimental" ]]; then
        REMOTE_BRANCH="origin/experimental"
        VERSION_KEY="experimental_version"
        TAG_SUFFIX="-exp"
    elif [[ "$BRANCH" == "dev" ]]; then
        REMOTE_BRANCH="origin/dev"
        VERSION_KEY="dev_version"
        TAG_SUFFIX="-dev"
    else
        echo "Error: not on a known release branch (dev/experimental)." >&2
        echo "Pass a version explicitly: $0 v0.10.0-exp" >&2
        exit 1
    fi
fi

# ---------- Build the version map ----------

VERSION_MAP_FILE=$(mktemp)
trap 'rm -f "$VERSION_MAP_FILE"' EXIT

build_version_map "$REMOTE_BRANCH" "$VERSION_KEY" > "$VERSION_MAP_FILE"

# ---------- Resolve PREV_REF / HEAD_REF / PREV_PREV_REF ----------

if [[ $# -eq 2 ]]; then
    # Explicit: two version strings given
    prev_ver="${1#v}"   # strip leading 'v'
    curr_ver="${2#v}"
    PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$prev_ver")
    HEAD_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")

    # For dedup: find the version entry just after PREV in the map (= older version)
    PREV_PREV_REF=$(awk -v target="$prev_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")

    if [[ -z "$PREV_REF" || -z "$HEAD_REF" ]]; then
        echo "Error: could not find commit for one or both versions in $REMOTE_BRANCH history." >&2
        echo "Available versions:" >&2
        awk '{print "  v"$1}' "$VERSION_MAP_FILE" >&2
        exit 1
    fi

elif [[ $# -eq 1 ]]; then
    # Reproduce a specific release: find its commit and the previous version's commit
    curr_ver="${1#v}"
    HEAD_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")

    if [[ -z "$HEAD_REF" ]]; then
        echo "Error: version $curr_ver not found in $REMOTE_BRANCH history." >&2
        echo "Available versions:" >&2
        awk '{print "  v"$1}' "$VERSION_MAP_FILE" >&2
        exit 1
    fi

    # PREV_REF = oldest commit of the version immediately before curr_ver in the map
    PREV_REF=$(awk -v target="$curr_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")
    [[ -z "$PREV_REF" ]] && PREV_REF=$(git rev-list --max-parents=0 HEAD)

    # PREV_PREV_REF = version before PREV for dedup
    prev_ver=$(awk -v target="$curr_ver" '
        found { print $1; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")
    PREV_PREV_REF=$(awk -v target="$prev_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")

else
    # Auto-detect: show unreleased changes since the most recent release commit
    curr_ver=$(awk 'NR==1{print $1}' "$VERSION_MAP_FILE")
    HEAD_REF="HEAD"
    PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")
    [[ -z "$PREV_REF" ]] && PREV_REF=$(git rev-list --max-parents=0 HEAD)

    # PREV_PREV_REF = version before the current one
    prev_ver=$(awk 'NR==2{print $1}' "$VERSION_MAP_FILE")
    PREV_PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$prev_ver")
fi

echo "Branch      : $BRANCH"
echo "Remote      : $REMOTE_BRANCH"
echo "Range       : ${PREV_REF:0:12}..${HEAD_REF:0:12}"
echo "Dedup from  : ${PREV_PREV_REF:0:12}"
echo ""

# ---------- Build previous-release message set for cross-release dedup ----------

SEEN_FILE=$(mktemp)
PREV_MSGS_FILE=$(mktemp)
trap 'rm -f "$VERSION_MAP_FILE" "$SEEN_FILE" "$PREV_MSGS_FILE"' EXIT

normalize() {
    echo "$1" | sed 's/^[^[:alnum:]]*//' | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//'
}

if [[ -n "${PREV_PREV_REF:-}" ]]; then
    while IFS= read -r msg; do
        norm=$(normalize "$msg")
        echo "$norm" >> "$PREV_MSGS_FILE"
    done < <(git log "${PREV_PREV_REF}..${PREV_REF}" --no-merges --pretty=tformat:"%s")
fi

# ---------- Process commits in range ----------

features="" fixes="" perf="" refactor="" docs="" chores="" other=""

while IFS='|' read -r msg hash; do
    [[ "$msg" =~ ^[Vv]ersion\ bump ]] && continue
    [[ -z "$msg" ]] && continue

    norm=$(normalize "$msg")

    grep -qxF "$norm" "$SEEN_FILE"      && continue  # within-range duplicate
    grep -qxF "$norm" "$PREV_MSGS_FILE" && continue  # already in previous release

    echo "$norm" >> "$SEEN_FILE"

    line="- ${msg} (\`${hash}\`)"

    if [[ "$norm" =~ ^feat ]];     then features+="${line}"$'\n'
    elif [[ "$norm" =~ ^fix ]];    then fixes+="${line}"$'\n'
    elif [[ "$norm" =~ ^perf ]];   then perf+="${line}"$'\n'
    elif [[ "$norm" =~ ^refactor ]]; then refactor+="${line}"$'\n'
    elif [[ "$norm" =~ ^docs ]];   then docs+="${line}"$'\n'
    elif [[ "$norm" =~ ^chore ]];  then chores+="${line}"$'\n'
    else                                other+="${line}"$'\n'
    fi
done < <(git log "${PREV_REF}..${HEAD_REF}" --no-merges --pretty=tformat:"%s|%h")

# ---------- Output ----------

echo "## What's Changed"
echo ""

if [[ "${TAG_SUFFIX:-}" == "-exp" ]]; then
    echo "> [!WARNING]"
    echo "> This is an experimental release. Use at your own risk."
    echo ""
elif [[ "${TAG_SUFFIX:-}" == "-dev" ]]; then
    echo "> [!WARNING]"
    echo "> This is a dev/pre-release build. Use at your own risk."
    echo ""
fi

if [[ -n "$features" ]];  then echo "### Features";       echo -n "$features";  echo ""; fi
if [[ -n "$fixes" ]];     then echo "### Bug Fixes";      echo -n "$fixes";     echo ""; fi
if [[ -n "$perf" ]];      then echo "### Performance";    echo -n "$perf";      echo ""; fi
if [[ -n "$refactor" ]];  then echo "### Refactoring";    echo -n "$refactor";  echo ""; fi
if [[ -n "$docs" ]];      then echo "### Documentation";  echo -n "$docs";      echo ""; fi
if [[ -n "$chores" ]];    then echo "### Maintenance";    echo -n "$chores";    echo ""; fi
if [[ -n "$other" ]];     then echo "### Other Changes";  echo -n "$other";     echo ""; fi

if [[ -z "$features$fixes$perf$refactor$docs$chores$other" ]]; then
    echo "_No new changes found in range ${PREV_REF:0:12}..${HEAD_REF}_"
fi
