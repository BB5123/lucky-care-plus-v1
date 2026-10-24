<?php

namespace App\Modules\AiGateway\Domain;

/**
 * The only thing a provider adapter ever sees (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * Every data block has already passed scoping and scrubbing. `untrustedBlocks`
 * carry the record content inside `<untrusted_record>` wrappers so a prompt
 * injection inside a user note cannot become an instruction (AIG-004/AIG-005).
 */
final class PromptEnvelope
{
    /**
     * @param  list<array{type: string, content: string}>  $untrustedBlocks
     * @param  array<string, mixed>  $structuredContext  non-record metadata only
     */
    public function __construct(
        public readonly string $system,
        public readonly array $untrustedBlocks,
        public readonly array $structuredContext,
        public readonly string $locale,
        public readonly int $maxTokens,
        public readonly float $temperature,
        public readonly ?PromptImage $image = null,
        // The model this prompt must be sent to. `null` means "the adapter's
        // configured default". It is deliberately not part of `hash()`: the
        // audit hash covers the context that was sent, while the model is
        // recorded in its own `ai_requests` column.
        public readonly ?string $model = null,
    ) {}

    /**
     * Stable representation used for `ai_requests.context_hash`.
     */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->canonicalize($this->toArray()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $envelope = [
            'system' => $this->system,
            'untrusted_blocks' => $this->untrustedBlocks,
            'structured_context' => $this->structuredContext,
            'locale' => $this->locale,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        // Existing text-only envelopes keep their canonical shape (and hash).
        if ($this->image !== null) {
            $envelope['image'] = $this->image->descriptor();
        }

        return $envelope;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
