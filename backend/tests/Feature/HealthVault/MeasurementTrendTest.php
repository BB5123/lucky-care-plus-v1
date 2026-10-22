<?php

namespace Tests\Feature\HealthVault;

use Laravel\Sanctum\Sanctum;

/**
 * HV-T11 — daily trend buckets.
 */
class MeasurementTrendTest extends HealthVaultTestCase
{
    public function test_hv_t11_trend_buckets_by_day_and_returns_min_max_avg(): void
    {
        $threeDaysAgo = now()->subDays(3)->setTime(10, 0);
        $twoDaysAgo = now()->subDays(2)->setTime(9, 0);
        $tenDaysAgo = now()->subDays(10)->setTime(8, 0);

        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 80,
            'unit' => 'mmHg',
            'recorded_at' => $threeDaysAgo,
        ]);
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 140,
            'secondary_value' => 90,
            'unit' => 'mmHg',
            'recorded_at' => $threeDaysAgo->copy()->addHour(),
        ]);
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 110,
            'secondary_value' => 70,
            'unit' => 'mmHg',
            'recorded_at' => $twoDaysAgo,
        ]);

        // Outside the requested window and outside the blood-pressure type.
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 100,
            'secondary_value' => 60,
            'unit' => 'mmHg',
            'recorded_at' => $tenDaysAgo,
        ]);
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 88,
            'unit' => 'bpm',
            'recorded_at' => $threeDaysAgo,
        ]);

        Sanctum::actingAs($this->owner);

        $from = now()->subDays(4)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson(
            $this->profilesPath()."/measurements/trend?type=blood_pressure&from={$from}&to={$to}"
        )->assertOk();

        $this->assertSame('day', $response->json('meta.bucket'));

        $points = collect($response->json('data'))->keyBy('date');

        $this->assertCount(2, $points);

        $first = $points[$threeDaysAgo->toDateString()];
        $this->assertEqualsWithDelta(120.0, (float) $first['min'], 0.001);
        $this->assertEqualsWithDelta(140.0, (float) $first['max'], 0.001);
        $this->assertEqualsWithDelta(130.0, (float) $first['avg'], 0.001);
        $this->assertSame(2, $first['count']);

        $second = $points[$twoDaysAgo->toDateString()];
        $this->assertEqualsWithDelta(110.0, (float) $second['min'], 0.001);
        $this->assertEqualsWithDelta(110.0, (float) $second['max'], 0.001);
        $this->assertEqualsWithDelta(110.0, (float) $second['avg'], 0.001);
        $this->assertSame(1, $second['count']);
    }

    public function test_hv_t11_trend_defaults_to_the_last_thirty_days(): void
    {
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 70,
            'recorded_at' => now()->subDays(5),
        ]);
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 75,
            'recorded_at' => now()->subDays(45),
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->profilesPath().'/measurements/trend?type=heart_rate')
            ->assertOk();

        $points = $response->json('data');

        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]['count']);
        $this->assertEqualsWithDelta(70.0, (float) $points[0]['avg'], 0.001);
    }
}
