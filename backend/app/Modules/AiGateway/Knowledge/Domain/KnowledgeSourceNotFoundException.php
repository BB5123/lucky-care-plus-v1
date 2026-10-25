<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

use RuntimeException;

/**
 * The requested knowledge source does not exist.
 *
 * The API maps this to a neutral 404 so a slug cannot be used to enumerate
 * corpus files.
 */
final class KnowledgeSourceNotFoundException extends RuntimeException {}
