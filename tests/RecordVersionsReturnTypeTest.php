<?php

use Illuminate\Support\Facades\Route;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Http\VersionsController;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;

/**
 * The `data:` override on `Route::recordVersions()`, and the reason it is only an override.
 *
 * {@see VersionData} emits its own TypeScript, so codegen's `#[ResponseFromData]` fallback resolves a
 * real type for every host and the common case needs no declaration at all. That was not always true:
 * the attribute ships with `spatie/typescript-transformer`, which this package did not depend on, so
 * hosts were told to re-declare an annotated subclass — but these routes are minted from INSIDE the
 * macro, leaving no call site to point at it. Codegen fell back to the un-emitted package class and
 * generated hooks against a namespace that did not exist: 13 tsc errors in splicewire-app.
 *
 * `data:` survives that fix for the host that genuinely projects a different shape. `returns` is
 * written straight onto the route's action rather than through the `->returns()` macro, because that
 * macro is defined HOST-side — a package calling it would break any host lacking it.
 */
it('writes the host DTO onto each route of the triplet', function () {
    Route::recordVersions('typed-notes', Note::class, data: HostVersionData::class);
    Route::getRoutes()->refreshNameLookups();

    $index = Route::getRoutes()->getByName('typed-notes.versions.index');
    $store = Route::getRoutes()->getByName('typed-notes.versions.store');
    $restore = Route::getRoutes()->getByName('typed-notes.versions.restore');

    expect($index->getAction('returns'))->toBe(HostVersionData::class)
        ->and($store->getAction('returns'))->toBe(HostVersionData::class)
        ->and($restore->getAction('returns'))->toBe(HostVersionData::class);
});

it('marks only the list route as returning many', function () {
    // The cardinality is the half that silently produces a wrong-but-compiling client: a `many` on a
    // write would type a single version as an array. index lists; store and restore each answer with
    // one version.
    Route::recordVersions('cardinality-notes', Note::class, data: HostVersionData::class);
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('cardinality-notes.versions.index')->getAction('returnsMany'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('cardinality-notes.versions.store')->getAction('returnsMany'))->toBeNull()
        ->and(Route::getRoutes()->getByName('cardinality-notes.versions.restore')->getAction('returnsMany'))->toBeNull();
});

it('declares nothing when the host omits the seam', function () {
    // Backwards-compatible by construction: a host that never passes `data:` gets exactly the routes
    // it got before, and codegen falls back to #[ResponseFromData] as it always did.
    Route::recordVersions('untyped-notes', Note::class);
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('untyped-notes.versions.index')->getAction('returns'))->toBeNull()
        ->and(Route::getRoutes()->getByName('untyped-notes.versions.index')->getAction('returnsMany'))->toBeNull();
});

it('emits its own TypeScript so the common case needs no override', function () {
    // The fact that makes `data:` optional. Removing this attribute reopens the bug: the
    // #[ResponseFromData] fallback below would then name a class the host's transformer never emits.
    $names = array_map(
        fn ($a) => $a->getName(),
        (new ReflectionClass(VersionData::class))->getAttributes(),
    );

    expect($names)->toContain(TypeScript::class);

    // The attribute the fallback reads names this same now-emitted class, which is why it resolves.
    $index = new ReflectionMethod(VersionsController::class, 'index');
    $responseFrom = $index->getAttributes(Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData::class);

    expect($responseFrom)->not->toBeEmpty()
        ->and($responseFrom[0]->getArguments()[0])->toBe(VersionData::class);
});

/** Stands in for a host's annotated subclass (splicewire-app has Splicewire\Tower\Data\VersionData). */
class HostVersionData extends VersionData {}
