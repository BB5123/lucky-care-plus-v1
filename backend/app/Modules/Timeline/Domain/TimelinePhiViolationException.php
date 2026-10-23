<?php

namespace App\Modules\Timeline\Domain;

/**
 * Projection metadata contained a PHI-shaped key.
 *
 * The rule is normative (DM-007 / N-02): metadata may carry ids, closed-vocab
 * enums and timestamps only. A value, note, title, substance, reaction,
 * location or free-text instruction must never reach the projection, so the
 * projector rejects the whole record rather than silently dropping a key.
 */
final class TimelinePhiViolationException extends TimelineProjectionException {}
