<?php

use App\Providers\AccountsServiceProvider;
use App\Providers\AiGatewayServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DocumentsServiceProvider;
use App\Providers\NotificationsServiceProvider;
use App\Providers\TimelineServiceProvider;

return [
    AppServiceProvider::class,
    AccountsServiceProvider::class,
    AiGatewayServiceProvider::class,
    DocumentsServiceProvider::class,
    NotificationsServiceProvider::class,
    TimelineServiceProvider::class,
];
