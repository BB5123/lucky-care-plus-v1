<?php

namespace Tests\Feature\Authorization;

use App\Modules\Authz\PermissionCatalog;
use Tests\TestCase;

/**
 * CATALOG-* — drift guard between code and the frozen baseline.
 *
 * The baseline documents are never copied into the config by hand only: the
 * tests parse the markdown at run time, so any mismatch fails CI.
 */
class PermissionCatalogTest extends TestCase
{
    private function repositoryPath(string $relative): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.$relative;
    }

    /**
     * @return list<string>
     */
    private function catalogPermissionIds(): array
    {
        $markdown = file_get_contents($this->repositoryPath('docs/baseline-v1.0.0/PERMISSION_CATALOG.md'));

        $this->assertIsString($markdown, 'PERMISSION_CATALOG.md must be readable');

        preg_match_all('/^\s*`([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)`/m', $markdown, $matches);

        $ids = array_values(array_unique($matches[1]));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function authorizationScopeTokens(): array
    {
        $markdown = file_get_contents($this->repositoryPath('.ai/03-AUTHORIZATION.md'));

        $this->assertIsString($markdown, '03-AUTHORIZATION.md must be readable');

        $start = strpos($markdown, '## 5. Scope model');
        $end = strpos($markdown, '## 6.', (int) $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $section = substr($markdown, $start, $end - $start);

        preg_match_all('/^\|\s*`([a-z_][a-z0-9_.]*)`\s*\|/m', $section, $matches);

        $scopes = array_values(array_unique($matches[1]));
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    /**
     * @return list<string>
     */
    private function authorizationDisabledScopes(): array
    {
        $markdown = file_get_contents($this->repositoryPath('.ai/03-AUTHORIZATION.md'));

        $this->assertIsString($markdown);

        $start = strpos($markdown, '## 5. Scope model');
        $end = strpos($markdown, '## 6.', (int) $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $section = substr($markdown, $start, $end - $start);

        preg_match_all('/^\|\s*`([a-z_][a-z0-9_.]*)`\s*\|[^\n]*ปิดใน MVP/m', $section, $matches);

        $scopes = array_values(array_unique($matches[1]));
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    public function test_catalog_t01_config_contains_exactly_the_101_catalog_permissions(): void
    {
        $expected = $this->catalogPermissionIds();
        $configured = (new PermissionCatalog)->all();

        $this->assertCount(101, $expected, 'The v1.4.0 catalog must contain 101 permission IDs');
        $this->assertCount(101, $configured);

        $sortedConfigured = $configured;
        sort($sortedConfigured, SORT_STRING);

        $this->assertSame($expected, $sortedConfigured);
    }

    public function test_catalog_t02_scopes_and_disabled_scopes_match_authorization_doc(): void
    {
        $catalog = new PermissionCatalog;

        $expectedScopes = $this->authorizationScopeTokens();
        $configuredScopes = $catalog->scopes();
        sort($configuredScopes, SORT_STRING);

        $this->assertSame($expectedScopes, $configuredScopes);

        $expectedDisabled = $this->authorizationDisabledScopes();
        $configuredDisabled = $catalog->disabledScopes();
        sort($configuredDisabled, SORT_STRING);

        $this->assertSame($expectedDisabled, $configuredDisabled);

        foreach ($configuredDisabled as $scope) {
            $this->assertContains($scope, $catalog->scopes(), "{$scope} must be declared before it can be disabled");
            $this->assertFalse($catalog->isScopeEnabled($scope), "{$scope} must be disabled in the MVP");
        }

        foreach (array_diff($catalog->scopes(), $configuredDisabled) as $scope) {
            $this->assertTrue($catalog->isScopeEnabled($scope), "{$scope} must remain enabled");
        }
    }
}
