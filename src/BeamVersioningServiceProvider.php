<?php

namespace Splicewire\Beam\Versioning;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Splicewire\Beam\Versioning\Http\VersionsController;

/**
 * Wires the one thing this package adds to a host: the `Route::recordVersions()` macro. The raw
 * versioning mechanism (Versionable, Version, VersionStore) is bound by rushing/laravel-versioning's
 * own provider — this package is strictly the HTTP + DTO tier over it (ADR-0092 vendor seam).
 */
class BeamVersioningServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootRecordVersionsMacro();
    }

    /**
     * Register `Route::recordVersions($resource, $modelClass, abilities: [...], via: ...)`, which
     * registers the versions REST triplet for any Versionable model:
     *
     *   GET  {resource}/{id}/versions               → index   (list, newest-first, HEAD flagged)
     *   POST {resource}/{id}/versions               → store   (mint a labelled snapshot)
     *   POST {resource}/{id}/versions/{ref}/restore → restore (roll forward)
     *
     * Config rides each route's `defaults` as a plain array (no closures) so the macro survives
     * `route:cache`. `abilities` defaults to `view`/`update` — Composition's current contract — so
     * migrating an existing hand-rolled controller is a one-liner. `via` names an optional method on
     * the model that returns the Versionable to snapshot (e.g. `versionable` for a morph-aliased
     * record); omit it when the model is itself Versionable.
     *
     * `data` preserves legacy bare `returns` / `returnsMany` action values for existing callers.
     * Beam's RouteActionMetadataReader reads namespaced `beam.*` keys, so these bare values do not
     * override its response types. The controller's `#[ResponseFromData]` attributes declare the
     * complete response bodies; list cardinality belongs to the envelope's `data` field.
     */
    private function bootRecordVersionsMacro(): void
    {
        if (Route::hasMacro('recordVersions')) {
            return;
        }

        Route::macro('recordVersions', function (
            string $resource,
            string $modelClass,
            array $abilities = [],
            ?string $via = null,
            string $idConstraint = 'uuid',
            ?string $data = null,
        ): void {
            /** @var Router $this */
            $abilities = array_merge(['view' => 'view', 'mutate' => 'update'], $abilities);

            $config = [
                'resource' => $resource,
                'model' => $modelClass,
                'abilities' => $abilities,
                'via' => $via,
            ];

            // The OpenAPI `operationId` each of the three routes declares (api-surface-coherence 36/78).
            // A macro puts ONE controller method on N routes, so a title-derived id collides across every
            // resource that records versions; the mount is the only party that knows the resource, so it
            // declares. Written straight into `action['beam']['operationId']` — the one key beam's
            // `RouteMetadataReader::operationId()` reads and `->beam()->operationId()` writes — rather than
            // through the proxy, because this package does not depend on beam and must stay mountable
            // without it. `embeds` → `embedsVersionsIndex` / `embedsVersionsStore` / `embedsVersionsRestore`.
            $declared = function (RouteInstance $route, string $operation) use ($resource): RouteInstance {
                $route->action['beam']['operationId'] = Str::camel(str_replace('/', ' ', $resource)).'Versions'.ucfirst($operation);

                return $route;
            };

            $withId = function (RouteInstance $route) use ($idConstraint): RouteInstance {
                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            // Preserve legacy bare action values. The full-body controller attributes remain canonical.
            $typed = function (RouteInstance $route, bool $many = false) use ($data): RouteInstance {
                if ($data !== null) {
                    $route->action['returns'] = $data;
                    if ($many) {
                        $route->action['returnsMany'] = true;
                    }
                }

                return $route;
            };

            $declared($typed($withId($this->get("{$resource}/{id}/versions", [VersionsController::class, 'index'])), many: true), 'index')
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.index");

            $declared($typed($withId($this->post("{$resource}/{id}/versions", [VersionsController::class, 'store']))), 'store')
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.store");

            $declared($typed($withId($this->post("{$resource}/{id}/versions/{ref}/restore", [VersionsController::class, 'restore']))), 'restore')
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.restore");
        });
    }
}
