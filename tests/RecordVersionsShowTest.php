<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Versioning\Data\VersionSnapshotData;
use Splicewire\Beam\Versioning\Data\VersionSnapshotResponseData;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;
use Splicewire\Beam\Versioning\Tests\Fixtures\User;

/**
 * particle-editor G3: read ONE version's snapshot, at the HTTP boundary. A viewer of the parent reads what a version
 * froze; a non-viewer is refused through the route's `view` ability; a ref that is not this record's is restore's 404;
 * and the list stays exactly as it was (no snapshot on a row).
 */
function noteWithTwoVersions(User $owner): Note
{
    $note = Note::create(['title' => 'Original Title', 'body' => 'Original body', 'owner_id' => $owner->id]);
    test()->actingAs($owner)->postJson("/api/notes/{$note->id}/versions", ['label' => 'first cut'])->assertStatus(201);
    $note->fresh()->fill(['title' => 'Changed'])->save();
    test()->actingAs($owner)->postJson("/api/notes/{$note->id}/versions")->assertStatus(201);

    return $note;
}

it('returns one version with its frozen snapshot to a viewer, by readable slug and by uuid', function () {
    $owner = User::create(['name' => 'Ada']);
    $viewer = User::create(['name' => 'Grace']); // NotePolicy::view is open; update is owner-only.
    $note = noteWithTwoVersions($owner);

    $bySlug = $this->actingAs($viewer)->getJson("/api/notes/{$note->id}/versions/v1-first-cut");
    $bySlug->assertStatus(200);
    expect($bySlug->json('success'))->toBeTrue()
        ->and($bySlug->json('data.version'))->toBe(1)
        ->and($bySlug->json('data.readable'))->toBe('v1-first-cut') // a labelled version's readable handle carries its label slug
        ->and($bySlug->json('data.label'))->toBe('first cut')
        ->and($bySlug->json('data.isHead'))->toBeFalse()
        ->and($bySlug->json('data.snapshot.title'))->toBe('Original Title')
        ->and($bySlug->json('data.snapshot.body'))->toBe('Original body');

    $byId = $this->actingAs($viewer)->getJson("/api/notes/{$note->id}/versions/".$bySlug->json('data.id'));
    $byId->assertStatus(200);
    expect($byId->json('data'))->toBe($bySlug->json('data'));

    $head = $this->actingAs($viewer)->getJson("/api/notes/{$note->id}/versions/v2");
    expect($head->json('data.isHead'))->toBeTrue()
        ->and($head->json('data.snapshot.title'))->toBe('Changed');
});

it('refuses a non-viewer through the route view ability', function () {
    // The same model mounted with an owner-only view ability: the ability map is the macro's own seam.
    Route::middleware('api')->prefix('api')->group(function (): void {
        Route::recordVersions('private-notes', Note::class, abilities: ['view' => 'update']);
    });
    $owner = User::create(['name' => 'Ada']);
    $stranger = User::create(['name' => 'Grace']);
    $note = noteWithTwoVersions($owner);

    $this->actingAs($stranger)->getJson("/api/private-notes/{$note->id}/versions/v1-first-cut")->assertStatus(403);
    $this->actingAs($owner)->getJson("/api/private-notes/{$note->id}/versions/v1-first-cut")->assertStatus(200);
});

it('answers restore\'s coded 404 for a ref that is not one of this record\'s versions', function () {
    $owner = User::create(['name' => 'Ada']);
    $note = noteWithTwoVersions($owner);
    $other = noteWithTwoVersions($owner);
    $foreignId = $this->actingAs($owner)->getJson("/api/notes/{$other->id}/versions/v1-first-cut")->json('data.id');
    expect($foreignId)->toBeString(); // an empty ref would hit the list route and prove nothing

    foreach (['v99', $foreignId] as $ref) {
        $missing = $this->actingAs($owner)->getJson("/api/notes/{$note->id}/versions/{$ref}");
        $missing->assertStatus(404);
        expect($missing->json('success'))->toBeFalse()
            ->and($missing->json('code'))->not->toBeNull()
            ->and($missing->json('data'))->toBeNull();
    }
});

it('puts an empty snapshot on the wire as an object, as its declared type says', function () {
    $empty = new VersionSnapshotData(id: 'version-1', version: 1, readable: 'v1', snapshot: []);
    expect(json_encode(new VersionSnapshotResponseData(success: true, data: $empty), JSON_THROW_ON_ERROR))
        ->toContain('"snapshot":{}');
});

it('mounts the read as GET notes.versions.show', function () {
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'notes.versions.show');
    expect($route?->uri())->toBe('api/notes/{id}/versions/{ref}')
        ->and($route?->methods())->toContain('GET');
});

it('leaves the list rows exactly as they were, without a snapshot (green at base too: the guard)', function () {
    $owner = User::create(['name' => 'Ada']);
    $note = noteWithTwoVersions($owner);

    $list = $this->actingAs($owner)->getJson("/api/notes/{$note->id}/versions");
    $list->assertStatus(200);
    expect(array_keys($list->json('data.0')))
        ->toBe(['id', 'version', 'readable', 'label', 'createdBy', 'createdAt', 'isHead']);
});
