<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect plain-HTTP requests to HTTPS when FORCE_HTTPS is enabled.
 *
 * The middleware always sits in the global stack; the switch is read per
 * request so an operator can flip it with `config:cache` + a container
 * recreate without rebuilding the image. It also forces generated absolute
 * URLs to https, which is what signed document/export links need behind a
 * TLS terminator.
 *
 * `/up` and `/ready` are exempt from the redirect: the container healthcheck
 * talks to php-fpm over plain HTTP from inside the container, and a 301 would
 * make a healthy instance look unhealthy. Every other path is redirected.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.force_https')) {
            return $next($request);
        }

        URL::forceScheme('https');

        if ($request->isSecure() || in_array($request->path(), ['up', 'ready'], true)) {
            return $next($request);
        }

        return redirect()->secure($request->getRequestUri(), 301);
    }
}
