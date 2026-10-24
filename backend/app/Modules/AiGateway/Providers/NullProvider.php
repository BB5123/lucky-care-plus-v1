<?php

namespace App\Modules\AiGateway\Providers;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;

/**
 * Deterministic, in-process provider used until the G4 gate passes
 * (ADR-018, AIG-010).
 *
 * It has no constructor dependencies, no network client and no database
 * access: it receives only a scrubbed envelope and returns a fixed Thai
 * paragraph followed by the mandatory disclaimer. That makes the pipeline
 * testable end-to-end without ever sending data anywhere.
 */
final class NullProvider implements LlmProvider
{
    public function complete(PromptEnvelope $envelope): LlmResponse
    {
        $disclaimer = (string) config('ai.disclaimer');

        $paragraph = 'นี่คือบทสรุปตัวอย่างจากระบบทดสอบภายใน (NullProvider) '
            .'ซึ่งสรุปจากข้อมูลที่ได้รับอนุญาตตาม scope และช่วงเวลาที่ร้องขอเท่านั้น '
            .'ระบบนี้ไม่มีการวินิจฉัยโรค ไม่แนะนำการปรับขนาดยา '
            .'และไม่ได้ตีความผลตรวจแทนแพทย์';

        return new LlmResponse(
            content: $paragraph."\n\n".$disclaimer,
            inputTokens: null,
            outputTokens: null,
            model: (string) config('ai.model', 'null-provider-v1'),
            finishReason: 'stop',
        );
    }
}
