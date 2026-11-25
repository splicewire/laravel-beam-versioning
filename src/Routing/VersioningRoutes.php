<?php

namespace Splicewire\Beam\Versioning\Routing;

use Illuminate\Routing\Router;

/**
 * The host-owned mount for versioning routes.
 *
 * Call this inside the host's authentication and realm/tenant boundary. The package supplies only its configured
 * owner-tier prefix and route-name stem; it does not add middleware or name a host model. The resource and model are
 * supplied by each host, just as they are for Route::recordVersions().
 */
final class VersioningRoutes
{
    /**
     * Mount the generic version routes under the package's configured owner-tier root.
     *
     * @param  array<string, string>  $abilities
     */
    public static function mount(
        Router $router,
        string $resource,
        string $modelClass,
        array $abilities = [],
        ?string $via = null,
        string $idConstraint = 'uuid',
        ?string $data = null,
    ): void {
        $configuredRoot = config('beam.versioning.api_root', 'beam/versioning');
        $root = is_string($configuredRoot) && trim($configuredRoot, '/') !== ''
            ? trim($configuredRoot, '/')
            : 'beam/versioning';

        $router->group(['prefix' => $root, 'as' => 'beam.versioning.'], function () use ($router, $resource, $modelClass, $abilities, $via, $idConstraint, $data): void {
            $router->recordVersions($resource, $modelClass, $abilities, $via, $idConstraint, $data);
        });
    }
}
