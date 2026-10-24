<?php

namespace App\Modules\AiGateway\Domain;

/**
 * One image attached to a prompt (TASK P-3).
 *
 * The bytes are held only for the lifetime of the request and are serialised
 * into the provider call as a `data:` URL. They are never written to the
 * database, a log or a public/signed URL: the provider must not be able to
 * fetch our private storage (`.ai/08-AI-GATEWAY.md` sections 2–3, N-02).
 */
final class PromptImage
{
    public function __construct(
        public readonly string $mimeType,
        public readonly string $bytes,
    ) {}

    /**
     * OpenAI-compatible `image_url` payload. No storage URL is ever used.
     */
    public function dataUrl(): string
    {
        return 'data:'.$this->mimeType.';base64,'.base64_encode($this->bytes);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }

    /**
     * Stable, PHI-free representation for the envelope hash. The raw bytes are
     * deliberately omitted; a digest is enough to make a run reproducible
     * without storing the image itself.
     *
     * @return array{mime_type: string, sha256: string, size_bytes: int}
     */
    public function descriptor(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'sha256' => $this->sha256(),
            'size_bytes' => $this->sizeBytes(),
        ];
    }
}
