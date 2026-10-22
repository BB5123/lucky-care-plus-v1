<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables that carry both tenant_id and workspace_id.
     *
     * @var list<string>
     */
    private array $workspaceScopedTables = [
        'workspace_memberships',
        'invitations',
        'persons',
        'contact_points',
        'person_relationships',
    ];

    public function up(): void
    {
        // workspaces has no workspace_id column (it is the workspace itself),
        // so its policy isolates by tenant only.
        DB::statement('alter table workspaces enable row level security');
        DB::statement('alter table workspaces force row level security');
        DB::statement(
            'create policy tenant_isolation on workspaces '
            ."using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)"
        );

        foreach ($this->workspaceScopedTables as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
            DB::statement(
                "create policy tenant_isolation on {$table} "
                .'using ('
                ."tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid "
                .'and workspace_id = nullif(current_setting(\'app.workspace_id\', true), \'\')::uuid'
                .')'
            );
        }
    }

    public function down(): void
    {
        foreach ($this->workspaceScopedTables as $table) {
            DB::statement("drop policy if exists tenant_isolation on {$table}");
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
        }

        DB::statement('drop policy if exists tenant_isolation on workspaces');
        DB::statement('alter table workspaces no force row level security');
        DB::statement('alter table workspaces disable row level security');
    }
};
