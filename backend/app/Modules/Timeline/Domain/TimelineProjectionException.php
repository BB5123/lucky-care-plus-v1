<?php

namespace App\Modules\Timeline\Domain;

use RuntimeException;

/**
 * The projector or rebuild could not derive a timeline entry.
 *
 * This is a projection-only failure. Observers catch it so it can never abort
 * the source health write (the timeline is derived data).
 */
class TimelineProjectionException extends RuntimeException {}
