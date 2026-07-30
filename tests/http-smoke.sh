#!/usr/bin/env sh
set -eu

base_url="${1:-http://127.0.0.1:49174/index.php}"
work_dir="${2:-}"
owns_work_dir=0
if [ -z "$work_dir" ]; then
    work_dir="$(mktemp -d "${TMPDIR:-/tmp}/hiringband-http-smoke.XXXXXX")"
    owns_work_dir=1
fi
cleanup() {
    if [ "$owns_work_dir" -eq 1 ]; then
        rm -rf "$work_dir"
    fi
}
trap cleanup EXIT INT TERM

get_headers="$(curl -sS -D - -o "$work_dir/form.html" "$base_url?password=must-not-leak")"
printf '%s' "$get_headers" | grep -qi '^Cache-Control: no-store'
grep -q 'type="password"' "$work_dir/form.html"
grep -q 'action="/index.php"' "$work_dir/form.html"
! grep -q 'must-not-leak' "$work_dir/form.html"

form_status="$(curl -sS -o "$work_dir/form-post.html" -w '%{http_code}' \
  --data 'site=invalid&username=test&password=form-secret' "$base_url")"
[ "$form_status" = "200" ]
! grep -q 'form-secret' "$work_dir/form-post.html"

json_status="$(curl -sS -o "$work_dir/json.json" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  --data '{"site":"invalid","username":"test","password":"json-secret"}' "$base_url")"
[ "$json_status" = "400" ]
! grep -q 'json-secret' "$work_dir/json.json"

invalid_json_status="$(curl -sS -o "$work_dir/invalid.json" -w '%{http_code}' \
  -H 'Content-Type: application/json' --data '{' "$base_url")"
[ "$invalid_json_status" = "400" ]

method_status="$(curl -sS -o "$work_dir/method.json" -w '%{http_code}' -X PUT "$base_url")"
[ "$method_status" = "405" ]

printf 'HTTP smoke checks passed.\n'
