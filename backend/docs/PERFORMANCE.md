# Performance smoke test (`perf:smoke`)

> Status: **repeatable harness in place (2026-10-25).** It measures the dashboard aggregate, the
> timeline page and the care-task list against a synthetic workspace. It is not a concurrent load test;
> see [Limitations](#limitations).

## 1. How to run it

```bash
cd backend

# Default: 20 timed requests per route, 3 warmups, p95 gate 800/800/300 ms.
php artisan perf:smoke

# The acceptance run used for the baseline below.
php artisan perf:smoke --iterations=10

# Machine-readable report (used by PRF-T01..T04 and CI).
php artisan perf:smoke --iterations=10 --json

# Override the p95 gate for every route (useful on a slow laptop or a CI runner).
php artisan perf:smoke --iterations=10 --budget-ms=1500
```

If Xdebug is loaded, run the measurement with the debugger disabled. Xdebug instruments every function
call and database callback and roughly triples in-process latency on this machine:

```bash
php -d xdebug.mode=off artisan perf:smoke --iterations=10
```

The command detects Xdebug and relaxes only the **default gate** by 5x while printing a warning; the raw
p95 is never changed. An explicit `--budget-ms` is honoured exactly, with no relaxation. The raw budget
still reads 800/800/300 ms in the table. Measured debugger overhead on these routes ranges from about
1.5x to about 9x, so 5x is a conservative allowance, not a production number. Run with
`xdebug.mode=off` for the real baseline.

The command refuses to run when `APP_ENV=production`, and it only ever writes inside the dedicated
`perf-smoke` tenant / `[perf:smoke] Synthetic Workspace`. It never reads or writes real family data.

## 2. What the command does

1. Creates (or reuses) a marked synthetic tenant and workspace:
   - 1 workspace, 1 owner user, 1 person, 1 health profile.
   - ~500 health measurements over 24 months, mixed types and occasional critical values.
   - ~120 care tasks with mixed statuses and 2 task events each (240 events).
   - 6 medication plans, 60 medication records, 12 appointments.
   - 200 synthetic `security_audit_logs` rows (plus the audit rows the measured requests write).
   - 821 derived `timeline_entries` (500 measurements + 240 task events + 6 plans + 60 records
     + 15 appointment entries).
   - Under PHPUnit the same shape is seeded at `compact` scale so the test suite stays fast.
2. Issues a Sanctum bearer token for the owner.
3. Drives the real HTTP kernel in-process with `$kernel->handle(Request::create(...))` for:
   - `GET /api/v1/workspaces/{workspace}/dashboard?bypass_cache=1`
   - `GET /api/v1/workspaces/{workspace}/profiles/{profile}/timeline`
   - `GET /api/v1/workspaces/{workspace}/care-tasks`
4. Discards `--warmup` requests per route, then times `--iterations` requests.
5. Counts SQL statements with `DB::listen` between request start and response, and reports the average
   per request.
6. Exits non-zero if any request is non-2xx or a route's p95 is over its budget.

The dashboard is requested with `bypass_cache=1` deliberately. The 30-second read-model cache is a real
production optimisation, but a cache hit would not answer the question this harness exists for: how long
does the aggregate take for a family with years of records? The timeline and task list have no cache.

The fixture is reusable and idempotent. Every fixture row has a deterministic UUID, so a second run
inserts no duplicate source or timeline rows. `security_audit_logs` is append-only by design and therefore
grows by the audit rows each measured request writes; it is reported separately from the fixture counts.

## 3. What each number means

| Column | Meaning |
|---|---|
| `p50 ms` | Median request time. The typical user experience. |
| `p95 ms` | 95th percentile. The number the gate uses. |
| `p99 ms` | 99th percentile. With 10 samples this is close to the maximum. |
| `min ms` / `max ms` | Fastest and slowest timed request; useful for spotting a spike. |
| `q/req` | Average SQL statements per request, including token lookup, RLS `set_config`/`current_setting`, cache lookups, and audit-chain writes. |
| `statuses` | Every timed response, compacted as `200 x10`. Any non-2xx fails the command. |
| `budget ms` | The raw p95 budget for that route. |
| `result` | `PASS` only when every status is 2xx and p95 <= the effective budget. |

Percentiles use linear interpolation (the inclusive / type-7 definition used by NumPy and Excel's
`PERCENTILE.INC`). With only 10 samples, a nearest-rank p95 would simply be the maximum, so one scheduler
hiccup would decide the gate. Interpolation is the more useful reading and is what the command reports.

## 4. Measured baseline on this machine

Machine: Windows, PHP 8.4.15, PostgreSQL 16 in Docker (host port 5433), database cache store, 10 timed
requests and 3 warmups per route, full fixture. **Xdebug disabled** (`php -d xdebug.mode=off`):

```
perf:smoke - workspace 0198f000-0000-7000-8000-000000000002; fixture=full (500 measurements, 120 care tasks, 240 task events, 6 medication plans, 60 medication records, 12 appointments, 821 timeline entries; 408 audit rows).
iterations=10 warmup=3 (dashboard uses bypass_cache=1 to measure the aggregate, not the cache)
+------------+--------+--------+--------+--------+--------+-------+----------+-----------+--------+
| Route      | p50 ms | p95 ms | p99 ms | min ms | max ms | q/req | statuses | budget ms | result |
+------------+--------+--------+--------+--------+--------+-------+----------+-----------+--------+
| dashboard  | 118.9  | 155.9  | 164.6  | 106.0  | 166.8  | 36.1  | 200 x10  | 800       | PASS   |
| timeline   | 112.3  | 136.5  | 145.8  | 107.8  | 148.2  | 48.1  | 200 x10  | 800       | PASS   |
| care-tasks | 112.1  | 150.2  | 151.4  | 99.8   | 151.7  | 40.1  | 200 x10  | 300       | PASS   |
+------------+--------+--------+--------+--------+--------+-------+----------+-----------+--------+
All routes returned 2xx and stayed within their p95 budget.
```

Same command with the machine's `xdebug.mode=develop`:

```
+------------+---------+---------+---------+---------+---------+-------+----------+-----------+--------+
| Route      | p50 ms  | p95 ms  | p99 ms  | min ms  | max ms  | q/req | statuses | budget ms | result |
+------------+---------+---------+---------+---------+---------+-------+----------+-----------+--------+
| dashboard  | 1,740.6 | 2,532.4 | 2,895.6 | 1,551.1 | 2,986.4 | 37.0  | 200 x10  | 800       | PASS   |
| timeline   | 662.3   | 1,018.9 | 1,071.6 | 441.8   | 1,084.7 | 48.6  | 200 x10  | 800       | PASS   |
| care-tasks | 611.8   | 1,417.9 | 1,724.1 | 535.0   | 1,800.7 | 40.8  | 200 x10  | 300       | PASS   |
+------------+---------+---------+---------+---------+---------+-------+----------+-----------+--------+
```

The second table is shown so the debugger overhead is not mistaken for application cost. It is not the
performance baseline and should not be quoted in a capacity plan.

`audit_rows` is 200 fixture rows plus the audit rows the measured requests write on every run (the
ledger is append-only), so it grows between runs. The source fixture counts stayed stable across runs
(PRF-T03); only the ledger grows. The machine is also shared with other Docker workloads, and one
Xdebug-off run during this work showed a care-tasks p95 of 481 ms before settling back to 149 ms. Treat
the query counts and the order of magnitude as the stable signal, and use `--budget-ms` on a noisy
machine rather than reading a single spike as an application regression.

The first run also exposed an N+1 in the task list: presentation resolved the full/card DTO by calling the
owner/grant lookup once per row (20 extra queries per page). The list endpoint now computes the visible
full-profile set once and reuses it for the page predicate and presentation. Care-tasks dropped from
about 54 to about 40 queries per request; the p95 dropped accordingly (roughly 240 ms to 135-210 ms
depending on the run).

## 5. Budgets and why they are set there

| Route | Budget | Rationale |
|---|---|---|
| Dashboard | 800 ms p95 | It is the first screen and aggregates five cards with a separate authorization and audit row per card. The target is "feels instant on a mid-range phone over a family network"; 800 ms leaves room for the network and rendering after the API returns. |
| Timeline | 800 ms p95 | One page of 20 entries plus per-scope visibility checks and one audit segment. Same first-screen responsiveness target as the dashboard. |
| Care tasks | 300 ms p95 | A list of 20 safe task cards with a bounded query count. It is a secondary screen, but anything slower than 300 ms on a local database indicates a query-shape problem (N+1, missing index) rather than normal load. |

All three are p95, not p50, so a single slow request does not fail the gate but a systematically slow
route does. `--budget-ms` overrides all three at once for a slower machine or a CI runner.

## 6. Limitations

These are deliberate and must be read before quoting a number:

- **Single process, no concurrency.** Requests run sequentially in the artisan process. The harness
  measures the application and PostgreSQL, not throughput, connection-pool behaviour, lock contention,
  queue workers, or a real web server / TLS / reverse proxy.
- **No network.** The HTTP kernel is driven in-process, so network latency, load balancers and the
  deployment topology are out of scope.
- **One profile, one workspace.** Real families can have several profiles and grants. The dashboard's
  per-card authorization and the timeline's per-scope checks scale with profile/scope count, not just
  row count, so a larger family will be slower than this fixture.
- **Dashboard cache is bypassed.** These numbers are the aggregate cost; a warm cache hit is much faster.
- **Machine-specific.** The baseline above is this laptop with Docker PostgreSQL, a database cache store
  and no Xdebug. Absolute milliseconds on CI or production will differ; compare the shape and the query
  counts, not the exact number.
- **Query counts include infrastructure work.** Token lookup, RLS context calls, cache lookups and the
  hash-chained audit append are part of the request and therefore count.
- **Xdebug changes the number.** The command prints a warning and applies a 5x gate multiplier when
  Xdebug is active so the gate is not testing the debugger. Raw p95 is unchanged. Disable Xdebug for the
  real measurement.
- **Rate limiting is part of the stack.** Each route has a 60/minute throttle. Roughly 55 or more
  iterations in one run can reach it and produce 429s (which correctly fail the command). Keep
  `--iterations` below that, or wait a minute between runs.
- **Not a pre-launch load test.** A real concurrent load test with multiple clients, realistic think
  time, network and the deployed stack is still a pre-launch task. This harness is the repeatable
  regression measurement that makes the next change measurable.

## 7. Tests

`backend/tests/Feature/Performance/PerfSmokeTest.php`:

| Test | What it proves |
|---|---|
| `PRF-T01` | The command runs on the compact fixture with `--iterations=2 --warmup=1`, exits 0, and reports all three routes with percentiles and query counts. |
| `PRF-T02` | `--budget-ms=1` makes the command exit non-zero, proving the gate is live. |
| `PRF-T03` | Two runs leave the fixture row counts unchanged (idempotent fixture; audit rows are append-only and excluded from the source-count assertion). |
| `PRF-T04` | `APP_ENV=production` is refused before any fixture row is created. |
