<?php

namespace Tests\Feature\Retention;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RET-SCHED01 - the weekly archive probe is registered.
 *
 * The command itself is covered by RET-STAT01..03; this test exists because the
 * schedule is the part that runs unsupervised. If the entry is removed or its
 * flags change, nothing else notices until an archive rots.
 */
class RetentionScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ret_sched01_the_weekly_archive_probe_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('retention:status --verify-archives')
            ->assertSuccessful();
    }
}
