<?php

namespace Tests\Feature\CareCoordination;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Database\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CareTask\CareTaskTestCase;

/**
 * P1.4 acceptance suite — APT-T01 ... APT-T12.
 *
 * The lifecycle is exercised through the HTTP surface because the route
 * middleware (Authorizer + workspace context) is part of the contract. Direct
 * model fixtures are only used for filters and cross-workspace isolation.
 */
class AppointmentTest extends CareTaskTestCase
{
    public function test_apt_t01_create_returns_201_with_planned_status_version_one_and_actor(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->appointmentsPath(), $this->appointmentPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', Appointment::STATUS_PLANNED)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.created_by', $this->owner->id)
            ->assertJsonPath('data.title', 'ตรวจติดตามโรคความดัน');

        $this->assertNotNull($response->json('data.id'));
        $this->assertSame($this->profile->id, $response->json('data.profile_id'));
    }

    public function test_apt_t02_planned_to_confirmed_to_attended_and_second_attended_is_idempotent(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();

        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->appointmentsPath($appointment['id']), ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED)
            ->assertJsonPath('data.version', 2);

        $first = $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->appointmentsPath($appointment['id']).'/attended')
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_ATTENDED)
            ->assertJsonPath('data.version', 3)
            ->json('data');

        $this->assertNotNull($first['follow_up_task_id']);

        $second = $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->appointmentsPath($appointment['id']).'/attended')
            ->assertOk()
            ->json('data');

        $this->assertSame($first['follow_up_task_id'], $second['follow_up_task_id']);

        $this->useWorkspace();
        $this->assertSame(
            1,
            CareTask::query()
                ->where('profile_id', $this->profile->id)
                ->where('type', 'FOLLOW_UP')
                ->count(),
        );
    }

    public function test_apt_t03_planned_to_attended_directly_is_a_domain_conflict(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->appointmentsPath($appointment['id']).'/attended')
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');

        $this->useWorkspace();
        $this->assertSame(
            Appointment::STATUS_PLANNED,
            Appointment::query()->findOrFail($appointment['id'])->status,
        );
        $this->assertSame(0, CareTask::query()->where('type', 'FOLLOW_UP')->count());
    }

    public function test_apt_t04_cancelling_requires_a_reason_and_then_marks_the_appointment_cancelled(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->appointmentsPath($appointment['id']).'/cancel', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->appointmentsPath($appointment['id']).'/cancel', [
                'reason' => 'แพทย์เลื่อนนัด',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CANCELLED)
            ->assertJsonPath('data.cancelled_reason', 'แพทย์เลื่อนนัด')
            ->assertJsonPath('data.version', 2);
    }

    public function test_apt_t05_attended_creates_one_delegated_follow_up_task_due_in_three_days_and_links_both_rows(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();
        $this->confirm($appointment['id'], '1');

        $attendedAt = now()->subHours(2)->startOfSecond();

        $attended = $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->appointmentsPath($appointment['id']).'/attended', [
                'attended_at' => $attendedAt->toIso8601String(),
            ])
            ->assertOk()
            ->json('data');

        $this->assertNotNull($attended['follow_up_task_id']);

        $this->useWorkspace();

        $task = CareTask::query()->findOrFail($attended['follow_up_task_id']);
        $storedAppointment = Appointment::query()->findOrFail($appointment['id']);

        $this->assertSame(CareTask::TIER_DELEGATED, $task->tier);
        $this->assertSame('FOLLOW_UP', $task->type);
        $this->assertStringStartsWith('ติดตามผลหลังพบแพทย์:', $task->title);
        $this->assertSame($this->profile->id, $task->profile_id);
        $this->assertSame($this->workspace->id, $task->workspace_id);
        $this->assertTrue(
            $task->due_at->equalTo($attendedAt->copy()->addDays(3)),
            "Follow-up due_at {$task->due_at} is not attended_at + 3 days",
        );

        $this->assertSame($task->id, $storedAppointment->follow_up_task_id);
        $this->assertSame($storedAppointment->id, Appointment::query()->findOrFail($appointment['id'])->id);
    }

    public function test_apt_t06_follow_up_task_is_visible_through_the_care_task_api_with_its_own_events(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();
        $this->confirm($appointment['id'], '1');

        $attended = $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->appointmentsPath($appointment['id']).'/attended')
            ->assertOk()
            ->json('data');

        $taskId = $attended['follow_up_task_id'];

        $this->getJson($this->tasksPath($taskId))
            ->assertOk()
            ->assertJsonPath('data.id', $taskId)
            ->assertJsonPath('data.type', 'FOLLOW_UP')
            ->assertJsonPath('data.tier', CareTask::TIER_DELEGATED);

        $this->getJson($this->tasksPath($taskId).'/events')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'created');

        $this->useWorkspace();

        $created = TaskEvent::query()
            ->where('task_id', $taskId)
            ->where('event', 'created')
            ->sole();

        $this->assertSame($this->owner->id, $created->actor_id);
        $this->assertSame(CareTask::STATUS_OPEN, $created->to_status);
    }

    public function test_apt_t07_transport_task_from_another_profile_is_rejected_with_422(): void
    {
        $foreignTask = $this->makeTask([], $this->dependentProfile);

        Sanctum::actingAs($this->owner);

        $this->postJson($this->appointmentsPath(), $this->appointmentPayload([
            'transported_by_task_id' => $foreignTask->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_apt_t08_appointment_from_another_workspace_is_404(): void
    {
        $foreign = $this->makeAppointment([
            'title' => 'Foreign appointment',
        ], $this->otherWorkspaceProfile);

        Sanctum::actingAs($this->owner);

        $this->getJson($this->appointmentsPath($foreign->id))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_apt_t09_stale_if_match_is_412_and_the_row_is_unchanged(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();

        $this->withHeaders(['If-Match' => '99'])
            ->patchJson($this->appointmentsPath($appointment['id']), [
                'status' => 'confirmed',
                'title' => 'Should not persist',
            ])
            ->assertStatus(412)
            ->assertJsonPath('code', 'version_conflict');

        $this->useWorkspace();

        $stored = Appointment::query()->findOrFail($appointment['id']);
        $this->assertSame(Appointment::STATUS_PLANNED, $stored->status);
        $this->assertSame(1, $stored->version);
        $this->assertSame('ตรวจติดตามโรคความดัน', $stored->title);
    }

    public function test_apt_t10_documents_checklist_toggles_persist_and_are_returned_in_order(): void
    {
        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi();

        $documents = [
            ['label' => 'บัตรประชาชน', 'done' => false],
            ['label' => 'สมุดสุขภาพ', 'done' => true],
            ['label' => 'ผลตรวจเดิม', 'done' => false],
        ];

        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->appointmentsPath($appointment['id']).'/documents', [
                'documents' => $documents,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.prepared_documents.0.label', 'บัตรประชาชน')
            ->assertJsonPath('data.prepared_documents.0.done', false)
            ->assertJsonPath('data.prepared_documents.1.label', 'สมุดสุขภาพ')
            ->assertJsonPath('data.prepared_documents.2.label', 'ผลตรวจเดิม');

        $this->withHeaders(['If-Match' => '2'])
            ->patchJson($this->appointmentsPath($appointment['id']).'/documents', [
                'index' => 0,
                'done' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.prepared_documents.0.done', true)
            ->assertJsonPath('data.prepared_documents.1.done', true)
            ->assertJsonPath('data.prepared_documents.2.done', false);

        $this->useWorkspace();

        $stored = Appointment::query()->findOrFail($appointment['id']);
        $this->assertSame(
            ['บัตรประชาชน', 'สมุดสุขภาพ', 'ผลตรวจเดิม'],
            array_column($stored->prepared_documents, 'label'),
        );
        $this->assertSame([true, true, false], array_column($stored->prepared_documents, 'done'));
    }

    public function test_apt_t11_purpose_and_location_never_reach_audit_metadata(): void
    {
        Sanctum::actingAs($this->owner);

        $purposeMarker = 'SECRET-PURPOSE-MARKER';
        $locationMarker = 'SECRET-LOCATION-MARKER';

        $appointment = $this->createAppointmentViaApi([
            'purpose' => $purposeMarker,
            'location' => $locationMarker,
        ]);

        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->appointmentsPath($appointment['id']), [
                'status' => 'confirmed',
                'purpose' => $purposeMarker,
                'location' => $locationMarker,
            ])
            ->assertOk();

        $this->useWorkspace();

        foreach ([$purposeMarker, $locationMarker] as $marker) {
            $this->assertSame(
                0,
                DB::table('audit_events')->whereRaw('metadata::text like ?', ["%{$marker}%"])->count(),
                "audit_events leaked {$marker}",
            );
            $this->assertSame(
                0,
                DB::table('security_audit_logs')->whereRaw('metadata::text like ?', ["%{$marker}%"])->count(),
                "security_audit_logs leaked {$marker}",
            );
        }

        // The values are still available to an authorized reader via the API.
        $this->getJson($this->appointmentsPath($appointment['id']))
            ->assertOk()
            ->assertJsonPath('data.purpose', $purposeMarker)
            ->assertJsonPath('data.location', $locationMarker);
    }

    public function test_apt_t12_list_filters_status_date_window_and_upcoming_are_scoped(): void
    {
        $planned = $this->makeAppointment([
            'title' => 'Planned future',
            'status' => Appointment::STATUS_PLANNED,
            'scheduled_at' => now()->addDays(2),
        ]);
        $confirmed = $this->makeAppointment([
            'title' => 'Confirmed future',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_at' => now()->addDays(3),
        ]);
        $attended = $this->makeAppointment([
            'title' => 'Attended past',
            'status' => Appointment::STATUS_ATTENDED,
            'scheduled_at' => now()->subDays(5),
        ]);
        $cancelled = $this->makeAppointment([
            'title' => 'Cancelled past',
            'status' => Appointment::STATUS_CANCELLED,
            'cancelled_reason' => 'clinic closed',
            'scheduled_at' => now()->subDays(10),
        ]);

        // A row on another profile in the same workspace must never leak into
        // this profile's list.
        $this->makeAppointment([
            'title' => 'Other profile',
            'status' => Appointment::STATUS_PLANNED,
            'scheduled_at' => now()->addDays(2),
        ], $this->dependentProfile);

        Sanctum::actingAs($this->owner);

        $byStatus = $this->getJson($this->appointmentsPath().'?status=confirmed')
            ->assertOk()
            ->json('data');
        $this->assertSame([$confirmed->id], array_column($byStatus, 'id'));

        $byWindow = $this->getJson(
            $this->appointmentsPath().'?from='.now()->addDays(2)->toDateString().'&to='.now()->addDays(3)->toDateString(),
        )->assertOk()->json('data');
        $this->assertSame(
            [$planned->id, $confirmed->id],
            array_column($byWindow, 'id'),
        );

        $upcoming = $this->getJson($this->appointmentsPath().'?upcoming=true')
            ->assertOk()
            ->json('data');
        $this->assertSame(
            [$planned->id, $confirmed->id],
            array_column($upcoming, 'id'),
        );

        $all = $this->getJson($this->appointmentsPath())->assertOk()->json('data');
        $this->assertSame(
            [$cancelled->id, $attended->id, $planned->id, $confirmed->id],
            array_column($all, 'id'),
        );
    }

    private function appointmentsPath(?string $appointmentId = null): string
    {
        $path = "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/appointments";

        return $appointmentId === null ? $path : "{$path}/{$appointmentId}";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function appointmentPayload(array $attributes = []): array
    {
        return array_merge([
            'title' => 'ตรวจติดตามโรคความดัน',
            'provider_name' => 'Dr. Somchai',
            'facility' => 'Bangkok Hospital',
            'purpose' => 'ติดตามความดันโลหิต',
            'location' => 'อาคาร A ชั้น 3',
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'duration_minutes' => 30,
            'prepared_documents' => [
                ['label' => 'บัตรประชาชน', 'done' => false],
                ['label' => 'สมุดสุขภาพ', 'done' => false],
            ],
        ], $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function createAppointmentViaApi(array $attributes = []): array
    {
        return $this->postJson($this->appointmentsPath(), $this->appointmentPayload($attributes))
            ->assertCreated()
            ->json('data');
    }

    private function confirm(string $appointmentId, string $version): void
    {
        $this->withHeaders(['If-Match' => $version])
            ->patchJson($this->appointmentsPath($appointmentId), ['status' => 'confirmed'])
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAppointment(array $attributes = [], ?HealthProfile $profile = null): Appointment
    {
        $profile ??= $this->profile;

        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return Appointment::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'Directly created appointment',
            'provider_name' => null,
            'facility' => null,
            'purpose' => null,
            'location' => null,
            'scheduled_at' => Carbon::now()->addDay(),
            'duration_minutes' => null,
            'status' => Appointment::STATUS_PLANNED,
            'cancelled_reason' => null,
            'rescheduled_from_id' => null,
            'transported_by_task_id' => null,
            'prepared_documents' => [],
            'follow_up_task_id' => null,
            'version' => 1,
        ], $attributes));
    }
}
