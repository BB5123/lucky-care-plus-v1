<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

use RuntimeException;

/**
 * The knowledge corpus is missing, unreadable or not built.
 *
 * The gateway maps this to 503 `knowledge_unavailable` and never calls a
 * provider: an answer without references is worse than no answer (F-09).
 */
final class KnowledgeUnavailableException extends RuntimeException {}
