<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Result of scanning a prompt envelope for instruction-override attempts.
 *
 * `envelope` is always safe to send: neutralised blocks keep their data but
 * lose their imperative text and carry an explicit marker. `matches` and
 * `neutralisedTypes` exist so the gateway can record the event and the
 * evaluation can prove the payload did not survive.
 */
final class InjectionVerdict
{
    /**
     * @param  list<string>  $matches
     * @param  list<string>  $neutralisedTypes
     */
    public function __construct(
        public readonly PromptEnvelope $envelope,
        public readonly array $matches = [],
        public readonly array $neutralisedTypes = [],
    ) {}

    public function detected(): bool
    {
        return $this->matches !== [];
    }
}
