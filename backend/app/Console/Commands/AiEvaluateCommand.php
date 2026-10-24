<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Application\Evaluation\EvaluationRunner;
use Illuminate\Console\Command;

/**
 * Runs the six evaluation suites of `.ai/08-AI-GATEWAY.md` section 6 against
 * the deterministic in-process provider.
 *
 * The command never enables AI and never opens a network connection: it
 * exercises the gateway's own guard components with synthetic fixtures. A
 * suite below its threshold makes the command exit non-zero, so CI can gate
 * on it.
 */
final class AiEvaluateCommand extends Command
{
    protected $signature = 'ai:evaluate
        {--json : Print the result as JSON instead of a table}';

    protected $description = 'Run the AI gateway evaluation suites (offline, no network, AI stays disabled)';

    public function handle(EvaluationRunner $runner): int
    {
        $results = $runner->runAll();

        $ok = true;

        foreach ($results as $result) {
            $ok = $ok && (bool) $result['ok'];
        }

        if ((bool) $this->option('json')) {
            $payload = json_encode(
                [
                    'ai_enabled' => (bool) config('ai.enabled', false),
                    'provider' => 'evaluation-provider (in-process)',
                    'ok' => $ok,
                    'results' => $results,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            $this->line(is_string($payload) ? $payload : '{}');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'AI evaluation — AI_ENABLED=%s; deterministic in-process provider; no network calls.',
            (bool) config('ai.enabled', false) ? 'true' : 'false',
        ));

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result['suite'],
                $result['total'],
                $result['passed'],
                number_format($result['rate'] * 100, 1).'%',
                number_format($result['threshold'] * 100, 1).'%',
                $result['ok'] ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(['Suite', 'Total', 'Passed', 'Rate', 'Threshold', 'Result'], $rows);

        foreach ($results as $result) {
            if ($result['ok'] || $result['failures'] === []) {
                continue;
            }

            $this->warn($result['suite'].' failures:');

            foreach ($result['failures'] as $failure) {
                $this->line('  - '.$failure);
            }
        }

        if ($ok) {
            $this->info('All six suites meet their threshold.');
        } else {
            $this->error('At least one suite is below its threshold.');
        }

        $this->line('Scope: gateway guards with synthetic fixtures. No live provider is configured; AI remains disabled.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
