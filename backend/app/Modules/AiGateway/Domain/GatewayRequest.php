<?php

namespace App\Modules\AiGateway\Domain;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * A single gateway attempt. The workspace and profile are resolved server-side
 * from the route; the purpose is a closed vocabulary, never client text
 * (N-01 / AIG-009).
 */
final class GatewayRequest
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly HealthProfile $profile,
        public readonly User $actor,
        public readonly CarbonInterface $periodFrom,
        public readonly CarbonInterface $periodTo,
        public readonly string $purpose = 'summary',
        public readonly string $locale = 'th',
    ) {}
}
