<?php

namespace App\Modules\Notifications\Domain;

use App\Modules\Notifications\NotificationTemplateCatalog;
use InvalidArgumentException;

/**
 * A template key outside the whitelist in {@see NotificationTemplateCatalog}.
 *
 * An unknown key is a programming error, not a user error: templates are code.
 */
final class UnknownNotificationTemplateException extends InvalidArgumentException {}
