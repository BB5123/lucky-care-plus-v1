<?php

namespace Tests\Feature\Retention;

use Tests\TestCase;

/**
 * The retention decision pack must never drift from the code it describes.
 *
 * `docs/RETENTION-DECISION-PACK.md` is what the owner reads before approving deletion,
 * so a stale number there is worse than no document: it would let someone approve a
 * policy the system does not implement. This test parses the markdown table and
 * compares every period with `config/retention.php`.
 */
class RetentionDecisionPackTest extends TestCase
{
    public function test_every_period_in_the_decision_pack_matches_the_policy(): void
    {
        $document = file_get_contents(base_path('docs/RETENTION-DECISION-PACK.md'));
        $this->assertIsString($document, 'the decision pack must exist');

        $defaults = (array) config('retention.defaults');
        $bounds = (array) config('retention.bounds');

        // `documents_medical` is a compatibility alias of `medical_documents` with the
        // same period; the pack documents it once, in the canonical row's note.
        $aliases = ['documents_medical'];

        $checked = 0;

        foreach ($defaults as $type => $months) {
            if (in_array($type, $aliases, true)) {
                $this->assertSame($defaults['medical_documents'], $months, 'an alias must keep the canonical period');
                $checked++;

                continue;
            }
            // Rows look like: | `type` | 60 | 24-120 | note |
            // and the notification rows add "**days**" to both cells.
            $pattern = '/\|\s*`'.preg_quote((string) $type, '/').'`\s*\|\s*(\d+)\s*(?:\*\*days\*\*)?\s*\|\s*([0-9]+-[0-9]+|\*\*fixed\*\*)\s*(?:\*\*days\*\*)?\s*\|/u';
            $matched = preg_match($pattern, (string) $document, $matches);

            $this->assertSame(1, $matched, "the decision pack has no row for [{$type}]");
            $this->assertSame(
                (int) $months,
                (int) $matches[1],
                "the decision pack says [{$type}] keeps {$matches[1]} months, the policy says {$months}",
            );

            $range = $matches[2];

            if ($range === '**fixed**') {
                $this->assertContains($type, (array) config('retention.non_overridable'));
            } else {
                $this->assertArrayHasKey($type, $bounds, "[{$type}] has a range in the doc but no bounds in the policy");
                $this->assertSame(
                    $bounds[$type][0].'-'.$bounds[$type][1],
                    $range,
                    "the decision pack shows the wrong range for [{$type}]",
                );
            }

            $checked++;
        }

        $this->assertSame(count($defaults), $checked);
    }

    public function test_the_pack_states_the_inactivity_and_reminder_constants(): void
    {
        $document = (string) file_get_contents(base_path('docs/RETENTION-DECISION-PACK.md'));

        $this->assertStringContainsString((string) config('retention.inactivity_months'), $document);

        foreach ((array) config('retention.notification_days_before') as $days) {
            $this->assertStringContainsString((string) $days, $document);
        }
    }

    public function test_retention_is_still_dry_by_default(): void
    {
        // The pack claims nothing is deleted today. If someone flips the flag, this
        // test fails and forces them to update the document (and justify it).
        $this->assertTrue((bool) config('retention.dry_run'));
    }
}
