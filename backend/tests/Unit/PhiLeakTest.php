<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * SEC-PHI-001..003 — PHI/secret leak guard for log calls.
 *
 * Walks every `*.php` file under `app/` (the vendor tree is never scanned) and
 * inspects the argument text of every Laravel `Log::<level>(...)` call. A call
 * is flagged when its arguments contain a likely health-data or credential
 * key. The failure message names the file and line, so the fix is obvious in
 * CI output.
 *
 * Deliberate exceptions live in ALLOWLIST below as "<relative path>:<line>" =>
 * "<reason>". It is empty on purpose: widening it is a security decision that
 * must be visible in review. The allowlist is itself tested (SEC-PHI-003), so
 * an entry that no longer matches a call site fails instead of rotting.
 *
 * This is a pure unit test: it reads source text and never boots the framework.
 */
class PhiLeakTest extends TestCase
{
    /** @var list<string> */
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** @var list<string> */
    private const PHI_TOKENS = [
        'name',
        'phone',
        'email',
        'pin',
        'otp',
        'code_hash',
        'diagnosis',
        'note',
        'measurement',
        '->value',
    ];

    /**
     * @var array<string, string>
     */
    private const ALLOWLIST = [];

    private function backendPath(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * @return list<array{line: int, level: string, arguments: string}>
     */
    private function logCallSites(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            if ($name !== 'Log' && $name !== 'Illuminate\Support\Facades\Log' && ! str_ends_with($name, '\\Log')) {
                continue;
            }

            $operator = $this->nextSignificantToken($tokens, $i + 1);

            if ($operator === null || $tokens[$operator][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $levelToken = $this->nextSignificantToken($tokens, $operator + 1);

            if ($levelToken === null || ! is_array($tokens[$levelToken])) {
                continue;
            }

            $level = strtolower($tokens[$levelToken][1]);

            if (! in_array($level, self::LEVELS, true)) {
                continue;
            }

            $opening = $this->nextSignificantToken($tokens, $levelToken + 1);

            if ($opening === null || $tokens[$opening] !== '(') {
                continue;
            }

            $depth = 1;
            $arguments = '';

            for ($j = $opening + 1; $j < $count; $j++) {
                $part = $tokens[$j];
                $text = is_array($part) ? $part[1] : $part;

                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }

                $arguments .= $text;
            }

            $sites[] = [
                'line' => $token[2],
                'level' => $level,
                'arguments' => $arguments,
            ];
        }

        return $sites;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($i = $start; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @return list<array{line: int, level: string, hits: list<string>, arguments: string}>
     */
    private function violationsIn(string $source): array
    {
        $violations = [];

        foreach ($this->logCallSites($source) as $site) {
            $hits = [];

            foreach (self::PHI_TOKENS as $needle) {
                if (str_contains($site['arguments'], $needle)) {
                    $hits[] = $needle;
                }
            }

            if ($hits === []) {
                continue;
            }

            $violations[] = [
                'line' => $site['line'],
                'level' => $site['level'],
                'hits' => $hits,
                'arguments' => $site['arguments'],
            ];
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function applicationFiles(): array
    {
        $root = $this->backendPath().'/app';
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/vendor/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function relativePath(string $absolute): string
    {
        $base = $this->backendPath().'/';

        return str_starts_with($absolute, $base)
            ? substr($absolute, strlen($base))
            : $absolute;
    }

    private function excerpt(string $arguments): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($arguments)) ?? '';

        return mb_strimwidth($singleLine, 0, 200, '…');
    }

    public function test_sec_phi_001_the_scanner_detects_a_planted_leak(): void
    {
        $planted = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Log;

        Log::info('user phone updated', ['phone' => $phone]);
        Log::debug('reading', ['value' => $measurement->value]);

        Log::info('tenant_id', ['tenant_id' => $tenantId]);
        PHP;

        $violations = $this->violationsIn($planted);

        $this->assertCount(2, $violations, 'The scanner must flag both planted PHI log calls and ignore the clean one');
        $this->assertSame(4, $violations[0]['line']);
        $this->assertContains('phone', $violations[0]['hits']);
        $this->assertContains('->value', $violations[1]['hits']);
    }

    public function test_sec_phi_002_no_log_call_in_app_embeds_likely_phi(): void
    {
        $offenders = [];

        foreach ($this->applicationFiles() as $absolute) {
            $relative = $this->relativePath($absolute);
            $source = file_get_contents($absolute);

            if (! is_string($source)) {
                $offenders[] = $relative.':0 — file could not be read, so the PHI scan is incomplete';

                continue;
            }

            foreach ($this->violationsIn($source) as $violation) {
                $id = $relative.':'.$violation['line'];

                if (array_key_exists($id, self::ALLOWLIST)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — Log::%s() contains [%s]: %s',
                    $relative,
                    $violation['line'],
                    $violation['level'],
                    implode(', ', $violation['hits']),
                    $this->excerpt($violation['arguments']),
                );
            }
        }

        $this->assertSame([], $offenders, "SEC-PHI-002: possible PHI/secret in a log call (N-02).\n"
            ."Move the value out of the log, or add a reviewed allowlist entry with a written reason.\n"
            ."Offending log calls:\n- ".implode("\n- ", $offenders));
    }

    public function test_sec_phi_003_allowlist_entries_point_at_real_log_sites(): void
    {
        $sites = [];

        foreach ($this->applicationFiles() as $absolute) {
            $relative = $this->relativePath($absolute);
            $source = file_get_contents($absolute);

            if (! is_string($source)) {
                continue;
            }

            foreach ($this->violationsIn($source) as $violation) {
                $sites[$relative.':'.$violation['line']] = true;
            }
        }

        $this->assertSame(
            array_keys(self::ALLOWLIST),
            array_values(array_intersect(array_keys(self::ALLOWLIST), array_keys($sites))),
            'SEC-PHI-003: every allowlist entry must still point at a flagged log call; remove stale entries',
        );

        foreach (self::ALLOWLIST as $id => $reason) {
            $this->assertNotSame('', trim($reason), "SEC-PHI-003: allowlist entry {$id} needs a written reason");
        }
    }
}
