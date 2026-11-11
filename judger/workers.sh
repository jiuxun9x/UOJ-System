#!/bin/bash
# Writes a docker compose file that runs several judgers on this machine, each on a CPU of its
# own, all working for the site under one judging account (judge protocol 3 keeps them apart:
# every judger is a worker with a name of its own, kept in its own log folder).
#
#   bash judger/workers.sh --url https://oj.example.edu.cn --name lab3 --password '...' \
#       [--workers 4 | --cpus 2-7] [--memory 4g] [--image school-oj-judger]
#   docker compose -f docker-compose.judgers.yml up -d
#
# How many judgers is for whoever runs it to say, but whatever is said, every judger gets a CPU
# of its own and memory of its own, and no more judgers are written than the machine can take:
#  - the judgers are put on one CPU of every physical core but the one of CPU 0: two hyperthreads
#    of one core slow each other down, which shows in the times that are measured, and CPU 0
#    serves the interrupts of the machine. More judgers than that are refused.
#  - CPUs that are named (--cpus) are checked: none twice, none that is not there, no two
#    hyperthreads of one core (unless --allow-smt is given, with a warning).
#  - every judger gets as much memory, with no swap: by default what the machine has but 2 GB,
#    shared out. Less than 2 GB a judger is refused: a judger that runs out of memory would have
#    its programs killed, and they would be judged wrongly. (A judger also gives back a problem
#    whose memory limit it can not hold, see judge_client.)

set -e

usage() {
    cat >&2 <<'EOF'
usage: bash judger/workers.sh --url URL --name NAME --password PASSWORD [options]

  --url URL           the address of the site, as the page of the judging accounts shows it
  --name NAME         the judging account, made on the site: 系统管理 → 评测机管理
  --password PASSWORD its password (or the environment variable JUDGER_PASSWORD)
  --workers N         how many judgers, each on a CPU of its own (default: as many as there
                      are physical cores, but the first)
  --cpus LIST         the CPUs to judge on instead, one judger on each: 2,3,4 or 2-5 or 1-3,8-11
  --allow-smt         allow two judgers on the two hyperthreads of one core (times suffer)
  --memory SIZE       the memory of each judger, such as 4g, with no swap, at least 2g
                      (default: the memory of the machine but 2 GB, shared out)
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

SYS_CPU="${UOJ_SYSFS_CPU:-/sys/devices/system/cpu}"

# the CPUs that are online, or nothing where that can not be read
online_cpus() {
    local online
    online="$(cat "$SYS_CPU/online" 2>/dev/null)" || return 0
    expand_cpus "$online" | tr '\n' ' '
}

# the physical core of a CPU: the first of the hyperthreads it is one of
core_of() {
    local siblings
    siblings="$(cat "$SYS_CPU/cpu$1/topology/thread_siblings_list" 2>/dev/null || echo "$1")"
    expand_cpus "$siblings" | head -n 1
}

# one CPU of every physical core but the one of CPU 0 (all of them where there is one core): the
# first of its hyperthreads that is online (the first of them was taken, online or not)
default_cpus() {
    local cpu first seen="" chosen="" zero=""
    for cpu in $(online_cpus); do
        first="$(core_of "$cpu")"
        case " $seen " in
            *" $first "*) continue ;;
        esac
        seen="$seen $first"
        if [ "$first" = "$(core_of 0)" ]; then
            zero="$cpu"
        else
            chosen="$chosen $cpu"
        fi
    done
    if [ -z "$chosen" ]; then
        chosen="$zero"
    fi
    if [ -z "$chosen" ]; then
        chosen=0
    fi
    echo $chosen
}

# megabytes of a size such as 4g, 512m or 4096
megabytes() {
    local number="${1%[bkmgBKMG]}" unit="${1##*[0-9]}"
    case "$unit" in
        g | G) echo $((number * 1024)) ;;
        m | M | "") echo "$number" ;;
        k | K) echo $((number / 1024)) ;;
        b | B) echo $((number / 1048576)) ;;
    esac
}

# megabytes of memory the machine has, or nothing where that can not be read
machine_megabytes() {
    awk '/^MemTotal:/ { print int($2 / 1024) }' "${UOJ_PROC_MEMINFO:-/proc/meminfo}" 2>/dev/null || true
}

# the least memory a judger is given, and what is left to the machine itself
MIN_JUDGER_MB=2048
MACHINE_RESERVE_MB=2048

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
ALLOW_SMT=""
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
        --allow-smt) ALLOW_SMT=1; shift ;;
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

if [ -n "$WORKERS" ] && ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]]; then
    echo "--workers is a number: $WORKERS" >&2
    exit 2
fi
if [ -n "$CPUS" ]; then
    if ! LIST="$(expand_cpus "$CPUS")" || [ -z "$LIST" ]; then
        echo "--cpus is a list of CPUs such as 2,3,4 or 2-5: $CPUS" >&2
        exit 2
    fi
    if [ "$(echo "$LIST" | sort | uniq -d)" != "" ]; then
        echo "--cpus names a CPU twice: two judgers on one CPU would slow each other down" >&2
        exit 2
    fi
    ONLINE="$(online_cpus)"
    CORES=""
    for cpu in $LIST; do
        if [ -n "$ONLINE" ] && ! [[ " $ONLINE " == *" $cpu "* ]]; then
            echo "--cpus names CPU $cpu, which this machine does not have; it has: $ONLINE" >&2
            exit 2
        fi
        core="$(core_of "$cpu")"
        if [[ " $CORES " == *" $core "* ]]; then
            if [ -z "$ALLOW_SMT" ]; then
                echo "--cpus puts two judgers on one physical core (CPU $cpu is a hyperthread of CPU $core):" >&2
                echo "they would slow each other down. Name one CPU of each core, or add --allow-smt." >&2
                exit 2
            fi
            echo "warning: two judgers share the physical core of CPU $core; the times they measure suffer" >&2
        fi
        CORES="$CORES $core"
        if [ "$core" = "$(core_of 0)" ]; then
            echo "warning: CPU $cpu is on the core of CPU 0, which also serves the interrupts of the machine" >&2
        fi
    done
    LIST="$(echo $LIST | tr '\n' ' ')"
    if [ -n "$WORKERS" ]; then
        LIST="$(echo $LIST | tr ' ' '\n' | head -n "$WORKERS" | tr '\n' ' ')"
    fi
else
    LIST="$(default_cpus)"
    if [ -n "$WORKERS" ]; then
        set -- $LIST
        if [ "$WORKERS" -gt $# ]; then
            echo "this machine can take $# judger(s): one on each physical core but the one of CPU 0 ($LIST)." >&2
            echo "More would share cores, and the times they measure would suffer. Use --cpus to choose them yourself." >&2
            exit 2
        fi
        LIST="$(echo $LIST | tr ' ' '\n' | head -n "$WORKERS" | tr '\n' ' ')"
    fi
fi
set -- $LIST
COUNT=$#
LIST="$*"

if [ -n "$MEMORY" ]; then
    MEMORY_MB="$(megabytes "$MEMORY")"
    if [ "$MEMORY_MB" -lt "$MIN_JUDGER_MB" ]; then
        echo "--memory $MEMORY is too little for a judger: at least ${MIN_JUDGER_MB} MB (2g)" >&2
        exit 2
    fi
else
    TOTAL_MB="$(machine_megabytes)"
    if [ -z "$TOTAL_MB" ]; then
        echo "how much memory this machine has can not be read: say how much each judger gets with --memory" >&2
        exit 2
    fi
    MEMORY_MB=$(((TOTAL_MB - MACHINE_RESERVE_MB) / COUNT))
    if [ "$MEMORY_MB" -lt "$MIN_JUDGER_MB" ]; then
        echo "this machine has ${TOTAL_MB} MB: with ${MACHINE_RESERVE_MB} MB left to it, $COUNT judger(s) would get ${MEMORY_MB} MB each," >&2
        echo "less than the ${MIN_JUDGER_MB} MB a judger needs. Run fewer judgers (--workers)." >&2
        exit 2
    fi
fi
MEMORY="${MEMORY_MB}m"

write() {
    echo "# made by judger/workers.sh: $COUNT judger(s) for $URL as the judging account $NAME,"
    echo "# on the CPUs $LIST, with $MEMORY of memory each. It holds the password of the account:"
    echo "# keep it to yourself."
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
        echo "    mem_limit: $MEMORY"
        echo "    memswap_limit: $MEMORY"
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
    echo "wrote $OUTPUT: $COUNT judger(s) on the CPUs $LIST, $MEMORY of memory each, as the judging account $NAME" >&2
    echo "start them with: docker compose -f $OUTPUT up -d" >&2
fi
