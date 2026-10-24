<?php

namespace Tests\Feature\Performance;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Performance\PerfFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRF-T01..T04 - the perf:smoke harness itself.
 *
 * The command automatically uses its compact fixture under PHPUnit, so these
 * tests stay in the "a few seconds" range rather than seeding the full
 * two-year dataset four times.
 */
class PerfSmokeTest extends TestCase
{
    use RefreshDatabase;

    // -- PRF-T01 ------------------------------------------------------------

    public function test_prf_t01_command_runs_and_reports_all_three_routes(): void
    {
        $report = $this->runPerf();

        $this->assertTrue($report['ok']);
        $this->assertSame('compact', $report['fixture_scale']);
        $this->assertCount(3, $report['routes']);
        $this->assertSame(
            ['dashboard', 'timeline', 'care-tasks'],
            array_column($report['routes'], 'name'),
        );

        foreach ($report['routes'] as $route) {
            $this->assertArrayHasKey('p50_ms', $route, $route['name']);
            $this->assertArrayHasKey('p95_ms', $route, $route['name']);
            $this->assertArrayHasKey('p99_ms', $route, $route['name']);
            $this->assertArrayHasKey('min_ms', $route, $route['name']);
            $this->assertArrayHasKey('max_ms', $route, $route['name']);
            $this->assertArrayHasKey('queries_avg', $route, $route['name']);
            $this->assertGreaterThan(0, $route['queries_avg'], $route['name'].' must report query counts');
            $this->assertSame([200, 200], $route['statuses'], $route['name'].' must return 2xx');
            $this->assertTrue($route['ok'], $route['name'].' must pass its budget');
            $this->assertGreaterThanOrEqual($route['p50_ms'], $route['p95_ms']);
            $this->assertGreaterThanOrEqual($route['p95_ms'], $route['p99_ms']);
        }

        $this->assertSame(60, $report['fixture']['measurements']);
        $this->assertSame(24, $report['fixture']['care_tasks']);
        $this->assertSame(48, $report['fixture']['task_events']);
        $this->assertSame(3, $report['fixture']['medication_plans']);
        $this->assertSame(12, $report['fixture']['medication_records']);
        $this->assertSame(6, $report['fixture']['appointments']);
        $this->assertSame(130, $report['fixture']['timeline_entries']);
    }

    // -- PRF-T02 ------------------------------------------------------------

    public function test_prf_t02_a_one_millisecond_budget_fails_the_gate(): void
    {
        $exit = Artisan::call('perf:smoke', [
            '--iterations' => 1,
            '--warmup' => 0,
            '--budget-ms' => 1,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, 'A 1 ms budget must make the command exit non-zero.');
        $this->assertStringContainsString('"ok": false', $output);

        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['ok']);
        $this->assertContains(false, array_column($report['routes'], 'ok'));
    }

    // -- PRF-T03 ------------------------------------------------------------

    public function test_prf_t03_two_runs_leave_the_fixture_row_counts_stable(): void
    {
        // T03 only needs the fixture counts, not a latency gate: one request
        // per route keeps it fast, and a generous budget keeps a scheduler
        // hiccup on a shared CI runner from turning this into a flaky p95 test.
        $first = $this->runPerf(1, 0, 10_000);
        $second = $this->runPerf(1, 0, 10_000);

        $this->assertSame($first['fixture'], $second['fixture'], 'The fixture must be idempotent.');

        $tenant = Tenant::query()->where('slug', PerfFixture::TENANT_SLUG)->firstOrFail();

        $workspace = TenantContext::run(
            $tenant->id,
            '',
            fn (): ?Workspace => Workspace::query()
                ->where('slug', PerfFixture::WORKSPACE_SLUG)
                ->first(),
        );

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertTrue((bool) ($workspace->settings['perf_smoke'] ?? false));

        $counts = TenantContext::run($tenant->id, $workspace->id, fn (): array => [
            'measurements' => DB::table('health_measurements')->where('workspace_id', $workspace->id)->count(),
            'care_tasks' => DB::table('care_tasks')->where('workspace_id', $workspace->id)->count(),
            'task_events' => DB::table('task_events')->where('workspace_id', $workspace->id)->count(),
            'medication_plans' => DB::table('medication_plans')->where('workspace_id', $workspace->id)->count(),
            'medication_records' => DB::table('medication_records')->where('workspace_id', $workspace->id)->count(),
            'appointments' => DB::table('appointments')->where('workspace_id', $workspace->id)->count(),
            'timeline_entries' => DB::table('timeline_entries')->where('workspace_id', $workspace->id)->count(),
        ]);

        $this->assertSame($second['fixture'], $counts, 'The database rows must match the reported fixture.');
    }

    // -- PRF-T04 ------------------------------------------------------------

    public function test_prf_t04_command_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $exit = Artisan::call('perf:smoke', [
                '--iterations' => 1,
                '--warmup' => 0,
            ]);
            $output = Artisan::output();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('APP_ENV=production', $output);
        $this->assertSame(
            0,
            Tenant::query()->where('slug', PerfFixture::TENANT_SLUG)->count(),
            'The production refusal must happen before any fixture is created.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runPerf(int $iterations = 2, int $warmup = 1, ?int $budgetMs = null): array
    {
        $options = [
            '--iterations' => $iterations,
            '--warmup' => $warmup,
            '--json' => true,
        ];

        if ($budgetMs !== null) {
            $options['--budget-ms'] = $budgetMs;
        }

        $exit = Artisan::call('perf:smoke', $options);

        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
