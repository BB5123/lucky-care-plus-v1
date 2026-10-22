<?php

namespace App\Modules\Dashboard\Domain;

use RuntimeException;

/**
 * The requested profile is not inside the actor's dashboard scope.
 *
 * Deliberately indistinguishable from "no such profile": the transport layer
 * maps it to `404 out_of_scope` (AP-008), never to 403.
 */
final class DashboardNotFoundException extends RuntimeException {}
