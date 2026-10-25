<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use RuntimeException;

/**
 * Renders the clinician review pack for the health knowledge corpus (TASK REVIEW-PACK).
 *
 * The pack is a decision aid, not a copy of the corpus: for every selected source
 * it shows provenance, the current review decision, the section map and a bounded
 * excerpt (the first {@see self::EXCERPT_CHARS} characters of each section). The
 * output is deterministic so a regeneration produces the same bytes on the same
 * day, which makes the committed document reviewable in a diff.
 *
 * The corpus is Thai. This class never transcodes: PHP strings decoded from the
 * JSON sources are UTF-8 bytes, the renderer only concatenates them, and the
 * command writes the resulting string as UTF-8. A U+FFFD replacement character
 * anywhere in the input is treated as corruption and fails the run instead of
 * publishing a pack with destroyed Thai text.
 */
final class KnowledgeReviewPack
{
    public const EXCERPT_CHARS = 400;

    private const STALE_DAYS = 180;

    public function __construct(private readonly KnowledgeBaseBuilder $builder) {}

    /**
     * @return array{contents: string, selected: int, total: int, unreviewed: int}
     */
    public function generate(string $status = 'unreviewed'): array
    {
        $sources = $this->builder->sources((string) config('knowledge.sources_path'));
        $rows = array_map(fn (array $source): array => $this->row($source), $sources);

        $total = count($rows);
        $unreviewed = count(array_filter(
            $rows,
            static fn (array $row): bool => $row['review_status'] === 'unreviewed',
        ));

        $selected = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $status === 'all' || $row['review_status'] === $status,
        ));

        $contents = $this->render($selected, $status, $total, $unreviewed);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException('The knowledge review pack is not valid UTF-8; refusing to write it.');
        }

        if (str_contains($contents, "\u{FFFD}")) {
            throw new RuntimeException(
                'The knowledge review pack contains U+FFFD replacement characters; refusing to write corrupted Thai text.',
            );
        }

        return [
            'contents' => $contents,
            'selected' => count($selected),
            'total' => $total,
            'unreviewed' => $unreviewed,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{
     *     id: string,
     *     title: string,
     *     publisher: string,
     *     url: string,
     *     fetched_at: string,
     *     review_status: string,
     *     reviewed_by: ?string,
     *     reviewed_at: ?string,
     *     stale: bool,
     *     headings: list<string>,
     *     sections: list<array{heading: string, body: string}>
     * }
     */
    private function row(array $source): array
    {
        $id = (string) ($source['id'] ?? '');
        $title = (string) ($source['title'] ?? '');
        $publisher = (string) ($source['publisher'] ?? '');
        $text = (string) ($source['text'] ?? '');
        $headings = [];

        foreach ((array) ($source['headings'] ?? []) as $heading) {
            if (! is_string($heading) || trim($heading) === '') {
                continue;
            }

            $headings[] = trim($heading);
        }

        $this->assertExtractedText($id, $title);
        $this->assertExtractedText($id, $publisher);
        $this->assertExtractedText($id, $text);

        foreach ($headings as $heading) {
            $this->assertExtractedText($id, $heading);
        }

        $fetchedAt = (string) ($source['fetched_at'] ?? '');
        $fetchedTimestamp = strtotime($fetchedAt);
        $reviewedBy = trim((string) ($source['reviewed_by'] ?? ''));
        $reviewedAt = trim((string) ($source['reviewed_at'] ?? ''));

        return [
            'id' => $id,
            'title' => $title,
            'publisher' => $publisher,
            'url' => (string) ($source['url'] ?? ''),
            'fetched_at' => $fetchedAt,
            'review_status' => (string) ($source['review_status'] ?? ''),
            'reviewed_by' => $reviewedBy === '' ? null : $reviewedBy,
            'reviewed_at' => $reviewedAt === '' ? null : $reviewedAt,
            'stale' => $fetchedTimestamp !== false
                && $fetchedTimestamp < now()->subDays(self::STALE_DAYS)->getTimestamp(),
            'headings' => $headings,
            'sections' => $this->sections($text, $headings),
        ];
    }

    private function assertExtractedText(string $id, string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException(
                'Knowledge source '.$id.' contains invalid UTF-8; refusing to write the review pack.',
            );
        }

        if (str_contains($text, "\u{FFFD}")) {
            throw new RuntimeException(
                'Knowledge source '.$id.' contains U+FFFD replacement characters; refusing to write the review pack.',
            );
        }
    }

    /**
     * Split the body at the declared headings, in the order the headings occur
     * in the text (the `headings` array is not always in document order). Text
     * before the first heading becomes an introduction section; a source with
     * no located heading has one whole-body section.
     *
     * @param  list<string>  $headings
     * @return list<array{heading: string, body: string}>
     */
    private function sections(string $text, array $headings): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $located = [];

        foreach ($headings as $heading) {
            $position = $this->locateHeading($text, $heading);

            if ($position === null) {
                continue;
            }

            $located[] = [
                'heading' => $heading,
                'start' => $position,
                'length' => mb_strlen($heading),
            ];
        }

        usort($located, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $unique = [];
        $lastStart = null;

        foreach ($located as $section) {
            if ($section['start'] === $lastStart) {
                continue;
            }

            $unique[] = $section;
            $lastStart = $section['start'];
        }

        if ($unique === []) {
            return [['heading' => 'เนื้อหาทั้งหมด', 'body' => $text]];
        }

        $sections = [];

        if ($unique[0]['start'] > 0) {
            $introduction = trim(mb_substr($text, 0, $unique[0]['start']));

            if ($introduction !== '') {
                $sections[] = ['heading' => 'บทนำ', 'body' => $introduction];
            }
        }

        foreach ($unique as $index => $section) {
            $bodyStart = $section['start'] + $section['length'];
            $bodyEnd = $unique[$index + 1]['start'] ?? mb_strlen($text);
            $body = trim(mb_substr($text, $bodyStart, max(0, $bodyEnd - $bodyStart)));

            $sections[] = ['heading' => $section['heading'], 'body' => $body];
        }

        return $sections;
    }

    /**
     * Prefer a heading that stands on its own line. Headings are also mentioned
     * inline in some source bodies ("การป้องกัน" inside a sentence); using the
     * first raw match would cut the document in the middle of a paragraph.
     */
    private function locateHeading(string $text, string $heading): ?int
    {
        $heading = trim($heading);

        if ($heading === '') {
            return null;
        }

        $length = mb_strlen($heading);
        $offset = 0;
        $fallback = null;

        while (($position = mb_strpos($text, $heading, $offset)) !== false) {
            $fallback ??= $position;

            $before = $position === 0 ? "\n" : mb_substr($text, $position - 1, 1);
            $after = mb_substr($text, $position + $length, 8);

            if (($before === "\n" || $before === "\r")
                && preg_match('/^[ \t]*(?:\r\n|\n|\r|$)/u', $after) === 1) {
                return $position;
            }

            $offset = $position + 1;
        }

        return $fallback;
    }

    private function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        if ($body === '') {
            return '_(no body text captured)_';
        }

        if (mb_strlen($body) <= self::EXCERPT_CHARS) {
            return $body;
        }

        // Keep the rendered excerpt at the limit, including the ellipsis: the
        // slice is one character shorter and the marker occupies the last one.
        return rtrim(mb_substr($body, 0, self::EXCERPT_CHARS - 1)).'…';
    }

    /**
     * @param  list<array{
     *     id: string,
     *     title: string,
     *     publisher: string,
     *     url: string,
     *     fetched_at: string,
     *     review_status: string,
     *     reviewed_by: ?string,
     *     reviewed_at: ?string,
     *     stale: bool,
     *     headings: list<string>,
     *     sections: list<array{heading: string, body: string}>
     * }>  $selected
     */
    private function render(array $selected, string $status, int $total, int $unreviewed): string
    {
        $lines = [
            '# Knowledge review pack',
            '',
            '> **Generated document - do not edit this file by hand.** Regeneration overwrites every change.',
            '> Regenerate with `cd backend && php artisan knowledge:review-pack` '
                .'(add `--status=all` to include reviewed sources).',
            '> Generated on '.now()->toDateString().'. Unreviewed sources in the corpus: '
                .$unreviewed.' of '.$total.'.',
            '> Selected for this pack: '.count($selected).' of '.$total
                .' sources (status filter: `'.$status.'`).',
            '',
            '## How to review',
            '',
            '1. Open `/admin/knowledge`.',
            '2. Pick a row and read the linked page against the excerpt below.',
            '3. Enter your name in the review field.',
            '4. Save the review.',
            '',
            'The default pack contains only `unreviewed` sources. Use `--status=all` to include sources that are '
                .'already decided.',
            '',
            '## Summary',
            '',
            '| # | Id | Title | Status | Stale |',
            '|---:|---|---|---|---|',
        ];

        foreach ($selected as $index => $row) {
            $lines[] = '| '.($index + 1)
                .' | `'.$this->cell($row['id']).'`'
                .' | '.$this->cell($row['title'])
                .' | `'.$this->cell($row['review_status']).'`'
                .' | '.($row['stale'] ? 'yes' : 'no')
                .' |';
        }

        if ($selected === []) {
            $lines[] = '';
            $lines[] = 'No sources match the selected status.';
        }

        $lines[] = '';
        $lines[] = '## Sources';

        foreach ($selected as $index => $row) {
            $lines[] = '';
            $lines[] = '### '.($index + 1).'. `'.$this->cell($row['id']).'` - '.$this->cell($row['title']);
            $lines[] = '';
            $lines[] = '| Field | Value |';
            $lines[] = '|---|---|';
            $lines[] = '| Id | `'.$this->cell($row['id']).'` |';
            $lines[] = '| Title | '.$this->cell($row['title']).' |';
            $lines[] = '| Publisher | '.$this->cell($row['publisher']).' |';
            $lines[] = '| URL | <'.$this->cell($row['url']).'> |';
            $lines[] = '| Fetched at | '.$this->cell($row['fetched_at']).' |';
            $lines[] = '| Review status | `'.$this->cell($row['review_status']).'` |';
            $lines[] = '| Reviewed by | '.$this->cell($row['reviewed_by'] ?? '-').' |';
            $lines[] = '| Reviewed at | '.$this->cell($row['reviewed_at'] ?? '-').' |';
            $lines[] = '| Stale | '.($row['stale'] ? 'yes' : 'no').' |';
            $lines[] = '';
            $lines[] = '**Section headings**';
            $lines[] = '';

            if ($row['headings'] === []) {
                $lines[] = '_No section headings were captured for this source._';
            } else {
                foreach ($row['headings'] as $heading) {
                    $lines[] = '- '.$this->cell($heading);
                }
            }

            $lines[] = '';
            $lines[] = '**Section excerpts (first '.self::EXCERPT_CHARS.' characters)**';
            $lines[] = '';

            if ($row['sections'] === []) {
                $lines[] = '_No text body was captured for this source._';
            } else {
                foreach ($row['sections'] as $section) {
                    $lines[] = '**'.$this->cell($section['heading']).'**';
                    $lines[] = '';
                    $lines[] = '> '.$this->excerpt($section['body']);
                    $lines[] = '';
                }
            }

            $lines[] = '**Check this**';
            $lines[] = '';
            $lines[] = '- [ ] อาการที่ระบุถูกต้องหรือไม่ (are the symptoms right?)';
            $lines[] = '- [ ] คำแนะนำยังเป็นปัจจุบันหรือไม่ (is the advice current?)';
            $lines[] = '- [ ] เกณฑ์การไปพบแพทย์ฉุกเฉินถูกต้องหรือไม่ (is the emergency threshold correct?)';
            $lines[] = '- [ ] มีข้อมูลสำคัญที่ผู้ดูแลมักถามแต่ยังขาดอยู่หรือไม่ '
                .'(is anything missing that a caregiver would ask?)';
            $lines[] = '';
            $lines[] = '---';
        }

        return implode("\n", $lines)."\n";
    }

    private function cell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], trim($value));
    }
}
