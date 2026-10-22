<?php

namespace Tests\Feature\Authorization;

use App\Modules\Authz\Authorizer;
use App\Modules\Authz\RoleGate;
use Tests\TestCase;

/**
 * Matrix A–E conformance against the frozen
 * docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md.
 *
 * The document is the source of truth. A mismatch is reported with the matrix,
 * row, role, document cell, and RoleGate result so it can be fixed without
 * weakening this test.
 *
 * Conditional notation (`C`, `C*`, `C**`, `A*`, and `A/C`) is asserted as
 * `true` at the role-gate level: the document says the role may hold the
 * action once its condition is met, and the condition (grant, ownership,
 * consent, scope, assigned task, verified authority) is enforced by the
 * Authorizer pipeline after the gate. Existing GRANT-* and CONSENT-* tests
 * cover those later checks.
 *
 * Explicit choices:
 * - `N/A` is asserted as `false`: it means the role is not applicable to the
 *   action in the standard product, so it is not a permission grant (F-01).
 * - `break_glass.use` is asserted as `false` even where Matrix D says `C*`,
 *   because break-glass is disabled in the MVP (ADR-002). This override is
 *   limited to the permissions in MVP_DISABLED_PERMISSIONS.
 * - `legal_guardian` Matrix A/B/D `C` cells are `true` at the gate; guardian
 *   authority verification itself is Phase 2 and is not encoded here.
 * - `healthcare_professional` has its own role branch because Matrix B gives
 *   it `C` for "Create profile" while a caregiver is `D`.
 */
class MatrixConformanceTest extends TestCase
{
    /**
     * Reviewed row → permission-ID map. A row with no permission yet must map
     * to [] and be listed in DEFERRED_ROWS with its implementing phase.
     *
     * @var array<string, list<string>>
     */
    private const ROW_TO_PERMISSIONS = [
        // Matrix A — workspace administration.
        'Read workspace metadata' => ['workspace.read'],
        'Update workspace settings' => ['workspace.update'],
        'Read member list' => ['workspace.member.read'],
        'Invite member' => ['workspace.member.invite'],
        'Change member role' => ['workspace.member.update_role'],
        'Suspend/remove member' => ['workspace.member.suspend', 'workspace.member.remove'],
        'Accept own invitation' => ['workspace.invitation.accept'],

        // Matrix B — profile and health data.
        'Read person/profile overview' => ['person.read', 'health_profile.read'],
        'Create profile' => ['person.create', 'health_profile.create'],
        'Update demographics' => ['person.update'],
        'Read measurements' => ['health_measurement.read'],
        'Create measurement' => ['health_measurement.create'],
        'Correct measurement' => ['health_measurement.correct'],
        'Delete measurement' => ['health_measurement.delete'],
        'Read conditions/allergies' => ['health_condition.read', 'allergy.read'],
        'Update conditions/allergies' => ['health_condition.correct', 'allergy.update'],
        'Read medication plan' => ['medication_plan.read'],
        'Create/update medication plan' => ['medication_plan.create', 'medication_plan.update'],
        'Record reported medication event' => ['medication_record.create'],
        'Read medical documents' => ['medical_document.read'],
        'Upload medical document' => ['medical_document.upload'],
        'Share/export medical document' => ['medical_document.share', 'medical_document.export'],

        // Matrix C — care coordination.
        'Read care plan' => ['care_plan.read'],
        'Create/update care plan' => ['care_plan.create', 'care_plan.update'],
        'Create task' => ['care_task.create'],
        'Read task' => ['care_task.read'],
        'Assign/reassign task' => ['care_task.assign'],
        'Accept/start assigned task' => ['care_task.accept', 'care_task.start'],
        'Complete task' => ['care_task.complete'],
        'Verify task' => ['care_task.verify'],
        'Reopen/cancel task' => ['care_task.reopen', 'care_task.cancel'],
        'Read task evidence' => ['care_task.evidence.read'],

        // Matrix D — consent, delegation, AI, and export.
        'Read own/authorized consent status' => ['consent.read'],
        'Record consent' => ['consent.record'],
        'Withdraw consent' => ['consent.withdraw'],
        'Create access grant' => ['access_grant.create'],
        'Revoke grant' => ['access_grant.revoke'],
        'Use health data in AI' => ['ai.health_context.use'],
        'Read AI conversation' => ['ai.conversation.read'],
        'Generate health report' => ['health_report.create'],
        'Export/download health data' => ['data_export.request', 'data_export.download'],
        'Read audit events' => ['audit_event.read'],
        // Catalogued but disabled in the MVP; see MVP_DISABLED_PERMISSIONS.
        'Use break-glass access' => ['break_glass.use'],

        // Matrix E — self-service preferences and self-audit.
        'Read own notification preferences' => ['notification.preference.read'],
        'Update own notification preferences' => ['notification.preference.update'],
        'Read own notification delivery status' => ['notification.delivery.read'],
        'Read own audit events' => ['audit_event.read_own'],
    ];

    /**
     * Rows with no permission yet, mapped to the phase that will implement
     * them. Every parsed row must be in ROW_TO_PERMISSIONS (with a non-empty
     * list) or here with an empty list.
     *
     * @var array<string, string>
     */
    private const DEFERRED_ROWS = [
        // None in the frozen v1.1.0 matrices.
    ];

    /**
     * Document column order per matrix. A combined document column (Matrix D
     * and E "Platform Support/Admin") expands to both concrete roles.
     *
     * @var array<string, list<string|list<string>>>
     */
    private const MATRIX_ROLES = [
        'A' => [
            'profile_owner',
            'legal_guardian',
            'workspace_admin',
            'caregiver',
            'viewer',
            'platform_support',
            'platform_admin',
        ],
        'B' => [
            'profile_owner',
            'legal_guardian',
            'workspace_admin',
            'caregiver',
            'viewer',
            'healthcare_professional',
        ],
        'C' => [
            'profile_owner',
            'legal_guardian',
            'workspace_admin',
            'caregiver',
            'viewer',
        ],
        'D' => [
            'profile_owner',
            'legal_guardian',
            'workspace_admin',
            'caregiver',
            'viewer',
            ['platform_support', 'platform_admin'],
        ],
        'E' => [
            'profile_owner',
            'legal_guardian',
            'workspace_admin',
            'caregiver',
            'viewer',
            ['platform_support', 'platform_admin'],
        ],
    ];

    /** Number of role columns in each fixed-width matrix. */
    private const MATRIX_CELL_COUNT = [
        'A' => 7,
        'B' => 6,
        'C' => 5,
        'D' => 6,
        'E' => 6,
    ];

    /**
     * Matrix D says C* for platform roles, but the feature is disabled in the
     * MVP (ADR-002), so the gate must remain false.
     *
     * @var list<string>
     */
    private const MVP_DISABLED_PERMISSIONS = [
        'break_glass.use',
    ];

    public function test_matrices_a_to_e_match_the_role_gate(): void
    {
        $path = __DIR__.'/../../../../docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md';

        $this->assertFileExists($path, 'The frozen DATA_ACCESS_MATRIX.md must be readable from the repo root.');

        $parsed = $this->parseMatrices($path);

        $this->assertSame(
            ['A', 'B', 'C', 'D', 'E'],
            array_keys($parsed),
            'The parser must find all five matrices; a silently missing matrix would make this test worthless.',
        );

        $gate = new RoleGate;
        $authorizer = $this->app->make(Authorizer::class);

        $checked = 0;
        $failures = [];
        $seenRows = [];

        foreach ($parsed as $matrix => $rows) {
            $this->assertNotEmpty($rows, "Matrix {$matrix} was parsed with no data rows.");

            foreach ($rows as $row) {
                $action = $row['action'];
                $seenRows[$action] = true;

                $this->assertArrayHasKey(
                    $action,
                    self::ROW_TO_PERMISSIONS,
                    "Matrix {$matrix} row \"{$action}\" is not mapped in ROW_TO_PERMISSIONS and is not listed in DEFERRED_ROWS.",
                );

                $permissions = self::ROW_TO_PERMISSIONS[$action];

                if ($permissions === []) {
                    $this->assertArrayHasKey(
                        $action,
                        self::DEFERRED_ROWS,
                        "Matrix {$matrix} row \"{$action}\" has no permission but is not listed in DEFERRED_ROWS.",
                    );
                    $this->assertNotSame(
                        '',
                        trim(self::DEFERRED_ROWS[$action]),
                        "Deferred row \"{$action}\" must name the phase that will implement it.",
                    );

                    continue;
                }

                $this->assertArrayNotHasKey(
                    $action,
                    self::DEFERRED_ROWS,
                    "Matrix {$matrix} row \"{$action}\" is both mapped and deferred; keep one source of truth.",
                );

                $this->assertCount(
                    self::MATRIX_CELL_COUNT[$matrix],
                    $row['cells'],
                    "Matrix {$matrix} row \"{$action}\" could not be parsed into the expected number of cells. Raw line(s): {$row['raw']}",
                );

                foreach ($row['cells'] as $column => $documentCell) {
                    $columnRoles = self::MATRIX_ROLES[$matrix][$column];
                    $roles = is_array($columnRoles) ? $columnRoles : [$columnRoles];
                    $documentKind = $this->documentCellKind($documentCell);
                    $documentExpectsAllow = $this->documentExpectsAllow($documentCell);

                    foreach ($roles as $role) {
                        foreach ($permissions as $permission) {
                            $mapping = $authorizer->mappingFor($permission);

                            $this->assertNotNull(
                                $mapping,
                                "Permission \"{$permission}\" from row \"{$action}\" has no scope/action mapping.",
                            );

                            $expected = in_array($permission, self::MVP_DISABLED_PERMISSIONS, true)
                                ? false
                                : $documentExpectsAllow;

                            $actual = $gate->permits($role, $permission, $mapping['action']);
                            $checked++;

                            if ($actual !== $expected) {
                                $failures[] = sprintf(
                                    'matrix %s row "%s" role "%s": document says %s, RoleGate says %s (permission %s)',
                                    $matrix,
                                    $action,
                                    $role,
                                    $documentKind,
                                    $actual ? 'true' : 'false',
                                    $permission,
                                );
                            }
                        }
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            100,
            $checked,
            "Only {$checked} matrix role/permission cells were checked; the parser or the matrix may have regressed.",
        );

        $this->assertSame(
            [],
            $failures,
            "Matrix conformance failures:\n- ".implode("\n- ", $failures),
        );

        $mappedButNotInDocument = array_values(array_diff(
            array_keys(self::ROW_TO_PERMISSIONS),
            array_keys($seenRows),
        ));

        $this->assertSame(
            [],
            $mappedButNotInDocument,
            'ROW_TO_PERMISSIONS contains rows that no longer appear in the document: '.implode(', ', $mappedButNotInDocument),
        );

        $deferredButNotInDocument = array_values(array_diff(
            array_keys(self::DEFERRED_ROWS),
            array_keys($seenRows),
        ));

        $this->assertSame(
            [],
            $deferredButNotInDocument,
            'DEFERRED_ROWS contains rows that no longer appear in the document: '.implode(', ', $deferredButNotInDocument),
        );
    }

    /**
     * @return array<string, list<array{action: string, cells: list<string>, raw: string}>>
     */
    private function parseMatrices(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($lines, "Could not read {$path}.");

        $matrices = [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/^##\s+\d+\.\s+Matrix\s+([A-E])\b/', $line, $matches)) {
                continue;
            }

            $matrix = $matches[1];

            $matrices[$matrix] = $matrix === 'E'
                ? $this->parsePipeTable($lines, $index + 1)
                : $this->parseFixedWidthTable($lines, $index + 1, $matrix);
        }

        return $matrices;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{action: string, cells: list<string>, raw: string}>
     */
    private function parseFixedWidthTable(array $lines, int $start, string $matrix): array
    {
        $separatorIndex = null;
        $spans = [];

        for ($i = $start; $i < count($lines); $i++) {
            if (preg_match('/^##\s/', $lines[$i])) {
                break;
            }

            if ($this->isHeaderSeparatorLine($lines[$i])) {
                $separatorIndex = $i;
                $spans = $this->separatorSpans($lines[$i]);
                break;
            }
        }

        $this->assertNotNull($separatorIndex, "Matrix {$matrix}: no ASCII table separator found.");
        $this->assertCount(
            self::MATRIX_CELL_COUNT[$matrix] + 1,
            $spans,
            "Matrix {$matrix}: separator does not define one action column and ".self::MATRIX_CELL_COUNT[$matrix].' role columns.',
        );

        $rows = [];

        for ($i = $separatorIndex + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^##\s/', $line)) {
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            // A closing separator ends the table. Matrix C has none and ends
            // at its non-indented footnote instead.
            if ($this->isSeparatorLine($line)) {
                break;
            }

            if (! str_starts_with($line, '  ')) {
                break;
            }

            $cells = [];

            foreach ($spans as [$offset, $length]) {
                $cells[] = trim($this->slice($line, $offset, $length));
            }

            $action = array_shift($cells);
            $hasCells = count(array_filter($cells, static fn (string $cell): bool => $cell !== '')) > 0;

            if ($action !== '' && $hasCells) {
                $rows[] = ['action' => $action, 'cells' => $cells, 'raw' => $line];

                continue;
            }

            if ($action !== '' && ! $hasCells) {
                $this->assertNotEmpty($rows, "Matrix {$matrix}: action continuation line before any data row: {$line}");
                $last = count($rows) - 1;
                $rows[$last]['action'] = trim($rows[$last]['action'].' '.$action);
                $rows[$last]['raw'] .= ' | '.$line;

                continue;
            }

            if ($action === '' && $hasCells) {
                $this->assertNotEmpty($rows, "Matrix {$matrix}: cell continuation line before any data row: {$line}");
                $last = count($rows) - 1;

                foreach ($cells as $column => $cell) {
                    if ($cell !== '') {
                        $rows[$last]['cells'][$column] = trim($rows[$last]['cells'][$column].' '.$cell);
                    }
                }

                $rows[$last]['raw'] .= ' | '.$line;

                continue;
            }
        }

        foreach ($rows as $row) {
            $this->assertCount(
                self::MATRIX_CELL_COUNT[$matrix],
                $row['cells'],
                "Matrix {$matrix} row \"{$row['action']}\" could not be parsed into the expected number of cells. Raw line(s): {$row['raw']}",
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{action: string, cells: list<string>, raw: string}>
     */
    private function parsePipeTable(array $lines, int $start): array
    {
        $rows = [];
        $headerSeen = false;

        for ($i = $start; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^##\s/', $line)) {
                break;
            }

            if (! str_starts_with(ltrim($line), '|')) {
                if ($headerSeen) {
                    break;
                }

                continue;
            }

            $cells = array_map('trim', explode('|', trim($line)));

            // Drop the empty strings produced by the leading/trailing pipes.
            array_shift($cells);
            array_pop($cells);

            if (! $headerSeen) {
                $this->assertCount(
                    self::MATRIX_CELL_COUNT['E'] + 1,
                    $cells,
                    "Matrix E header could not be parsed: {$line}",
                );
                $headerSeen = true;

                continue;
            }

            if ($this->isPipeSeparatorRow($cells)) {
                continue;
            }

            $action = array_shift($cells);

            $rows[] = ['action' => $action, 'cells' => $cells, 'raw' => $line];
        }

        foreach ($rows as $row) {
            $this->assertCount(
                self::MATRIX_CELL_COUNT['E'],
                $row['cells'],
                "Matrix E row \"{$row['action']}\" could not be parsed into the expected number of cells. Raw line: {$row['raw']}",
            );
        }

        return $rows;
    }

    private function isSeparatorLine(string $line): bool
    {
        return preg_match('/^\s*-{3,}(?:\s+-{3,})*\s*$/', $line) === 1;
    }

    /**
     * The header separator has one dash run per column, unlike the single-run
     * top/bottom borders used by Matrices A, B, and D.
     */
    private function isHeaderSeparatorLine(string $line): bool
    {
        return preg_match('/^\s*-{3,}(\s+-{3,})+\s*$/', $line) === 1;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function separatorSpans(string $line): array
    {
        preg_match_all('/-+/', $line, $matches, PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $match): array => [$match[1], strlen($match[0])],
            $matches[0],
        );
    }

    /**
     * @param  list<string>  $cells
     */
    private function isPipeSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return $cells !== [];
    }

    private function slice(string $line, int $offset, int $length): string
    {
        if ($offset >= strlen($line)) {
            return '';
        }

        return substr($line, $offset, $length);
    }

    private function documentCellKind(string $cell): string
    {
        $cell = trim($cell);

        return match (true) {
            str_starts_with($cell, 'N/A') => 'N/A',
            str_starts_with($cell, 'D') => 'D',
            str_starts_with($cell, 'A') => 'A',
            str_starts_with($cell, 'C') => 'C',
            default => $this->fail("Unrecognised matrix cell \"{$cell}\"."),
        };
    }

    private function documentExpectsAllow(string $cell): bool
    {
        return match ($this->documentCellKind($cell)) {
            'A', 'C' => true,
            'D', 'N/A' => false,
        };
    }
}
