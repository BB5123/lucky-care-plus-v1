#!/usr/bin/env bash
#
# LuckyCare+ post-deploy smoke check.
#
# Runs the same flow a real client would against a running deployment:
#
#   1. GET /up                                   (health)
#   2. owner login + dev OTP request/verify      (auth surface)
#   3. GET /api/v1/me                            (identity + memberships)
#   4. GET /api/v1/workspaces/{id}               (at least one workspace)
#   5. GET /api/v1/workspaces/{id}/profiles      (the profile picker)
#   6. dashboard                                 (non-empty aggregate)
#   7. timeline                                  (non-empty read model)
#   8. care tasks                                (non-empty list)
#   9. appointments                              (non-empty list)
#  10. viewer -> owner-only path                  (must be 403, not 200)
#
# The demo:seed command creates the three synthetic accounts below. They are
# obviously fake (`example.test`, "ทดสอบ") and are not secrets; override them
# with environment variables or flags when checking another workspace.
#
# The task text asks for `GET /api/v1/workspaces`; this API exposes the
# workspace list through `/api/v1/me` (there is no collection route), so step 4
# reads that list and then proves the workspace detail endpoint. The OTP
# request/verify endpoints require an existing bearer token in this API, so the
# token is obtained with the seeded password first and the OTP round-trip is
# exercised with it. With OTP_DEMO_MODE=false the server does not expose the
# code, so verification is reported as skipped rather than guessed.
#
# Usage:
#
#   bash backend/scripts/smoke.sh --base-url=http://localhost:8080
#   bash backend/scripts/smoke.sh --base-url=http://localhost:8080 --verbose
#
# Exit code 0 means every step passed. The script stops at the first failure.
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
VERBOSE=0

OWNER_EMAIL="${DEMO_OWNER_EMAIL:-demo.owner@example.test}"
OWNER_PASSWORD="${DEMO_OWNER_PASSWORD:-DemoPass!2026}"
OWNER_PHONE="${DEMO_OWNER_PHONE:-0800000001}"
VIEWER_EMAIL="${DEMO_VIEWER_EMAIL:-demo.viewer@example.test}"
VIEWER_PASSWORD="${DEMO_VIEWER_PASSWORD:-DemoPass!2026}"

TOTAL_STEPS=10

usage() {
  cat <<'USAGE'
LuckyCare+ smoke check

Usage:
  bash backend/scripts/smoke.sh [options]

Options:
  --base-url=URL          API base URL (default: http://localhost:8080)
  --verbose               Print every request and response body (may contain
                          synthetic health data; use it against the demo only)
  --owner-email=EMAIL     Demo owner account (default: demo.owner@example.test)
  --owner-password=PASS   Demo owner password (default: DemoPass!2026)
  --owner-phone=PHONE     Demo owner phone for the OTP round-trip
  --viewer-email=EMAIL    Demo viewer account (default: demo.viewer@example.test)
  --viewer-password=PASS  Demo viewer password (default: DemoPass!2026)
  --help, -h              Show this help

Environment variables with the same defaults:
  BASE_URL, DEMO_OWNER_EMAIL, DEMO_OWNER_PASSWORD, DEMO_OWNER_PHONE,
  DEMO_VIEWER_EMAIL, DEMO_VIEWER_PASSWORD

The script needs only curl and php (no jq). It is safe to run repeatedly: it
never writes demo data, and the OTP/login rate limits are per endpoint.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --base-url=*)
      BASE_URL="${1#*=}"
      shift
      ;;
    --base-url)
      [ "$#" -ge 2 ] || { echo "FAIL: --base-url needs a value" >&2; exit 2; }
      BASE_URL="$2"
      shift 2
      ;;
    --verbose)
      VERBOSE=1
      shift
      ;;
    --owner-email=*)
      OWNER_EMAIL="${1#*=}"
      shift
      ;;
    --owner-email)
      [ "$#" -ge 2 ] || { echo "FAIL: --owner-email needs a value" >&2; exit 2; }
      OWNER_EMAIL="$2"
      shift 2
      ;;
    --owner-password=*)
      OWNER_PASSWORD="${1#*=}"
      shift
      ;;
    --owner-password)
      [ "$#" -ge 2 ] || { echo "FAIL: --owner-password needs a value" >&2; exit 2; }
      OWNER_PASSWORD="$2"
      shift 2
      ;;
    --owner-phone=*)
      OWNER_PHONE="${1#*=}"
      shift
      ;;
    --owner-phone)
      [ "$#" -ge 2 ] || { echo "FAIL: --owner-phone needs a value" >&2; exit 2; }
      OWNER_PHONE="$2"
      shift 2
      ;;
    --viewer-email=*)
      VIEWER_EMAIL="${1#*=}"
      shift
      ;;
    --viewer-email)
      [ "$#" -ge 2 ] || { echo "FAIL: --viewer-email needs a value" >&2; exit 2; }
      VIEWER_EMAIL="$2"
      shift 2
      ;;
    --viewer-password=*)
      VIEWER_PASSWORD="${1#*=}"
      shift
      ;;
    --viewer-password)
      [ "$#" -ge 2 ] || { echo "FAIL: --viewer-password needs a value" >&2; exit 2; }
      VIEWER_PASSWORD="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "FAIL: unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

BASE_URL="${BASE_URL%/}"

command -v curl >/dev/null 2>&1 || { echo "FAIL: curl is required" >&2; exit 1; }

# Linux images expose `php`; a WSL/Git Bash session on Windows may expose the
# binary as `php.exe`. Both are the same interpreter; JSON parsing is the only
# thing the script needs from it (there is no jq dependency).
if command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
elif command -v php.exe >/dev/null 2>&1; then
  PHP_BIN="php.exe"
else
  echo "FAIL: php (or php.exe) is required for JSON parsing (no jq dependency)" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/lc-smoke-XXXXXX")"
cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

STEP=0
HTTP_STATUS=""
HTTP_BODY=""

step() {
  STEP=$((STEP + 1))
  printf '[%d/%d] %-44s ' "$STEP" "$TOTAL_STEPS" "$1"
}

pass() {
  printf 'PASS (%s)\n' "$1"
}

fail() {
  printf 'FAIL (%s)\n' "$1" >&2
  exit 1
}

# Read a dot path from JSON on stdin. Keeps the script free of a jq dependency.
# `data.workspaces.0.id` is supported because PHP casts numeric string keys.
json_get() {
  local path="$1"

  "$PHP_BIN" -r '
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        exit(1);
    }

    foreach (explode(".", $argv[1]) as $key) {
        if ($key === "") {
            continue;
        }

        if (!is_array($data) || !array_key_exists($key, $data)) {
            exit(1);
        }

        $data = $data[$key];
    }

    if (is_array($data) || is_object($data)) {
        echo json_encode($data);
        return;
    }

    if (is_bool($data)) {
        echo $data ? "true" : "false";
        return;
    }

    if ($data === null) {
        return;
    }

    echo $data;
  ' "$path"
}

# Perform one request and expose HTTP_STATUS / HTTP_BODY.
http() {
  local method="$1"
  local url="$2"
  local token="${3:-}"
  local payload="${4:-}"
  local out="$TMP_DIR/body"

  local args=(-sS --max-time 30 -o "$out" -w '%{http_code}' -X "$method" "$url" -H 'Accept: application/json')

  if [ -n "$token" ]; then
    args+=(-H "Authorization: Bearer $token")
  fi

  if [ -n "$payload" ]; then
    args+=(-H 'Content-Type: application/json' --data "$payload")
  fi

  if ! HTTP_STATUS="$(curl "${args[@]}")"; then
    HTTP_STATUS="000"
    HTTP_BODY=""
    return 1
  fi

  HTTP_BODY="$(cat "$out")"
  rm -f "$out"

  if [ "$VERBOSE" -eq 1 ]; then
    # stderr keeps the request log out of `$(login ...)`, whose stdout must
    # contain only the bearer token.
    printf '   -> %s %s [%s]\n' "$method" "$url" "$HTTP_STATUS" >&2
    printf '%s\n' "$HTTP_BODY" | sed 's/^/      /' >&2
  fi

  return 0
}

assert_status() {
  local expected="$1"
  local label="$2"

  if [ "$HTTP_STATUS" != "$expected" ]; then
    local detail
    detail="$(printf '%s' "$HTTP_BODY" | head -c 400)"
    fail "$label returned HTTP $HTTP_STATUS (expected $expected): $detail"
  fi
}

data_is_non_empty() {
  local body="$1"
  local data

  data="$(printf '%s' "$body" | json_get "data")" || return 1

  case "$data" in
    ""|"[]"|"{}"|"null") return 1 ;;
  esac

  return 0
}

login() {
  local email="$1"
  local password="$2"
  local payload

  payload="$("$PHP_BIN" -r 'echo json_encode(["email" => $argv[1], "password" => $argv[2]]);' "$email" "$password")"

  http POST "$BASE_URL/api/v1/auth/login" "" "$payload"
  assert_status 200 "POST /api/v1/auth/login ($email)"

  printf '%s' "$HTTP_BODY" | json_get "data.token"
}

echo "LuckyCare+ smoke check"
echo "  base URL: $BASE_URL"
echo "  owner:    $OWNER_EMAIL"
echo "  viewer:   $VIEWER_EMAIL"

if [ "$VERBOSE" -eq 1 ]; then
  echo "  WARNING:  --verbose prints response bodies (synthetic health data); demo use only."
fi

echo

# 1. Health ---------------------------------------------------------------
step "GET /up"
http GET "$BASE_URL/up"
assert_status 200 "GET /up"
pass "HTTP 200"

# 2. Owner login + dev OTP ------------------------------------------------
step "owner login + dev OTP round-trip"
OWNER_TOKEN="$(login "$OWNER_EMAIL" "$OWNER_PASSWORD")" || fail "owner login did not return a token"
[ -n "$OWNER_TOKEN" ] || fail "owner login returned an empty token"

otp_payload="$("$PHP_BIN" -r 'echo json_encode(["phone" => $argv[1]]);' "$OWNER_PHONE")"
http POST "$BASE_URL/api/v1/auth/otp/request" "$OWNER_TOKEN" "$otp_payload"
assert_status 202 "POST /api/v1/auth/otp/request"

DEMO_CODE="$(printf '%s' "$HTTP_BODY" | json_get "data.demo_code" 2>/dev/null || true)"

if [ -n "$DEMO_CODE" ]; then
  verify_payload="$("$PHP_BIN" -r 'echo json_encode(["phone" => $argv[1], "code" => $argv[2]]);' "$OWNER_PHONE" "$DEMO_CODE")"
  http POST "$BASE_URL/api/v1/auth/otp/verify" "$OWNER_TOKEN" "$verify_payload"
  assert_status 200 "POST /api/v1/auth/otp/verify"
  pass "login 200, token captured; OTP request 202, verify 200"
else
  pass "login 200, token captured; OTP request 202; verify skipped (OTP_DEMO_MODE is off)"
fi

# 3. Identity + memberships ----------------------------------------------
step "GET /api/v1/me"
http GET "$BASE_URL/api/v1/me" "$OWNER_TOKEN"
assert_status 200 "GET /api/v1/me"

OWNER_ROLE="$(printf '%s' "$HTTP_BODY" | json_get "data.workspaces.0.role")" || fail "GET /me has no workspace role"
[ -n "$OWNER_ROLE" ] || fail "GET /me returned an empty owner role"

# Safety guard: this script is for the synthetic fixture. A real workspace must
# never be mistaken for the demo just because someone pointed the base URL or
# credentials at a production deployment.
WORKSPACE_NAME="$(printf '%s' "$HTTP_BODY" | json_get "data.workspaces.0.name" 2>/dev/null || true)"
case "$WORKSPACE_NAME" in
  *"[DEMO]"*) ;;
  *) fail "The signed-in workspace is not marked [DEMO]; refusing to smoke a non-demo deployment" ;;
esac

pass "HTTP 200, role=$OWNER_ROLE, demo workspace"

# 4. At least one workspace ----------------------------------------------
step "workspace membership + detail"
WORKSPACE_ID="$(printf '%s' "$HTTP_BODY" | json_get "data.workspaces.0.id")" || fail "GET /me has no workspace id"
[ -n "$WORKSPACE_ID" ] || fail "GET /me returned an empty workspace id"

http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID" "$OWNER_TOKEN"
assert_status 200 "GET /api/v1/workspaces/{id}"
WORKSPACE_ROLE="$(printf '%s' "$HTTP_BODY" | json_get "data.role" 2>/dev/null || true)"
[ -n "$WORKSPACE_ROLE" ] || fail "workspace detail has no role"
pass "workspace=$WORKSPACE_ID, role=$WORKSPACE_ROLE"

# 5. Profile picker -------------------------------------------------------
step "GET profiles (profile picker)"
http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/profiles" "$OWNER_TOKEN"
assert_status 200 "GET /profiles"
PROFILE_ID="$(printf '%s' "$HTTP_BODY" | json_get "data.0.id" 2>/dev/null || true)"
[ -n "$PROFILE_ID" ] || fail "no readable profile; run 'php artisan demo:seed' against this database"
pass "profile=$PROFILE_ID"

# 6. Dashboard ------------------------------------------------------------
step "GET dashboard"
http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/dashboard?profile_id=$PROFILE_ID&bypass_cache=1" "$OWNER_TOKEN"
assert_status 200 "GET /dashboard"
data_is_non_empty "$HTTP_BODY" || fail "dashboard body is empty"
pass "HTTP 200, non-empty body"

# 7. Timeline -------------------------------------------------------------
step "GET timeline"
http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/profiles/$PROFILE_ID/timeline?limit=10" "$OWNER_TOKEN"
assert_status 200 "GET /timeline"
data_is_non_empty "$HTTP_BODY" || fail "timeline body is empty"
pass "HTTP 200, non-empty body"

# 8. Care tasks -----------------------------------------------------------
step "GET care tasks"
http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/care-tasks?limit=10" "$OWNER_TOKEN"
assert_status 200 "GET /care-tasks"
data_is_non_empty "$HTTP_BODY" || fail "care-task list is empty"
pass "HTTP 200, non-empty body"

# 9. Appointments ---------------------------------------------------------
step "GET appointments"
http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/profiles/$PROFILE_ID/appointments?limit=10" "$OWNER_TOKEN"
assert_status 200 "GET /appointments"
data_is_non_empty "$HTTP_BODY" || fail "appointment list is empty"
pass "HTTP 200, non-empty body"

# 10. Negative: a viewer may not read the member list ---------------------
step "viewer denied on owner-only path"
VIEWER_TOKEN="$(login "$VIEWER_EMAIL" "$VIEWER_PASSWORD")" || fail "viewer login did not return a token"
[ -n "$VIEWER_TOKEN" ] || fail "viewer login returned an empty token"

http GET "$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/members" "$VIEWER_TOKEN"
[ "$HTTP_STATUS" = "403" ] || fail "viewer got HTTP $HTTP_STATUS on /members (expected 403)"
DENIAL_CODE="$(printf '%s' "$HTTP_BODY" | json_get "code" 2>/dev/null || true)"
[ "$DENIAL_CODE" = "forbidden" ] || fail "viewer denial had code '$DENIAL_CODE' (expected 'forbidden')"
pass "HTTP 403 forbidden (workspace.member.read)"

echo
echo "All $TOTAL_STEPS smoke checks passed."
