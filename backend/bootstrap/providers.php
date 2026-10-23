<?php

use App\Providers\AccountsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\NotificationsServiceProvider;
use App\Providers\TimelineServiceProvider;

return [
    AppServiceProvider::class,
    AccountsServiceProvider::class,
    NotificationsServiceProvider::class,
    TimelineServiceProvider::class,
];
