#!/bin/bash
# Writes a docker compose file that runs several judgers on this machine, each on a CPU of its
# own, all working for the site under one judging account (judge protocol 3 keeps them apart:
# every judger is a worker with a name of its own, kept in its own log folder).
#
#   bash judger/workers.sh --url https://oj.example.edu.cn --name lab3 --password '...' \
#       [--cpus 2-7] [--memory 4g] [--image school-oj-judger] [--output docker-compose.judgers.yml]
#   docker compose -f docker-compose.judgers.yml up -d
#
# Without --cpus, one judger is put on one CPU of every physical core but the one of CPU 0: two
# hyperthreads of one core slow each other down, which shows in the times that are measured, and
# CPU 0 serves the interrupts of the machine.

set -e

usage() {
    cat >&2 <<'EOF'
usage: bash judger/workers.sh --url URL --name NAME --password PASSWORD [options]

  --url URL           the address of the site, as the page of the judging accounts shows it
  --name NAME         the judging account, made on the site: 系统管理 → 评测机管理
  --password PASSWORD its password (or the environment variable JUDGER_PASSWORD)
  --cpus LIST         the CPUs to judge on, one judger on each: 2,3,4 or 2-5 or 1-3,8-11
                      (default: one CPU of every physical core but the first)
  --workers N         only the first N of these CPUs
  --memory SIZE       the memory of each judger, such as 4g, with no swap (default: no limit)
  --image IMAGE       the image of the judger (default: school-oj-judger)
  --prefix NAME       the names of the containers start with it (default: uoj-judger)
  --log-dir DIR       the logs of the judgers, one folder each (default: ./judger-workers)
  --add-host HOST:IP  a name the judgers resolve to an address; may be given more than once
  --env KEY=VALUE     a setting for every judger, such as DATA_CACHE_PROBLEMS=1000
  --output FILE       where the compose file goes (default: docker-compose.judgers.yml, - for
                      the standard output)
EOF
    exit 2
}

# the CPUs of a list such as 0-3,8,10-11, one a line
expand_cpus() {
    local part from to
    local IFS=,
    for part in $1; do
        part="${part//[[:space:]]/}"
        [ -z "$part" ] && continue
        if [[ "$part" =~ ^([0-9]+)-([0-9]+)$ ]]; then
            from="${BASH_REMATCH[1]}"
            to="${BASH_REMATCH[2]}"
            [ "$from" -le "$to" ] || return 1
            seq "$from" "$to"
        elif [[ "$part" =~ ^[0-9]+$ ]]; then
            echo "$part"
        else
            return 1
        fi
    done
}

# one CPU of every physical core but the one of CPU 0 (all of them where there is one core)
default_cpus() {
    local sys="${UOJ_SYSFS_CPU:-/sys/devices/system/cpu}"
    local online cpu siblings first seen="" chosen="" zero=""
    online="$(cat "$sys/online" 2>/dev/null || echo 0)"
    for cpu in $(expand_cpus "$online"); do
        siblings="$(cat "$sys/cpu$cpu/topology/thread_siblings_list" 2>/dev/null || echo "$cpu")"
        first="$(expand_cpus "$siblings" | head -n 1)"
        case " $seen " in
            *" $first "*) continue ;;
        esac
        seen="$seen $first"
        if expand_cpus "$siblings" | grep -qx 0; then
            zero="$first"
        else
            chosen="$chosen $first"
        fi
    done
    if [ -z "$chosen" ]; then
        chosen="$zero"
    fi
    echo $chosen
}

# a value in single quotes, as YAML reads it, with a $ that compose does not take for a variable
quote() {
    local q="'" value="${1//\$/\$\$}"
    printf "'%s'" "${value//$q/$q$q}"
}

URL=""
NAME=""
PASSWORD="${JUDGER_PASSWORD:-}"
CPUS=""
WORKERS=""
MEMORY=""
IMAGE="school-oj-judger"
PREFIX="uoj-judger"
LOG_DIR="./judger-workers"
OUTPUT="docker-compose.judgers.yml"
HOSTS=()
ENVS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="$2"; shift 2 ;;
        --name) NAME="$2"; shift 2 ;;
        --password) PASSWORD="$2"; shift 2 ;;
        --cpus) CPUS="$2"; shift 2 ;;
        --workers) WORKERS="$2"; shift 2 ;;
        --memory) MEMORY="$2"; shift 2 ;;
        --image) IMAGE="$2"; shift 2 ;;
        --prefix) PREFIX="$2"; shift 2 ;;
        --log-dir) LOG_DIR="$2"; shift 2 ;;
        --add-host) HOSTS+=("$2"); shift 2 ;;
        --env) ENVS+=("$2"); shift 2 ;;
        --output) OUTPUT="$2"; shift 2 ;;
        -h | --help) usage ;;
        *) echo "unknown option: $1" >&2; usage ;;
    esac
done

if [ -z "$URL" ] || [ -z "$NAME" ] || [ -z "$PASSWORD" ]; then
    echo "the address of the site, the name of the judging account and its password are needed" >&2
    usage
fi
if ! [[ "$URL" =~ ^https?://[^/[:space:]]+/?$ ]]; then
    echo "--url is the address of the site, such as https://oj.example.edu.cn: $URL" >&2
    exit 2
fi
if ! [[ "$NAME" =~ ^[A-Za-z0-9_]{1,20}$ ]]; then
    echo "--name is the name of a judging account: 1 to 20 letters, digits and underscores" >&2
    exit 2
fi
if ! [[ "$PREFIX" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "--prefix may hold letters, digits, '_', '.' and '-': $PREFIX" >&2
    exit 2
fi
if [ -n "$MEMORY" ] && ! [[ "$MEMORY" =~ ^[0-9]+[bkmgBKMG]?$ ]]; then
    echo "--memory is a size such as 4g: $MEMORY" >&2
    exit 2
fi
for entry in "${ENVS[@]}"; do
    if ! [[ "$entry" =~ ^[A-Z_][A-Z0-9_]*= ]]; then
        echo "--env is KEY=VALUE: $entry" >&2
        exit 2
    fi
done
for entry in "${HOSTS[@]}"; do
    if ! [[ "$entry" =~ ^[A-Za-z0-9.-]+:[A-Za-z0-9.:-]+$ ]]; then
        echo "--add-host is HOST:IP: $entry" >&2
        exit 2
    fi
done

if [ -n "$CPUS" ]; then
    if ! LIST="$(expand_cpus "$CPUS")" || [ -z "$LIST" ]; then
        echo "--cpus is a list of CPUs such as 2,3,4 or 2-5: $CPUS" >&2
        exit 2
    fi
    LIST="$(echo "$LIST" | awk '!seen[$0]++' | tr '\n' ' ')"
else
    LIST="$(default_cpus)"
fi
if [ -n "$WORKERS" ]; then
    if ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]]; then
        echo "--workers is a number: $WORKERS" >&2
        exit 2
    fi
    LIST="$(echo $LIST | tr ' ' '\n' | head -n "$WORKERS" | tr '\n' ' ')"
fi
set -- $LIST
COUNT=$#
LIST="$*"

write() {
    echo "# made by judger/workers.sh: $COUNT judger(s) for $URL as the judging account $NAME,"
    echo "# on the CPUs $LIST. It holds the password of the account: keep it to yourself."
    echo "#"
    echo "#   docker compose -f $([ "$OUTPUT" = "-" ] && echo docker-compose.judgers.yml || basename "$OUTPUT") up -d"
    echo ""
    echo "services:"
    for cpu in "$@"; do
        local service="$PREFIX-cpu$cpu"
        echo "  $service:"
        echo "    image: $(quote "$IMAGE")"
        echo "    pull_policy: never"
        echo "    container_name: $service"
        echo "    restart: always"
        echo "    stdin_open: true"
        echo "    tty: true"
        echo "    cap_add:"
        echo "      - SYS_PTRACE"
        echo "    cpuset: \"$cpu\""
        if [ -n "$MEMORY" ]; then
            echo "    mem_limit: $MEMORY"
            echo "    memswap_limit: $MEMORY"
        fi
        echo "    volumes:"
        echo "      - $(quote "${LOG_DIR%/}/cpu$cpu:/opt/uoj_judger/log")"
        echo "    environment:"
        echo "      - $(quote "UOJ_SERVER_URL=${URL%/}")"
        echo "      - $(quote "JUDGER_NAME=$NAME")"
        echo "      - $(quote "JUDGER_PASSWORD=$PASSWORD")"
        echo "      - $(quote "WORKER_LABEL=cpu$cpu")"
        for entry in "${ENVS[@]}"; do
            echo "      - $(quote "$entry")"
        done
        if [ ${#HOSTS[@]} -gt 0 ]; then
            echo "    extra_hosts:"
            for entry in "${HOSTS[@]}"; do
                echo "      - $(quote "$entry")"
            done
        fi
    done
}

if [ "$OUTPUT" = "-" ]; then
    write "$@"
else
    umask 077
    write "$@" > "$OUTPUT"
    echo "wrote $OUTPUT: $COUNT judger(s) on the CPUs $LIST, as the judging account $NAME" >&2
    echo "start them with: docker compose -f $OUTPUT up -d" >&2
fi
