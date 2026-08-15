<?php

namespace Splicewire\Beam\Versioning;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
     * `data` names the response DTO the CLIENT should type against, and exists because this package
     * cannot answer that question for itself. {@see VersionData} deliberately carries no
     * `#[TypeScript]` — it is engine-tier, and its own docblock prescribes that a host re-declare a
     * thin annotated subclass to route the shape through the host's projection pipeline. Codegen
     * resolves a return type from `#[ResponseFromData]` when nothing more specific is declared, and
     * that attribute can only name the package class — so without this seam a host generates a client
     * referencing a TypeScript namespace its own transformer never emits, which does not compile.
     *
     * Passing `data:` writes the host's annotated subclass onto each route's action as `returns`,
     * which is the FIRST source in the codegen precedence and therefore beats the attribute. Written
     * to the action array directly rather than through the `->returns()` macro: that macro is defined
     * host-side, so a package that called it would break any host without it.
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
                'model' => $modelClass,
                'abilities' => $abilities,
                'via' => $via,
            ];

            $withId = function (RouteInstance $route) use ($idConstraint): RouteInstance {
                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            // The host's client-facing DTO, when declared. `many` only on index — the triplet's two
            // writes each answer with a single version.
            $typed = function (RouteInstance $route, bool $many = false) use ($data): RouteInstance {
                if ($data !== null) {
                    $route->action['returns'] = $data;
                    if ($many) {
                        $route->action['returnsMany'] = true;
                    }
                }

                return $route;
            };

            $typed($withId($this->get("{$resource}/{id}/versions", [VersionsController::class, 'index'])), many: true)
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.index");

            $typed($withId($this->post("{$resource}/{id}/versions", [VersionsController::class, 'store'])))
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.store");

            $typed($withId($this->post("{$resource}/{id}/versions/{ref}/restore", [VersionsController::class, 'restore'])))
                ->defaults('_versions', $config)
                ->name("{$resource}.versions.restore");
        });
    }
}
