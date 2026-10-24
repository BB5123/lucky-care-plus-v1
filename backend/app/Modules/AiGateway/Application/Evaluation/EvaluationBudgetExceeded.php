<?php

namespace App\Modules\AiGateway\Application\Evaluation;

use RuntimeException;

/**
 * The live evaluation would exceed its provider-call budget.
 *
 * A dedicated type matters: `InvalidOutputException` and `ProviderException`
 * also extend `RuntimeException`, and the suites must not mistake a budget stop
 * for a model failure (or the reverse).
 */
final class EvaluationBudgetExceeded extends RuntimeException {}
