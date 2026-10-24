<?php

namespace Tests\Feature\Authorization;

use App\Http\Middleware\EnsureAuthorized;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * AP-001..004 — route/permission coverage guard.
 *
 * Every `api/v1/*` route must be one of exactly two things:
 *
 *  1. explicitly allowlisted as public or self-service (with a written
 *     reason, so widening the allowlist is visible in review), or
 *  2. guarded by `EnsureAuthorized` with a permission ID that exists in
 *     `config/authorization.permissions` (the frozen 99-ID catalog).
 *
 * A new endpoint that forgets its authorization middleware therefore fails
 * CI, and so does a route declared with a permission ID that is not in the
 * baseline catalog. The allowlist cannot be used to hide a route that has no
 * authentication either (AP-004).
 *
 * `AP-*` here is a pipeline guard ID; the behavioural API tests are the
 * `AP-T01..AP-T10` cases in `.ai/10-TESTING.md §4.4`.
 */
class RoutePermissionCoverageTest extends TestCase
{
    /**
     * Routes that intentionally have no permission ID, keyed by
     * "<METHOD> <URI>". The five required entries come from the task that
     * introduced this guard; the remaining entries are the authenticated
     * self-service auth surface (logout, OTP, PIN), which is scoped to the
     * caller's own account and must not consume a health permission ID.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [
        'POST api/v1/auth/register' => 'Public: account registration, no workspace exists yet.',
        'POST api/v1/auth/login' => 'Public: credential exchange, no authenticated actor exists yet.',
        'POST api/v1/invitations/{token}/accept' => 'Self-service: the token itself is the authority; the result is a membership, not health data.',
        'GET api/v1/me' => 'Self-service: returns only the authenticated actor and their own memberships.',
        'POST api/v1/workspaces' => 'Self-service: creates a workspace and makes the actor its first admin.',
        'POST api/v1/auth/logout' => 'Self-service: revokes only the caller\'s own token.',
        'POST api/v1/auth/otp/request' => 'Self-service: issues an OTP for the caller\'s own account.',
        'POST api/v1/auth/otp/verify' => 'Self-service: verifies an OTP for the caller\'s own account.',
        'POST api/v1/auth/pin/setup' => 'Self-service: sets the caller\'s own PIN.',
        'POST api/v1/auth/pin/verify' => 'Self-service: verifies the caller\'s own PIN.',
        'GET api/v1/workspaces/{workspace}/dashboard' => 'ADR-010 aggregate: the controller authorizes every card separately through Authorizer (CRM-023); one route-level permission cannot express per-card visibility.',
        'GET api/v1/briefs/{token}' => 'Public: the 256-bit report token is the capability (DB-003); a doctor has no account and the response carries no workspace identity.',
    ];

    /**
     * Allowlisted routes that are intentionally unauthenticated. Every other
     * allowlisted route must still pass through `auth:sanctum`; otherwise the
     * allowlist would become a way to expose data anonymously.
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'GET api/v1/briefs/{token}',
    ];

    /**
     * @return list<array{method: string, uri: string, key: string, route: Route}>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $routes[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'key' => $method.' '.$uri,
                    'route' => $route,
                ];
            }
        }

        return $routes;
    }

    /**
     * Resolve aliases exactly the way the HTTP kernel does, so an alias such as
     * `authorize.permission:workspace.read` is tested as the class it names.
     *
     * @return list<string>
     */
    private function resolvedMiddleware(Route $route): array
    {
        return array_values(array_filter(
            $this->app->make(Router::class)->gatherRouteMiddleware($route),
            static fn ($middleware): bool => is_string($middleware),
        ));
    }

    /**
     * @return list<string>
     */
    private function declaredPermissions(Route $route): array
    {
        $permissions = [];

        foreach ($this->resolvedMiddleware($route) as $middleware) {
            [$class, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);

            if ($class !== EnsureAuthorized::class || $parameters === null) {
                continue;
            }

            foreach (explode(',', $parameters) as $permission) {
                $permission = trim($permission);

                if ($permission !== '') {
                    $permissions[] = $permission;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    private function hasAuthorizingMiddleware(Route $route): bool
    {
        foreach ($this->resolvedMiddleware($route) as $middleware) {
            if (explode(':', $middleware, 2)[0] === EnsureAuthorized::class) {
                return true;
            }
        }

        return false;
    }

    private function hasMiddlewareClass(Route $route, string $class): bool
    {
        foreach ($this->resolvedMiddleware($route) as $middleware) {
            if (explode(':', $middleware, 2)[0] === $class) {
                return true;
            }
        }

        return false;
    }

    public function test_ap_001_every_protected_api_v1_route_has_ensure_authorized_middleware(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->apiRoutes() as $entry) {
            if (array_key_exists($entry['key'], self::ALLOWLIST)) {
                continue;
            }

            $checked++;

            if (! $this->hasAuthorizingMiddleware($entry['route'])) {
                $failures[] = $entry['key'].' — no EnsureAuthorized middleware (or alias) is attached';
            }
        }

        $this->assertGreaterThan(0, $checked, 'No permission-guarded api/v1 routes were found; did routing change?');
        $this->assertSame([], $failures, "AP-001: every protected api/v1 route must declare a permission.\n"
            ."Offending routes:\n- ".implode("\n- ", $failures));
    }

    public function test_ap_002_every_declared_permission_exists_in_the_catalog(): void
    {
        $catalog = config('authorization.permissions', []);
        $failures = [];
        $checked = 0;

        foreach ($this->apiRoutes() as $entry) {
            if (array_key_exists($entry['key'], self::ALLOWLIST)) {
                continue;
            }

            $permissions = $this->declaredPermissions($entry['route']);

            if ($permissions === []) {
                $failures[] = $entry['key'].' — EnsureAuthorized is present but declares no permission ID';

                continue;
            }

            foreach ($permissions as $permission) {
                $checked++;

                if (! in_array($permission, $catalog, true)) {
                    $failures[] = $entry['key'].' — permission "'.$permission.'" is not in config/authorization.permissions';
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No permission IDs were declared by any api/v1 route.');
        $this->assertSame([], $failures, "AP-002: every declared permission must exist in the frozen catalog.\n"
            ."Offending routes:\n- ".implode("\n- ", $failures));
    }

    public function test_ap_003_no_api_v1_route_escapes_both_rules(): void
    {
        $unclassified = [];
        $doubleClassified = [];

        foreach ($this->apiRoutes() as $entry) {
            $allowlisted = array_key_exists($entry['key'], self::ALLOWLIST);
            $guarded = $this->hasAuthorizingMiddleware($entry['route']);

            if (! $allowlisted && ! $guarded) {
                $unclassified[] = $entry['key'];
            }

            if ($allowlisted && $guarded) {
                $doubleClassified[] = $entry['key'];
            }
        }

        $this->assertSame([], $unclassified, "AP-003: these api/v1 routes match neither the allowlist nor the permission guard:\n- "
            .implode("\n- ", $unclassified));
        $this->assertSame([], $doubleClassified, 'AP-003: these api/v1 routes are both allowlisted and permission-guarded; '
            ."remove the allowlist entry so the guard is not bypassed:\n- ".implode("\n- ", $doubleClassified));
    }

    /**
     * TPL-T05 (CR-003): every template and escalation route declares its new
     * permission ID. The general AP-001..004 sweep would also catch a missing
     * guard, but this focused assertion makes sure none of the routes regressed
     * to the borrowed `care_task.*` / `workspace.*` IDs.
     */
    public function test_tpl_t05_template_and_escalation_routes_declare_the_cr_003_permissions(): void
    {
        $expected = [
            'GET api/v1/workspaces/{workspace}/profiles/{profile}/task-templates' => 'care_task.template.read',
            'POST api/v1/workspaces/{workspace}/profiles/{profile}/task-templates' => 'care_task.template.create',
            'GET api/v1/workspaces/{workspace}/profiles/{profile}/task-templates/{template}' => 'care_task.template.read',
            'PATCH api/v1/workspaces/{workspace}/profiles/{profile}/task-templates/{template}' => 'care_task.template.update',
            'POST api/v1/workspaces/{workspace}/profiles/{profile}/task-templates/{template}/deactivate' => 'care_task.template.deactivate',
            'POST api/v1/workspaces/{workspace}/profiles/{profile}/task-templates/{template}/exceptions' => 'care_task.template.update',
            'GET api/v1/workspaces/{workspace}/escalation-policies' => 'escalation_policy.read',
            'PUT api/v1/workspaces/{workspace}/escalation-policies' => 'escalation_policy.update',
            'POST api/v1/workspaces/{workspace}/escalation-policies/defaults' => 'escalation_policy.update',
        ];

        $routes = [];

        foreach ($this->apiRoutes() as $entry) {
            $routes[$entry['key']] = $entry['route'];
        }

        foreach ($expected as $key => $permission) {
            $this->assertArrayHasKey($key, $routes, "TPL-T05: route {$key} is missing");
            $this->assertTrue(
                $this->hasAuthorizingMiddleware($routes[$key]),
                "TPL-T05: {$key} has no EnsureAuthorized middleware",
            );
            $this->assertSame(
                [$permission],
                $this->declaredPermissions($routes[$key]),
                "TPL-T05: {$key} must declare only {$permission}",
            );
        }
    }

    /**
     * PRF-T08 (TASK I): the profile list is its own route and declares the
     * frozen `health_profile.read` permission — not `person.read` (which the
     * dashboard family card uses) and not a new invented ID. The general
     * AP-001..004 sweep would catch a missing guard, but this focused
     * assertion pins the exact permission so the endpoint cannot silently
     * drift to a broader one.
     */
    public function test_prf_t08_profile_list_route_is_permission_guarded(): void
    {
        $routes = [];

        foreach ($this->apiRoutes() as $entry) {
            $routes[$entry['key']] = $entry['route'];
        }

        $key = 'GET api/v1/workspaces/{workspace}/profiles';

        $this->assertArrayHasKey($key, $routes, 'PRF-T08: the profile list route is missing');
        $this->assertTrue(
            $this->hasAuthorizingMiddleware($routes[$key]),
            'PRF-T08: the profile list route has no EnsureAuthorized middleware',
        );
        $this->assertSame(
            ['health_profile.read'],
            $this->declaredPermissions($routes[$key]),
            'PRF-T08: the profile list route must declare only health_profile.read',
        );
    }

    public function test_ap_004_allowlist_entries_are_live_and_keep_authentication(): void
    {
        $routes = [];

        foreach ($this->apiRoutes() as $entry) {
            $routes[$entry['key']] = $entry['route'];
        }

        foreach (self::ALLOWLIST as $key => $reason) {
            $this->assertNotSame('', trim($reason), "AP-004: allowlist entry {$key} needs a written reason");
            $this->assertArrayHasKey(
                $key,
                $routes,
                "AP-004: allowlist entry {$key} no longer matches a route; remove the stale entry",
            );

            if (in_array($key, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $this->assertTrue(
                $this->hasMiddlewareClass($routes[$key], Authenticate::class),
                "AP-004: {$key} is allowlisted but has no authentication middleware",
            );
        }
    }
}
