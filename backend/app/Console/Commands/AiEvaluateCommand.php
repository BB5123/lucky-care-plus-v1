<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Application\Evaluation\EvaluationRunner;
use App\Modules\AiGateway\Application\Evaluation\LiveEvaluationRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Runs the six evaluation suites of `.ai/08-AI-GATEWAY.md` section 6.
 *
 * Offline (default) the command exercises the gateway's guard components with
 * synthetic fixtures and a deterministic in-process provider: no network, AI
 * stays disabled, and CI can gate on it.
 *
 * `--live` adds the three model-behaviour suites (injection, grounding, safety)
 * against the configured provider. It deliberately forces `ai.enabled` for this
 * process only, refuses when no provider is configured, and caps the number of
 * provider calls. The leakage, scope and refusal suites stay offline because
 * they are decided before a provider is reached.
 */
final class AiEvaluateCommand extends Command
{
    protected $signature = 'ai:evaluate
        {--live : Send the model-behaviour suites to the configured provider (real network calls)}
        {--max-calls=50 : Hard cap on provider calls in --live mode}
        {--base-url= : Point --live at another OpenAI-compatible endpoint (e.g. a tunnel to a local model)}
        {--model= : Override the model name used by --live}
        {--json : Print the result as JSON instead of a table}';

    protected $description = 'Run the AI gateway evaluation suites (offline by default; --live uses the provider)';

    public function handle(EvaluationRunner $runner): int
    {
        $live = (bool) $this->option('live');
        $results = [];
        $liveRunner = null;

        if ($live) {
            // The adapter and the router both refuse while the gate is closed.
            // This command is the explicit operator action that opens it for
            // its own process; nothing is persisted.
            config(['ai.enabled' => true]);

            try {
                $this->applyEndpointOverride();
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $liveRunner = $this->laravel->make(LiveEvaluationRunner::class);

            try {
                $liveRunner->preflight();
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            foreach (['leakage', 'scope', 'refusal'] as $suite) {
                $results[] = $runner->run($suite) + ['mode' => 'offline'];
            }

            try {
                foreach ($liveRunner->run((int) $this->option('max-calls')) as $result) {
                    $results[] = $result;
                }
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            foreach ($runner->runAll() as $result) {
                $results[] = $result + ['mode' => 'offline'];
            }
        }

        $ok = true;

        foreach ($results as $result) {
            $ok = $ok && (bool) $result['ok'];
        }

        if ((bool) $this->option('json')) {
            $payload = json_encode(
                [
                    'ai_enabled' => (bool) config('ai.enabled', false),
                    'mode' => $live ? 'live' : 'offline',
                    'provider' => $live
                        ? $liveRunner?->providerName()
                        : 'evaluation-provider (in-process)',
                    'model' => $live ? $liveRunner?->model() : null,
                    'ok' => $ok,
                    'usage' => $live ? $liveRunner?->usage() : null,
                    'results' => $results,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            $this->line(is_string($payload) ? $payload : '{}');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if ($live) {
            $this->line(sprintf(
                'AI evaluation — live provider=%s model=%s; AI_ENABLED forced true for this process only.',
                $liveRunner?->providerName() ?? 'unknown',
                $liveRunner?->model() ?? 'unknown',
            ));
        } else {
            $this->line(sprintf(
                'AI evaluation — AI_ENABLED=%s; deterministic in-process provider; no network calls.',
                (bool) config('ai.enabled', false) ? 'true' : 'false',
            ));
        }

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result['suite'],
                $result['mode'] ?? 'offline',
                $result['total'],
                $result['passed'],
                number_format($result['rate'] * 100, 1).'%',
                number_format($result['threshold'] * 100, 1).'%',
                $result['ok'] ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(['Suite', 'Mode', 'Total', 'Passed', 'Rate', 'Threshold', 'Result'], $rows);

        foreach ($results as $result) {
            if ($result['failures'] === []) {
                continue;
            }

            $this->warn($result['suite'].' failures ('.$result['passed'].'/'.$result['total'].'):');

            foreach ($result['failures'] as $failure) {
                $this->line('  - '.$failure);
            }
        }

        if ($live && $liveRunner !== null) {
            $usage = $liveRunner->usage();

            $this->line(sprintf(
                'Provider calls: %d (input %d / output %d tokens, avg %d ms, retries %d).',
                $usage['calls'],
                $usage['input_tokens'],
                $usage['output_tokens'],
                $usage['avg_latency_ms'],
                $usage['retries'],
            ));
        }

        if ($ok) {
            $this->info('All suites meet their threshold.');
        } else {
            $this->error('At least one suite is below its threshold.');
        }

        $this->line($live
            ? 'Scope: injection, grounding and safety hit the real provider; leakage, scope and refusal are decided before any call.'
            : 'Scope: gateway guards with synthetic fixtures. No live provider is configured; AI remains disabled.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Point `--live` at another OpenAI-compatible endpoint without editing .env.
     *
     * This exists so a locally hosted model (for example a Pathumma GGUF behind a
     * tunnel, see `docs/LOCAL-AI-PATHUMMA.md`) can be scored with the same suites
     * as the production provider, which is what makes the numbers comparable.
     *
     * The override changes config in memory only; the adapter still refuses when
     * `ai.enabled` is false, and only this command sets it (for its own process).
     */
    private function applyEndpointOverride(): void
    {
        $baseUrl = trim((string) $this->option('base-url'));
        $model = trim((string) $this->option('model'));

        if ($baseUrl === '' && $model === '') {
            return;
        }

        if ($baseUrl !== '') {
            if (! str_starts_with($baseUrl, 'https://') && ! str_starts_with($baseUrl, 'http://')) {
                throw new RuntimeException('--base-url must be an http(s) URL.');
            }

            config([
                'ai.provider' => 'openai_compatible',
                'ai.openai_compatible.base_url' => rtrim($baseUrl, '/'),
            ]);
        }

        if ($model !== '') {
            config(['ai.openai_compatible.model' => $model]);
        }
    }
}
