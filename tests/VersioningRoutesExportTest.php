<?php

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Splicewire\Beam\Versioning\Routing\VersioningRoutes;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;

function exportedVersionRoute(string $name): ?RouteInstance
{
    foreach (app(Router::class)->getRoutes() as $route) {
        if ($route->getName() === $name) {
            return $route;
        }
    }

    return null;
}

it('mounts the versioning triplet at the configured package root with package names', function () {
    expect(config('beam.versioning.api_root'))->toBe('beam/versioning');

    VersioningRoutes::mount(app(Router::class), 'notes', Note::class);

    expect(exportedVersionRoute('beam.versioning.notes.versions.index')?->uri())
        ->toBe('beam/versioning/notes/{id}/versions')
        ->and(exportedVersionRoute('beam.versioning.notes.versions.index')?->methods())
        ->toContain('GET')
        ->and(exportedVersionRoute('beam.versioning.notes.versions.store')?->uri())
        ->toBe('beam/versioning/notes/{id}/versions')
        ->and(exportedVersionRoute('beam.versioning.notes.versions.store')?->methods())
        ->toContain('POST')
        ->and(exportedVersionRoute('beam.versioning.notes.versions.restore')?->uri())
        ->toBe('beam/versioning/notes/{id}/versions/{ref}/restore')
        ->and(exportedVersionRoute('beam.versioning.notes.versions.restore')?->methods())
        ->toContain('POST');
});
