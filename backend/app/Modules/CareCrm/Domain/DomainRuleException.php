<?php

namespace App\Modules\CareCrm\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A state transition the domain refuses even though the request is well formed
 * (`.ai/07-API-CONTRACT.md` §2: 409 `domain_rule`).
 *
 * The message is deliberately generic and never carries task text or evidence.
 */
final class DomainRuleException extends \RuntimeException implements Responsable
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The requested care-task transition is not allowed.');
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
