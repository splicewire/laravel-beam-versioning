<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Http\VersionsController;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;

/**
 * The `data:` seam on `Route::recordVersions()` — how a host declares the DTO its generated CLIENT
 * types against, which this package cannot answer for itself.
 *
 * {@see VersionData} deliberately carries no `#[TypeScript]`: it is engine-tier, and its own docblock
 * prescribes that a host re-declare a thin annotated subclass to route the shape through the host's
 * projection pipeline. Client codegen resolves a return type from `#[ResponseFromData]` when nothing
 * more specific is declared, and that attribute can only ever name THIS package's class — so a host
 * without this seam generates hooks referencing a TypeScript namespace its transformer never emits.
 * That is not a cosmetic mismatch: it does not compile. It cost splicewire-app 13 tsc errors.
 *
 * `returns` is written straight onto the route's action rather than through the `->returns()` macro,
 * because that macro is defined HOST-side — a package that called it would break any host lacking it.
 * These tests pin both the wiring and that omitting `data:` stays inert.
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

it('keeps the package DTO un-annotated, which is why the seam exists', function () {
    // If someone "fixes" the tsc error by adding #[TypeScript] here instead, this fails — and it
    // should: annotating an engine-tier DTO emits it under the PACKAGE namespace, not the host's
    // App.Data.*, so the host's own subclass would then double-declare the same shape.
    $attributes = (new ReflectionClass(VersionData::class))->getAttributes();
    $names = array_map(fn ($a) => $a->getName(), $attributes);

    expect($names)->not->toContain(Spatie\TypeScriptTransformer\Attributes\TypeScript::class);

    // And the attribute the fallback reads still names the package class — the fact the seam overrides.
    $index = new ReflectionMethod(VersionsController::class, 'index');
    $responseFrom = $index->getAttributes(Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData::class);

    expect($responseFrom)->not->toBeEmpty()
        ->and($responseFrom[0]->getArguments()[0])->toBe(VersionData::class);
});

/** Stands in for a host's annotated subclass (splicewire-app has Splicewire\Tower\Data\VersionData). */
class HostVersionData extends VersionData {}
