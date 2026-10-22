<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A state transition the domain refuses even though the request is well formed
 * (`.ai/07-API-CONTRACT.md §2`: 409 `domain_rule`).
 *
 * Examples: recording a dose against a discontinued plan, or confirming a dose
 * slot twice. The message is deliberately generic and never contains health
 * values (N-02).
 */
final class DomainRuleException extends \RuntimeException implements Responsable
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The requested change is not allowed by the domain rules.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            409,
            'domain_rule',
            'Domain rule violation',
            'ไม่สามารถดําเนินการตามกฎของระบบได้',
        );
    }
}
