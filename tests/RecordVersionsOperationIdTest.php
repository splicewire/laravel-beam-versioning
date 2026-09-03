<?php

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;

/**
 * The triplet DECLARES its OpenAPI `operationId` (api-surface-coherence 36/78). One controller method on
 * three routes per resource, mounted for N resources, is exactly the construct that gives every
 * `index()` in the estate one title-derived id — `listVersions` ×N — and drops all but one from a
 * generated SDK. The mount is the only party that knows the resource, so it writes the id the route
 * carries, into the one key beam's reader consults; this package stays beam-free, so the key is asserted
 * by name rather than through beam's typed proxy.
 */
/**
 * By name, walking the collection: `RouteCollection::getByName()` reads a lookup table that a name set
 * AFTER `add()` (which is how every `->name()` chain works) does not reach until `refreshNameLookups()`,
 * and a null there reads exactly like "the macro wrote nothing".
 */
function versionsRoute(string $name): ?RouteInstance
{
    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === $name) {
            return $route;
        }
    }

    return null;
}

it('declares a resource-keyed operationId on each of the three routes', function () {
    $ids = collect(['notes.versions.index', 'notes.versions.store', 'notes.versions.restore'])
        ->mapWithKeys(fn (string $name) => [$name => versionsRoute($name)?->getAction('beam')['operationId'] ?? null]);

    expect($ids->all())->toBe([
        'notes.versions.index' => 'notesVersionsIndex',
        'notes.versions.store' => 'notesVersionsStore',
        'notes.versions.restore' => 'notesVersionsRestore',
    ]);
});

it('stamps the resource into _versions so a reader can attribute the route without re-parsing the uri', function () {
    expect(versionsRoute('notes.versions.index')?->defaults['_versions']['resource'])->toBe('notes');
});

it('camel-cases a nested resource path rather than dropping its prefix', function () {
    Route::recordVersions('splice/compositions', Note::class);

    expect(versionsRoute('splice/compositions.versions.index')?->getAction('beam')['operationId'])
        ->toBe('spliceCompositionsVersionsIndex');
});
