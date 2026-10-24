<?php

namespace App\Modules\AiGateway\Domain;

use RuntimeException;

/**
 * A provider call failed in a way the gateway must expose as metadata only
 * (`.ai/08-AI-GATEWAY.md` section 7).
 *
 * The exception deliberately carries no response body, prompt text or health
 * value: `errorCode` is the value written to `ai_requests.error_code`, and
 * `userMessage` is the neutral Thai copy the controller may show. The original
 * transport error is never chained, because a Guzzle exception can contain a
 * request URI or body (N-02).
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly string $userMessage,
        public readonly bool $countsAsFailure,
    ) {
        parent::__construct($errorCode);
    }

    public static function timeout(): self
    {
        return new self(
            'provider_timeout',
            503,
            self::message('provider_timeout', 'ระบบ AI ไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่ภายหลัง'),
            true,
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'provider_unavailable',
            503,
            self::message('provider_unavailable', 'ระบบ AI ไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่ภายหลัง'),
            true,
        );
    }

    public static function rejected(): self
    {
        return new self(
            'provider_rejected',
            503,
            self::message('provider_rejected', 'ระบบ AI ไม่สามารถประมวลผลคำขอนี้ได้ กรุณาลองใหม่ภายหลัง'),
            false,
        );
    }

    public static function invalidResponse(): self
    {
        return new self(
            'provider_invalid_response',
            503,
            self::message('provider_invalid_response', 'ระบบ AI ไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่ภายหลัง'),
            true,
        );
    }

    public static function refused(): self
    {
        return new self(
            'provider_refused',
            503,
            self::message('provider_refused', 'ระบบ AI ไม่สามารถให้คำตอบสำหรับคำขอนี้ได้ กรุณาปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุข'),
            false,
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            'provider_not_configured',
            503,
            self::message('provider_not_configured', 'ระบบ AI ยังไม่พร้อมใช้งาน'),
            false,
        );
    }

    public static function circuitOpen(): self
    {
        return new self(
            'ai_provider_unavailable',
            503,
            self::message('ai_provider_unavailable', 'ระบบ AI ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง หรือดูสรุปแบบไม่ใช้ AI'),
            false,
        );
    }

    private static function message(string $code, string $fallback): string
    {
        $configured = config('ai.provider_messages.'.$code);

        return is_string($configured) && trim($configured) !== '' ? $configured : $fallback;
    }
}
