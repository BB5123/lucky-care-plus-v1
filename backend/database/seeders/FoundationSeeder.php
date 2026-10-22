<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Models\HealthProfile;
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

            $caregiverPerson = Person::query()->updateOrCreate(
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

            // -- Health profiles: one per person (ADR-009) -------------------
            $this->upsertHealthProfile($tenant, $workspace, $ownerPerson, null);
            $this->upsertHealthProfile($tenant, $workspace, $caregiverPerson, null);
            $dependentProfile = $this->upsertHealthProfile($tenant, $workspace, $dependent, $ownerPerson->id);

            // -- Access grants covering the positive and negative paths ------
            $this->upsertGrant(
                $tenant,
                $workspace,
                $dependentProfile,
                $owner,
                $caregiver,
                'seeded_active_grant',
                ['profile.basic', 'vitals', 'medications'],
                ['read', 'create'],
                now()->subDay(),
                now()->addYear(),
                'active',
            );
            $this->upsertGrant(
                $tenant,
                $workspace,
                $dependentProfile,
                $owner,
                $caregiver,
                'seeded_expired_grant',
                ['profile.basic'],
                ['read'],
                now()->subYears(2),
                now()->subDay(),
                'expired',
            );
            $this->upsertGrant(
                $tenant,
                $workspace,
                $dependentProfile,
                $owner,
                $caregiver,
                'seeded_revoked_grant',
                ['profile.basic', 'vitals'],
                ['read'],
                now()->subMonth(),
                now()->addYear(),
                'revoked',
                revokedBy: $owner,
            );

            // Consent is append-only, so seed it only when it does not exist.
            $hasConsent = ConsentRecord::query()
                ->where('workspace_id', $workspace->id)
                ->where('profile_id', $dependentProfile->id)
                ->where('consent_type', 'health_processing')
                ->where('purpose', 'care_coordination')
                ->exists();

            if (! $hasConsent) {
                ConsentRecord::query()->create([
                    'tenant_id' => $tenant->id,
                    'workspace_id' => $workspace->id,
                    'profile_id' => $dependentProfile->id,
                    'consent_type' => 'health_processing',
                    'purpose' => 'care_coordination',
                    'scope' => ['profile.basic', 'vitals', 'medications'],
                    'policy_version' => 'v1.0.0',
                    'consenting_subject_id' => $dependent->id,
                    'legal_basis_type' => 'consent',
                    'granted_at' => now(),
                    'withdrawn_at' => null,
                    'evidence_reference' => 'seed:dev',
                    'superseded_by_id' => null,
                ]);
            }
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

    private function upsertHealthProfile(
        Tenant $tenant,
        Workspace $workspace,
        Person $person,
        ?string $managedByPersonId,
    ): HealthProfile {
        return HealthProfile::query()->updateOrCreate(
            ['person_id' => $person->id],
            [
                'tenant_id' => $tenant->id,
                'workspace_id' => $workspace->id,
                'managed_by_person_id' => $managedByPersonId,
                'version' => 1,
            ],
        );
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    private function upsertGrant(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $grantor,
        User $grantee,
        string $purpose,
        array $scopes,
        array $actions,
        \DateTimeInterface $validFrom,
        \DateTimeInterface $expiresAt,
        string $status,
        ?User $revokedBy = null,
    ): AccessGrant {
        return AccessGrant::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'profile_id' => $profile->id,
                'grantee_user_id' => $grantee->id,
                'purpose' => $purpose,
            ],
            [
                'tenant_id' => $tenant->id,
                'granted_by' => $grantor->id,
                'resource_scope' => $scopes,
                'allowed_actions' => $actions,
                'valid_from' => $validFrom,
                'expires_at' => $expiresAt,
                'status' => $status,
                'revoked_at' => $status === 'revoked' ? now()->subDay() : null,
                'revoked_by' => $status === 'revoked' ? $revokedBy?->id : null,
                'revocation_reason' => $status === 'revoked' ? 'seeded for negative tests' : null,
                'constraints' => [],
            ],
        );
    }
}
