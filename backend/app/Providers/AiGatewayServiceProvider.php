<?php

namespace App\Providers;

use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Contracts\LlmProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the provider boundary to the router.
 *
 * The router keeps NullProvider as the safe default and only selects the
 * OpenAI-compatible HTTP adapter when `AI_PROVIDER=openai_compatible` and all
 * three connection settings are present. A test may still replace the binding
 * with a capturing provider.
 */
final class AiGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            LlmProvider::class,
            fn (Application $app): LlmProvider => $app->make(ProviderRouter::class),
        );
    }
}
