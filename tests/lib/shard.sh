#!/usr/bin/env bash
# Sharding helpers for tests/run.sh --shard N/M (sourced; also exercised
# directly by tests/test_shard_split.php).

SHARD_DEFAULT_WEIGHT=5

# shard_validate N/M - prints the reason and returns 1 unless 1 <= N <= M.
shard_validate() {
    if ! [[ "$1" =~ ^[1-9][0-9]*/[1-9][0-9]*$ ]]; then
        echo "--shard needs N/M with 1 <= N <= M, e.g. --shard 2/3." >&2
        return 1
    fi
    if [ "${1%/*}" -gt "${1#*/}" ]; then
        echo "--shard N/M needs N <= M (got $1)." >&2
        return 1
    fi
}

# shard_files N/M WEIGHTS_FILE FILE... - prints this shard's FILEs, one per
# line. Greedy longest-first packing by the seconds in WEIGHTS_FILE ("name
# secs" per line; files missing from it count as SHARD_DEFAULT_WEIGHT), so
# shards finish together. Deterministic, so every CI runner computes the same
# split on its own.
shard_files() {
    local spec="$1" weights_file="$2" name secs f n i target
    shift 2
    local -A weight=()
    while read -r name secs; do weight["$name"]="$secs"; done < "$weights_file"
    local -a load=()
    for ((i = 0; i < ${spec#*/}; i++)); do load[i]=0; done
    while read -r _ f; do
        target=0
        for i in "${!load[@]}"; do
            [ "${load[i]}" -lt "${load[target]}" ] && target=$i
        done
        n="$(basename "$f")"
        load[target]=$((load[target] + ${weight[$n]:-$SHARD_DEFAULT_WEIGHT}))
        [ "$target" -eq $((${spec%/*} - 1)) ] && echo "$f"
    done < <(for f in "$@"; do n="$(basename "$f")"; echo "${weight[$n]:-$SHARD_DEFAULT_WEIGHT} $f"; done | sort -k1,1nr -k2,2)
    return 0
}
