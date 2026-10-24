<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeHtmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches one public health page into a reviewable JSON source (TASK G4-KB).
 *
 * Rules: https only, allow-listed government hosts, robots.txt respected
 * (DDC disallows /uploads/, where its PDFs live), one page per invocation and
 * no automatic retry storm. The page is parsed into plain text; nothing is
 * written to the application database.
 */
final class KnowledgeFetchCommand extends Command
{
    protected $signature = 'knowledge:fetch
        {url : Public https URL of a government health page}
        {--id= : Source id (lowercase slug); derived from the URL when omitted}
        {--title= : Override the extracted title}
        {--publisher= : Publisher shown in citations (default: กรมควบคุมโรค กระทรวงสาธารณสุข)}
        {--category=disease : disease|prevention|news|guideline}
        {--force : Overwrite an existing source file}';

    protected $description = 'Fetch one government health page into database/knowledge/sources';

    public function handle(KnowledgeHtmlParser $parser): int
    {
        $url = trim((string) $this->argument('url'));
        $error = $this->validateUrl($url);

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $category = (string) $this->option('category');

        if (! in_array($category, ['disease', 'prevention', 'news', 'guideline'], true)) {
            $this->error('category must be disease|prevention|news|guideline');

            return self::FAILURE;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('knowledge.fetch.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout((int) config('knowledge.fetch.timeout_seconds', 30))->get($url);
        } catch (Throwable $exception) {
            $this->error('Fetch failed: '.$exception::class);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Fetch failed with HTTP '.$response->status());

            return self::FAILURE;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($contentType, 'text/html')) {
            $this->error('Not an HTML page (Content-Type: '.($contentType !== '' ? $contentType : 'unknown').').');

            return self::FAILURE;
        }

        $body = $response->body();

        if (strlen($body) > (int) config('knowledge.fetch.max_html_bytes', 5 * 1024 * 1024)) {
            $this->error('The page is larger than the configured limit.');

            return self::FAILURE;
        }

        try {
            $article = $parser->parse($body);
        } catch (Throwable $exception) {
            $this->error('Could not extract the article: '.$exception->getMessage());

            return self::FAILURE;
        }

        $id = trim((string) $this->option('id')) ?: $this->suggestId($url);

        if (preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/', $id) !== 1) {
            $this->error('The source id must be a lowercase slug (a-z, 0-9, -).');

            return self::FAILURE;
        }

        $directory = (string) config('knowledge.sources_path');
        $path = rtrim($directory, '/\\').'/'.$id.'.json';

        if (file_exists($path) && ! (bool) $this->option('force')) {
            $this->error('Source already exists: '.$path.' (pass --force to overwrite)');

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('Cannot create '.$directory);

            return self::FAILURE;
        }

        $source = [
            'id' => $id,
            'title' => trim((string) $this->option('title')) ?: $article['title'],
            'publisher' => trim((string) $this->option('publisher'))
                ?: 'กรมควบคุมโรค กระทรวงสาธารณสุข',
            'url' => $url,
            'category' => $category,
            'language' => 'th',
            'fetched_at' => now()->toDateString(),
            'published_at' => $article['published_at'],
            'review_status' => 'unreviewed',
            'license_note' => 'ข้อมูลเผยแพร่โดยหน่วยงานราชการไทย; ระบบเก็บข้อความเพื่อการค้นคืนและแสดงแหล่งอ้างอิง',
            'headings' => $article['headings'],
            'text' => $article['text'],
        ];

        $encoded = json_encode(
            $source,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($encoded)) {
            $this->error('Could not encode the source.');

            return self::FAILURE;
        }

        file_put_contents($path, $encoded."\n");

        $this->info('Saved: '.$path);
        $this->line('title: '.$source['title']);
        $this->line('text: '.mb_strlen($source['text']).' characters, headings: '.count($source['headings']));

        return self::SUCCESS;
    }

    private function validateUrl(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            return 'URL must be an https URL.';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = (array) config('knowledge.fetch.allowed_hosts', []);

        if (! in_array($host, $allowed, true)) {
            return 'Host is not in the allow-list: '.$host;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ((array) config('knowledge.fetch.disallowed_path_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($path, $prefix)) {
                return 'Path is disallowed by the source robots.txt: '.$path;
            }
        }

        return null;
    }

    private function suggestId(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $parts = explode('.', $host);
        $label = ($parts[0] ?? '') === 'www' ? ($parts[1] ?? 'source') : ($parts[0] ?? 'source');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($label === 'ddc' && isset($query['d']) && is_scalar($query['d'])) {
            return 'ddc-disease-'.preg_replace('/[^0-9]/', '', (string) $query['d']);
        }

        return $label.'-'.substr(hash('sha256', $url), 0, 12);
    }
}
