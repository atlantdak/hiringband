#!/usr/bin/env sh
set -eu

port="${HTTP_SMOKE_PORT:-}"
if [ -z "$port" ]; then
    port="$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); if ($socket === false) { exit(1); } echo parse_url("tcp://" . stream_socket_get_name($socket, false), PHP_URL_PORT); fclose($socket);')"
fi
base_url="http://127.0.0.1:${port}/index.php"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/hiringband-http-run.XXXXXX")"
log_file="$work_dir/server.log"
server_pid=

php -S "127.0.0.1:${port}" -t . >"$log_file" 2>&1 &
server_pid=$!
cleanup() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

attempt=0
until curl -s -o /dev/null "$base_url"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 20 ]; then
        printf 'Temporary PHP server did not start. Log:\n' >&2
        cat "$log_file" >&2
        exit 1
    fi
    sleep 0.1
done

sh tests/http-smoke.sh "$base_url" "$work_dir"
