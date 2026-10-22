<?php

namespace App\Providers;

use App\Modules\Accounts\Contracts\OtpSender;
use App\Modules\Accounts\Senders\LogOtpSender;
use Illuminate\Support\ServiceProvider;

class AccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tests and other environments can override this binding with a fake.
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }
}
