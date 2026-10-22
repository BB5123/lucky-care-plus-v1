<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\People\Models\ContactPoint;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Development/staging foundation data. Idempotent: running it twice does not
 * create duplicate tenants, workspaces, users, memberships, persons, or
 * contact points. Never use this seeder in production.
 */
class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => 'default'],
                ['name' => 'Lucky Care Plus'],
            );

            // The workspaces policy isolates by tenant only, so a placeholder
            // workspace id is enough to look up an existing workspace.
            $candidateWorkspaceId = Uuid7::generate();
            TenantContext::set($tenant->id, $candidateWorkspaceId);

            $workspace = Workspace::query()
                ->where('tenant_id', $tenant->id)
                ->where('slug', 'jaidee-family')
                ->first();

            if ($workspace === null) {
                $workspace = new Workspace([
                    'tenant_id' => $tenant->id,
                    'name' => 'ครอบครัวใจดี',
                    'slug' => 'jaidee-family',
                    'settings' => [],
                    'break_glass_enabled' => false,
                ]);
                $workspace->id = $candidateWorkspaceId;
                $workspace->save();
            }

            TenantContext::set($tenant->id, $workspace->id);

            $owner = $this->upsertUser('owner@example.test', 'สมชาย ใจดี');
            $caregiver = $this->upsertUser('caregiver@example.test', 'สมหญิง ใจดี');
            $viewer = $this->upsertUser('viewer@example.test', 'สมศักดิ์ ใจดี');

            $this->upsertMembership($workspace, $owner, 'profile_owner');
            $this->upsertMembership($workspace, $caregiver, 'caregiver');
            $this->upsertMembership($workspace, $viewer, 'viewer');

            $ownerPerson = Person::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $owner->id],
                [
                    'tenant_id' => $tenant->id,
                    'display_name' => 'สมชาย ใจดี',
                    'birth_date' => '1965-03-12',
                    'managed_by_person_id' => null,
                ],
            );

            Person::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $caregiver->id],
                [
                    'tenant_id' => $tenant->id,
                    'display_name' => 'สมหญิง ใจดี',
                    'birth_date' => '1970-08-24',
                    'managed_by_person_id' => null,
                ],
            );

            $dependent = Person::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'display_name' => 'ลุงสมพงษ์ ใจดี'],
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => null,
                    'birth_date' => '1942-11-02',
                    'managed_by_person_id' => $ownerPerson->id,
                ],
            );

            $this->upsertContactPoint($tenant, $workspace, $dependent, 'phone', '0800000001', true);
            $this->upsertContactPoint($tenant, $workspace, $dependent, 'line', '@jaidee-dependent', false);
        });
    }

    private function upsertUser(string $email, string $name): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }

    private function upsertMembership(Workspace $workspace, User $user, string $role): void
    {
        WorkspaceMembership::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            [
                'tenant_id' => $workspace->tenant_id,
                'role' => $role,
                'status' => 'active',
            ],
        );
    }

    private function upsertContactPoint(
        Tenant $tenant,
        Workspace $workspace,
        Person $person,
        string $type,
        string $value,
        bool $isPrimary,
    ): void {
        ContactPoint::query()->updateOrCreate(
            ['person_id' => $person->id, 'type' => $type],
            [
                'tenant_id' => $tenant->id,
                'workspace_id' => $workspace->id,
                'value' => $value,
                'is_primary' => $isPrimary,
            ],
        );
    }
}
