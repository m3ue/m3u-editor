#!/usr/bin/env bash

set -euo pipefail

ENV_FILE=".env.docker"

# We'll discover available compose files at runtime. Matches:
#  - docker-compose.yml
#  - docker-compose.*.yml
COMPOSE_FILES=()

find_compose_files() {
	# Enable nullglob so non-matching globs expand to nothing
	shopt -s nullglob
	local patterns=("docker-compose.yml" "docker-compose.*.yml")
	COMPOSE_FILES=()
	for p in "${patterns[@]}"; do
		for f in $p; do
			# only include regular files
			if [ -f "$f" ]; then
				COMPOSE_FILES+=("$f")
			fi
		done
	done
	shopt -u nullglob
}

# Ensure .env.docker exists (copy from .env.example if missing)
ENV_FILE=".env.docker"
if [ ! -f "${ENV_FILE}" ]; then
    echo "-- Missing environment file, creating now..."
    cp "${ENV_FILE}.example" "${ENV_FILE}"
fi

print_menu() {
	# print directly to the controlling terminal so the menu isn't captured
	# by command substitution that collects the function's stdout
	exec 3>/dev/tty
	echo "Select which compose file to build & run:" >&3
	local i=1
	for f in "${COMPOSE_FILES[@]}"; do
		echo "$i) $f" >&3
		i=$((i+1))
	done >&3
	echo "q) Quit" >&3
	exec 3>&-
}

choose_from_arg_or_prompt() {
	# allow passing a choice as the first argument (index or filename)
	if [ "$#" -gt 0 ] && [ -n "${1:-}" ]; then
		arg="$1"
		# quit words
		case "${arg,,}" in
			q|quit|exit) echo q ; return 0 ;;
		esac

		# if it's a number index
		if printf "%s" "$arg" | grep -Eq '^[0-9]+$'; then
			echo "$arg"
			return 0
		fi

		# if it's exact filename present in COMPOSE_FILES, return its 1-based index
		for i in "${!COMPOSE_FILES[@]}"; do
			if [ "${COMPOSE_FILES[$i]}" = "$arg" ] || [ "$(basename "${COMPOSE_FILES[$i]}")" = "$arg" ]; then
				echo $((i+1))
				return 0
			fi
		done
		# otherwise fallthrough to interactive or default
	fi

	# interactive prompt if running in a TTY (read from /dev/tty so prompt is visible)
	if [ -t 0 ] || [ -t 1 ]; then
		print_menu
		local prompt_range="1-$((${#COMPOSE_FILES[@]}))"
		if read -rp "Enter choice [${prompt_range}/q] (default 1): " choice </dev/tty; then
			choice="${choice:-1}"
			echo "$choice"
			return 0
		fi
	fi

	# non-interactive default
	echo 1
}

run_compose() {
	local file="$1"
	echo "Building ${file} (no-cache) and starting..."
	docker compose --env-file "$ENV_FILE" -f "$file" build --no-cache --build-arg GIT_BRANCH=local --build-arg GIT_COMMIT=local --build-arg GIT_TAG=local
	docker compose --env-file "$ENV_FILE" -f "$file" up --remove-orphans
}

find_compose_files
if [ "${#COMPOSE_FILES[@]}" -eq 0 ]; then
	echo "No docker-compose files found (looked for docker-compose.yml and docker-compose.*.yml)" >&2
	exit 2
fi

# If there's exactly one compose file and no args, run it directly
if [ "${#COMPOSE_FILES[@]}" -eq 1 ] && [ "$#" -eq 0 ]; then
	echo "Only one compose file found: ${COMPOSE_FILES[0]} -- running it"
	run_compose "${COMPOSE_FILES[0]}"
	exit 0
fi

CHOICE=$(choose_from_arg_or_prompt "$@")

case "$CHOICE" in
	q|Q)
		echo "Cancelled."
		exit 0
		;;
	*)
		# If numeric, map to array (1-based)
		if printf "%s" "$CHOICE" | grep -Eq '^[0-9]+$'; then
			idx=$((CHOICE-1))
			if [ "$idx" -ge 0 ] && [ "$idx" -lt "${#COMPOSE_FILES[@]}" ]; then
				run_compose "${COMPOSE_FILES[$idx]}"
				exit 0
			else
				echo "Choice out of range: $CHOICE" >&2
				exit 2
			fi
		else
			echo "Unrecognized choice: $CHOICE" >&2
			exit 2
		fi
		;;
esac
