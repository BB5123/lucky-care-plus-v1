<?php

namespace Tests\Feature\AiGateway;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeHtmlParser;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeRetriever;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Health knowledge corpus (TASK G4-KB): HTML extraction, source validation,
 * SQLite FTS5 build and Thai retrieval. No network and no PostgreSQL: the
 * corpus is a public reference store, not tenant health data.
 */
class KnowledgeBaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/knowledge-'.Str::lower(Str::random(8)));

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_html_parser_extracts_the_ddc_article_title_and_body(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/knowledge/ddc-disease-44.html'));
        $this->assertIsString($html);

        $article = (new KnowledgeHtmlParser)->parse($html);

        $this->assertStringContainsString('Dengue', $article['title']);
        $this->assertGreaterThan(5000, mb_strlen($article['text']));
        $this->assertStringContainsString('ยุงลาย', $article['text']);
        $this->assertContains('ระบาดวิทยา', $article['headings']);
        $this->assertNotContains('', $article['headings']);
    }

    public function test_builder_and_retriever_answer_a_thai_question_from_the_corpus(): void
    {
        $this->writeSource('ddc-disease-44', [
            'title' => 'ไข้เลือดออก (Dengue)',
            'headings' => ['อาการ', 'การป้องกัน'],
            'text' => str_repeat(
                'ไข้เลือดออกเป็นโรคติดเชื้อไวรัสเดงกีที่มียุงลายเป็นพาหะ อาการสำคัญคือไข้สูงเฉียบพลัน '
                .'ปวดศีรษะ ปวดกล้ามเนื้อ และอาจมีเลือดออกผิดปกติ การป้องกันคือกำจัดแหล่งเพาะพันธุ์ยุงลาย '
                ."และป้องกันไม่ให้ยุงกัด โดยเฉพาะในช่วงที่มีการระบาด\n\n",
                4,
            ),
        ]);

        $this->writeSource('ddc-disease-13', [
            'title' => 'ไข้หวัดใหญ่ (Influenza)',
            'headings' => ['อาการ', 'การป้องกัน'],
            'text' => str_repeat(
                'ไข้หวัดใหญ่เกิดจากเชื้อไวรัส influenza ทำให้มีไข้สูง ปวดเมื่อยกล้ามเนื้อ '
                .'และอาการทางเดินหายใจ การป้องกันทำได้ด้วยการฉีดวัคซีนประจำปี ล้างมือบ่อย ๆ '
                ."และหลีกเลี่ยงสถานที่แออัดเมื่อเจ็บป่วย\n\n",
                4,
            ),
        ]);

        $target = $this->directory.'/kb.sqlite';
        $stats = (new KnowledgeBaseBuilder(300, 50))->build($this->directory, $target);

        $this->assertSame(2, $stats['documents']);
        $this->assertGreaterThan(0, $stats['chunks']);
        $this->assertFileExists($target);

        $retriever = new KnowledgeRetriever($target);

        $dengue = $retriever->search('ไข้เลือดออกมีอาการอย่างไร');

        $this->assertNotEmpty($dengue);
        $this->assertSame('ddc-disease-44', $dengue[0]['document_id']);
        $this->assertSame('ไข้เลือดออก (Dengue)', $dengue[0]['title']);
        $this->assertStringStartsWith('https://ddc.moph.go.th/', $dengue[0]['url']);
        $this->assertSame('unreviewed', $dengue[0]['review_status']);

        $influenza = $retriever->search('หวัดใหญ่ป้องกันอย่างไร');
        $this->assertNotEmpty($influenza);
        $this->assertSame('ddc-disease-13', $influenza[0]['document_id']);

        $this->assertSame([], $retriever->search('คำถามที่ไม่มีความเกี่ยวข้องกับฐานข้อมูล'));
    }

    public function test_validation_rejects_an_unsafe_source(): void
    {
        $builder = new KnowledgeBaseBuilder;

        $sources = [[
            'id' => 'bad-source',
            'title' => 'Bad',
            'publisher' => 'Nobody',
            'url' => 'http://example.com/health',
            'category' => 'unknown',
            'language' => 'th',
            'fetched_at' => '2026-10-24',
            'review_status' => 'unreviewed',
            'text' => str_repeat('short ', 10),
        ], [
            'id' => 'bad-source',
            'title' => 'Duplicate',
            'publisher' => 'Nobody',
            'url' => 'https://ddc.moph.go.th/uploads/secret.pdf',
            'category' => 'disease',
            'language' => 'th',
            'fetched_at' => '2026-10-24',
            'review_status' => 'unreviewed',
            'text' => str_repeat('ข้อความทดสอบ ', 30),
        ]];

        $errors = $builder->validateSources($sources);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(static fn (string $error): bool => str_contains($error, 'https URL')),
        );
        $this->assertTrue(
            collect($errors)->contains(static fn (string $error): bool => str_contains($error, 'duplicate id')),
        );
        $this->assertTrue(
            collect($errors)->contains(static fn (string $error): bool => str_contains($error, 'robots.txt')),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeSource(string $id, array $overrides): void
    {
        $source = array_merge([
            'id' => $id,
            'title' => 'เรื่องทดสอบ',
            'publisher' => 'กรมควบคุมโรค กระทรวงสาธารณสุข',
            'url' => 'https://ddc.moph.go.th/disease_detail.php?d='.substr($id, -2),
            'category' => 'disease',
            'language' => 'th',
            'fetched_at' => '2026-10-24',
            'published_at' => null,
            'review_status' => 'unreviewed',
            'license_note' => 'test',
            'headings' => [],
            'text' => str_repeat('ข้อความทดสอบ ', 30),
        ], $overrides);

        file_put_contents(
            $this->directory.'/'.$id.'.json',
            json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
