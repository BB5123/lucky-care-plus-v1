<?php

namespace App\Providers;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Providers\NullProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the provider boundary to the deterministic in-process adapter by
 * default. A later slice may name a real adapter in `config/ai.php`; no
 * outbound client is registered here.
 */
final class AiGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmProvider::class, function (Application $app): LlmProvider {
            $configured = config('ai.provider');

            if (is_string($configured) && $configured !== '' && is_a($configured, LlmProvider::class, true)) {
                return $app->make($configured);
            }

            return $app->make(NullProvider::class);
        });
    }
}
