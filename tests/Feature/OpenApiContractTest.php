<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Enums\CharacterStatus;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps the written contract and the running application from drifting apart.
 *
 * A specification maintained by hand is worth exactly as much as its accuracy,
 * and accuracy decays the moment someone adds a route in a hurry. These checks
 * turn "the document was right when it was written" into "the document cannot
 * stop being right without the suite saying so".
 *
 * The brief asks for documentation that reflects the endpoints correctly. This
 * is that requirement, enforced rather than promised.
 */
final class OpenApiContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function specification(): array
    {
        return Yaml::parseFile(base_path('openapi.yaml'));
    }

    /**
     * Every operation in the document, as "METHOD /path" with the parameter
     * names flattened so `{character}` and `{id}` compare equal.
     *
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->specification()['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtoupper($method).' '.$this->normalise($path);
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = $method.' '.$this->normalise($this->pathOf($route));
            }
        }

        $operations = array_values(array_unique($operations));
        sort($operations);

        return $operations;
    }

    /**
     * @return list<RegisteredRoute>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RegisteredRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'),
        ));
    }

    private function pathOf(RegisteredRoute $route): string
    {
        return '/'.substr($route->uri(), strlen('api/v1/'));
    }

    private function normalise(string $path): string
    {
        return (string) preg_replace('~\{[^}]+\}~', '{param}', $path);
    }

    public function test_every_endpoint_the_application_serves_is_documented(): void
    {
        $undocumented = array_diff($this->registeredOperations(), $this->documentedOperations());

        $this->assertSame([], array_values($undocumented), sprintf(
            'These endpoints exist but openapi.yaml does not mention them: %s',
            implode(', ', $undocumented),
        ));
    }

    public function test_every_documented_endpoint_actually_exists(): void
    {
        $imaginary = array_diff($this->documentedOperations(), $this->registeredOperations());

        $this->assertSame([], array_values($imaginary), sprintf(
            'openapi.yaml promises these endpoints and the application does not serve them: %s',
            implode(', ', $imaginary),
        ));
    }

    /**
     * The check that matters most. Documenting a protected endpoint as open is
     * worse than not documenting it: it invites a caller to build against an
     * answer they will never receive.
     */
    public function test_what_the_document_calls_protected_is_what_the_router_protects(): void
    {
        $specification = $this->specification();
        $securedByDefault = ($specification['security'] ?? []) !== [];

        foreach ($this->apiRoutes() as $route) {
            $guarded = in_array('auth:api', $route->gatherMiddleware(), true);

            $operation = $specification['paths'][$this->pathOf($route)][strtolower($route->methods()[0])] ?? null;

            $this->assertNotNull($operation, "No documentation for {$route->uri()}.");

            $documentedAsGuarded = array_key_exists('security', $operation)
                ? $operation['security'] !== []
                : $securedByDefault;

            $this->assertSame($guarded, $documentedAsGuarded, sprintf(
                '%s %s is %s by the router but documented as %s.',
                $route->methods()[0],
                $route->uri(),
                $guarded ? 'protected' : 'open',
                $documentedAsGuarded ? 'protected' : 'open',
            ));
        }
    }

    public function test_the_documented_filters_are_the_ones_the_listing_accepts(): void
    {
        $parameters = $this->specification()['paths']['/characters']['get']['parameters'];

        $names = array_map(
            fn (array $parameter): string => $parameter['name'] ?? basename((string) $parameter['$ref']),
            $parameters,
        );

        $this->assertSame(['Page', 'name', 'status', 'species'], $names);
    }

    public function test_the_documented_statuses_are_the_ones_the_domain_knows(): void
    {
        $documented = $this->specification()['components']['schemas']['Character']['properties']['status']['enum'];

        $known = array_map(
            static fn (CharacterStatus $case): string => $case->value,
            CharacterStatus::cases(),
        );

        $this->assertSame($known, $documented);
    }
}
