<?php

use Illuminate\Support\Facades\Route;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Data\VersionListResponseData;
use Splicewire\Beam\Versioning\Http\VersionsController;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;

/** Legacy bare action compatibility; the actual Beam reader is covered in VersionResponseContractTest. */
it('preserves the legacy host DTO in bare action values', function () {
    Route::recordVersions('typed-notes', Note::class, data: HostVersionData::class);
    Route::getRoutes()->refreshNameLookups();

    $index = Route::getRoutes()->getByName('typed-notes.versions.index');
    $store = Route::getRoutes()->getByName('typed-notes.versions.store');
    $restore = Route::getRoutes()->getByName('typed-notes.versions.restore');

    expect($index->getAction('returns'))->toBe(HostVersionData::class)
        ->and($store->getAction('returns'))->toBe(HostVersionData::class)
        ->and($restore->getAction('returns'))->toBe(HostVersionData::class);
});

it('preserves the legacy bare list cardinality', function () {
    Route::recordVersions('cardinality-notes', Note::class, data: HostVersionData::class);
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('cardinality-notes.versions.index')->getAction('returnsMany'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('cardinality-notes.versions.store')->getAction('returnsMany'))->toBeNull()
        ->and(Route::getRoutes()->getByName('cardinality-notes.versions.restore')->getAction('returnsMany'))->toBeNull();
});

it('omits legacy return action values when data is absent', function () {
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
        (new ReflectionClass(VersionListResponseData::class))->getAttributes(),
    );

    expect($names)->toContain(TypeScript::class);

    // The fallback names the emitted full-body envelope; its data field owns the list cardinality.
    $index = new ReflectionMethod(VersionsController::class, 'index');
    $responseFrom = $index->getAttributes(Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData::class);

    expect($responseFrom)->not->toBeEmpty()
        ->and($responseFrom[0]->getArguments()[0])->toBe(VersionListResponseData::class);
});

/** Stands in for a host's annotated subclass (splicewire-app has Splicewire\Tower\Data\VersionData). */
class HostVersionData extends VersionData {}
