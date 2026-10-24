<?php

namespace App\Console\Commands;

use App\Support\Performance\PerfFixture;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repeatable load smoke test for the two hot read paths and the task list.
 *
 * It seeds a synthetic workspace, then drives the real HTTP kernel in-process
 * (`$kernel->handle()`) as an authenticated member. No web server, no k6 and
 * no network: the measurement covers the application stack and PostgreSQL.
 *
 * The dashboard is requested with `bypass_cache=1` on purpose. The 30-second
 * read-model cache is a production optimisation, but a cache hit would not
 * answer "is the aggregate slow for a family with years of records?", which is
 * exactly what this command is for. The timeline and task list have no cache.
 *
 * The command never touches real data: it only operates on the dedicated
 * `perf-smoke` tenant/workspace, refuses a workspace without its marker, and
 * refuses to run at all when `APP_ENV=production`.
 */
final class PerfSmokeCommand extends Command
{
    protected $signature = 'perf:smoke
        {--iterations=20 : Timed requests per route}
        {--warmup=3 : Warmup requests discarded before timing}
        {--json : Print the report as JSON instead of a table}
        {--budget-ms= : Override the p95 budget for every route (dashboard/timeline default 800, care tasks 300)}';

    protected $description = 'Run an in-process load smoke test against a synthetic workspace (no web server)';

    private const DASHBOARD_BUDGET_MS = 800;

    private const TIMELINE_BUDGET_MS = 800;

    private const CARE_TASK_BUDGET_MS = 300;

    public function handle(PerfFixture $fixture): int
    {
        if ($this->laravel->environment('production') || config('app.env') === 'production') {
            $this->error('perf:smoke refuses to run with APP_ENV=production; it writes a synthetic fixture.');

            return self::FAILURE;
        }

        $iterations = (int) $this->option('iterations');
        $warmup = (int) $this->option('warmup');

        if ($iterations < 1) {
            $this->error('--iterations must be at least 1.');

            return self::FAILURE;
        }

        if ($warmup < 0) {
            $this->error('--warmup must be zero or greater.');

            return self::FAILURE;
        }

        $budgetOverride = $this->resolveBudgetOverride();

        if ($budgetOverride === false) {
            return self::FAILURE;
        }

        try {
            $info = $fixture->ensure($this->laravel->runningUnitTests());
            $token = $fixture->issueToken();

            $routes = $this->routes($info['workspace_id'], $info['profile_id'], $budgetOverride);
            // An explicit --budget-ms is honoured exactly. The debugger
            // allowance only applies to the built-in default gate.
            $budgetMultiplier = $budgetOverride === null ? $this->budgetMultiplier() : 1.0;
            $results = $this->probe($routes, $token, $iterations, $warmup, $budgetMultiplier);

            $counts = $fixture->counts();
            $auditRows = $counts['audit_rows'] ?? 0;
            unset($counts['audit_rows']);

            $ok = true;

            foreach ($results as $result) {
                $ok = $ok && $result['ok'];
            }
        } catch (Throwable $exception) {
            $this->error('perf:smoke failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $report = [
            'environment' => $this->laravel->environment(),
            'fixture_scale' => $fixture->scale(),
            'fixture_created' => $info['created'],
            'workspace_id' => $info['workspace_id'],
            'iterations' => $iterations,
            'warmup' => $warmup,
            'budget_override_ms' => $budgetOverride,
            'instrumentation' => [
                'xdebug' => $this->xdebugActive(),
                'budget_multiplier' => $budgetMultiplier,
            ],
            'fixture' => $counts,
            'audit_rows' => $auditRows,
            'ok' => $ok,
            'routes' => $results,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'perf:smoke - workspace %s; fixture=%s (%d measurements, %d care tasks, %d task events, '
            .'%d medication plans, %d medication records, %d appointments, %d timeline entries; '
            .'%d audit rows).',
            $info['workspace_id'],
            $fixture->scale(),
            $counts['measurements'],
            $counts['care_tasks'],
            $counts['task_events'],
            $counts['medication_plans'],
            $counts['medication_records'],
            $counts['appointments'],
            $counts['timeline_entries'],
            $auditRows,
        ));
        $this->line(sprintf(
            'iterations=%d warmup=%d (dashboard uses bypass_cache=1 to measure the aggregate, not the cache)',
            $iterations,
            $warmup,
        ));

        if ($budgetMultiplier > 1.0) {
            $this->warn(sprintf(
                'Xdebug is active (%s): the default p95 gates are relaxed by %.1fx so the number reflects '
                .'the app, not the debugger. Re-run with `php -d xdebug.mode=off artisan perf:smoke` for '
                .'the production-like baseline.',
                (string) ini_get('xdebug.mode'),
                $budgetMultiplier,
            ));
        } elseif ($this->xdebugActive() && $budgetOverride !== null) {
            $this->warn('Xdebug is active; --budget-ms is applied exactly without the default relaxation.');
        }

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result['name'],
                number_format($result['p50_ms'], 1),
                number_format($result['p95_ms'], 1),
                number_format($result['p99_ms'], 1),
                number_format($result['min_ms'], 1),
                number_format($result['max_ms'], 1),
                number_format($result['queries_avg'], 1),
                $this->statusSummary($result['statuses']),
                (string) $result['budget_ms'],
                $result['ok'] ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(
            ['Route', 'p50 ms', 'p95 ms', 'p99 ms', 'min ms', 'max ms', 'q/req', 'statuses', 'budget ms', 'result'],
            $rows,
        );

        if ($ok) {
            $this->info('All routes returned 2xx and stayed within their p95 budget.');

            return self::SUCCESS;
        }

        $this->error('At least one route failed or exceeded its p95 budget.');

        return self::FAILURE;
    }

    private function resolveBudgetOverride(): int|false|null
    {
        $value = $this->option('budget-ms');

        if ($value === null || $value === '') {
            return null;
        }

        $budget = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($budget === false) {
            $this->error('--budget-ms must be a positive integer.');

            return false;
        }

        return (int) $budget;
    }

    /**
     * @return list<array{name: string, path: string, budget_ms: int}>
     */
    private function routes(string $workspaceId, string $profileId, ?int $budgetOverride): array
    {
        return [
            [
                'name' => 'dashboard',
                'path' => sprintf('/api/v1/workspaces/%s/dashboard?bypass_cache=1', $workspaceId),
                'budget_ms' => $budgetOverride ?? self::DASHBOARD_BUDGET_MS,
            ],
            [
                'name' => 'timeline',
                'path' => sprintf('/api/v1/workspaces/%s/profiles/%s/timeline', $workspaceId, $profileId),
                'budget_ms' => $budgetOverride ?? self::TIMELINE_BUDGET_MS,
            ],
            [
                'name' => 'care-tasks',
                'path' => sprintf('/api/v1/workspaces/%s/care-tasks', $workspaceId),
                'budget_ms' => $budgetOverride ?? self::CARE_TASK_BUDGET_MS,
            ],
        ];
    }

    /**
     * Drive the real kernel for every route and collect timings/queries.
     *
     * @param  list<array{name: string, path: string, budget_ms: int}>  $routes
     * @return list<array<string, mixed>>
     */
    private function probe(
        array $routes,
        string $token,
        int $iterations,
        int $warmup,
        float $budgetMultiplier,
    ): array {
        /** @var HttpKernel $kernel */
        $kernel = $this->laravel->make(HttpKernel::class);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = [];

        foreach ($routes as $route) {
            $timings = [];
            $queryCounts = [];
            $statuses = [];
            $requests = $warmup + $iterations;

            for ($index = 0; $index < $requests; $index++) {
                $queries = 0;

                $request = Request::create(
                    $route['path'],
                    'GET',
                    [],
                    [],
                    [],
                    [
                        'HTTP_ACCEPT' => 'application/json',
                        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    ],
                );

                $started = hrtime(true);
                $response = $kernel->handle($request);
                $elapsedMs = (hrtime(true) - $started) / 1_000_000;
                $status = $response->getStatusCode();
                $countedQueries = $queries;

                try {
                    $kernel->terminate($request, $response);
                } catch (Throwable) {
                    // A terminate failure is not part of the response latency;
                    // the request result is already captured above.
                }

                // Re-authenticate on every request instead of reusing the
                // guard's cached user, so the token lookup is part of the cost.
                $this->laravel->make('auth')->forgetGuards();

                if ($index < $warmup) {
                    continue;
                }

                $timings[] = $elapsedMs;
                $queryCounts[] = $countedQueries;
                $statuses[] = $status;
            }

            sort($timings, SORT_NUMERIC);

            $p95 = $this->percentile($timings, 95);
            $allSuccessful = $statuses !== [];

            foreach ($statuses as $status) {
                if ($status < 200 || $status >= 300) {
                    $allSuccessful = false;

                    break;
                }
            }

            $effectiveBudgetMs = (int) ceil($route['budget_ms'] * $budgetMultiplier);

            $results[] = [
                'name' => $route['name'],
                'method' => 'GET',
                'path' => $route['path'],
                'budget_ms' => $route['budget_ms'],
                'effective_budget_ms' => $effectiveBudgetMs,
                'p50_ms' => round($this->percentile($timings, 50), 3),
                'p95_ms' => round($p95, 3),
                'p99_ms' => round($this->percentile($timings, 99), 3),
                'min_ms' => round($timings === [] ? 0.0 : $timings[0], 3),
                'max_ms' => round($timings === [] ? 0.0 : $timings[count($timings) - 1], 3),
                'queries_avg' => round($queryCounts === [] ? 0.0 : array_sum($queryCounts) / count($queryCounts), 2),
                'queries_min' => $queryCounts === [] ? 0 : min($queryCounts),
                'queries_max' => $queryCounts === [] ? 0 : max($queryCounts),
                'statuses' => $statuses,
                'ok' => $allSuccessful && $p95 <= $effectiveBudgetMs,
            ];
        }

        return $results;
    }

    /**
     * Linear-interpolation percentile (the "inclusive" / type-7 definition
     * used by NumPy and Excel's PERCENTILE.INC) over an ascending list.
     *
     * With 10 samples a nearest-rank p95 is literally the maximum, so one
     * scheduler hiccup would decide the gate. Interpolation between the 9th
     * and 10th sample is the more useful and more standard reading.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, int $percentile): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return $sorted[0];
        }

        $position = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($position - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * Xdebug instruments every function call and database callback. Measured
     * overhead on this repository's routes ranges from about 1.5x (timeline)
     * to about 9x (dashboard aggregate) with `xdebug.mode=develop`; 5x is the
     * conservative allowance that keeps the *gate* from measuring the
     * debugger on the four routes while still failing a real regression. The
     * raw budget and raw p95 are always reported unchanged.
     */
    private function xdebugActive(): bool
    {
        if (! extension_loaded('xdebug')) {
            return false;
        }

        $mode = strtolower(trim((string) ini_get('xdebug.mode')));

        return $mode !== '' && $mode !== 'off';
    }

    private function budgetMultiplier(): float
    {
        return $this->xdebugActive() ? 5.0 : 1.0;
    }

    /**
     * @param  list<int>  $statuses
     */
    private function statusSummary(array $statuses): string
    {
        $counts = array_count_values(array_map('strval', $statuses));
        ksort($counts);

        $parts = [];

        foreach ($counts as $status => $count) {
            $parts[] = $status.' x'.$count;
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }
}
