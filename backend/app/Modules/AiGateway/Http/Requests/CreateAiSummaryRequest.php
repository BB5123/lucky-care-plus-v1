<?php

namespace App\Modules\AiGateway\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Optional period window for an AI summary.
 *
 * Defaults to the last 30 days and caps the window at 180 days
 * (`.ai/08-AI-GATEWAY.md` section 2 / section 7). The window is validated
 * here, before the gateway ever sees the request, so an invalid window cannot
 * produce a fetch.
 */
final class CreateAiSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication, workspace membership, permission and consent are
        // enforced by the route middleware (`authorize.permission:ai.report.create`).
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->periodFrom();
            $to = $this->periodTo();

            if ($to->lessThan($from)) {
                $validator->errors()->add('period_to', 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น');

                return;
            }

            $maxDays = (int) config('ai.context.max_window_days', 180);

            // Compare whole calendar days: `periodTo()` is end-of-day, so a
            // raw diff would make exactly 180 days look like 180.99 and be
            // rejected even though the contract allows it.
            if ($from->startOfDay()->diffInDays($to->startOfDay()) > $maxDays) {
                $validator->errors()->add('period_to', 'ช่วงเวลาต้องไม่เกิน '.$maxDays.' วัน');
            }
        });
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function window(): array
    {
        return [
            'from' => $this->periodFrom(),
            'to' => $this->periodTo(),
        ];
    }

    private function periodFrom(): CarbonImmutable
    {
        $value = $this->input('period_from');

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->startOfDay();
        }

        $to = $this->input('period_to');

        if (is_string($to) && $to !== '') {
            return CarbonImmutable::parse($to)->subDays($this->defaultWindowDays() - 1)->startOfDay();
        }

        return CarbonImmutable::now()->subDays($this->defaultWindowDays() - 1)->startOfDay();
    }

    private function periodTo(): CarbonImmutable
    {
        $value = $this->input('period_to');

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->endOfDay();
        }

        return CarbonImmutable::now()->endOfDay();
    }

    private function defaultWindowDays(): int
    {
        return max(1, (int) config('ai.context.default_window_days', 30));
    }
}
