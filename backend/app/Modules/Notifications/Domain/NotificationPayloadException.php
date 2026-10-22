<?php

namespace App\Modules\Notifications\Domain;

use InvalidArgumentException;

/**
 * The caller tried to send a payload that is not a pure set of translation
 * placeholders (N-02).
 *
 * Raised **before** any delivery row is written, so a rejected payload can
 * never leave a partially persisted notification behind.
 */
final class NotificationPayloadException extends InvalidArgumentException {}
