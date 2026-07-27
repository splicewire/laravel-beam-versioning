<?php

use Illuminate\Support\Str;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;
use Splicewire\Beam\Versioning\Tests\Fixtures\User;

/**
 * The whole backend seam, proven in isolation: one `Route::recordVersions('notes', Note::class)` call
 * (see TestCase) lights up index/store/restore over a throwaway Versionable fixture — no app consumer
 * required. Assertions ride the HTTP boundary (response shape, newest-first ordering, HEAD marking,
 * roll-forward restore, and view-vs-mutate ability gating) — never VersionStore internals.
 */
function seedNote(User $owner): Note
{
    return Note::create([
        'title' => 'Original Title',
        'body' => 'Original body',
        'owner_id' => $owner->id,
    ]);
}

it('saves a version and lists it newest-first with HEAD flagged', function () {
    $owner = User::create(['name' => 'Ada']);
    $note = seedNote($owner);

    $save = $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions", ['label' => 'first cut']);
    $save->assertStatus(201);
    expect($save->json('data.version'))->toBe(1)
        ->and($save->json('data.label'))->toBe('first cut')
        ->and($save->json('data.isHead'))->toBeTrue();

    // Mutate, then save version #2.
    $note->fresh()->fill(['title' => 'Changed'])->save();
    $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions")->assertStatus(201);

    $list = $this->actingAs($owner)->getJson("/api/notes/{$note->id}/versions");
    $list->assertStatus(200);
    expect($list->json('data'))->toHaveCount(2)
        ->and($list->json('data.0.version'))->toBe(2)
        ->and($list->json('data.0.isHead'))->toBeTrue()
        ->and($list->json('data.1.version'))->toBe(1)
        ->and($list->json('data.1.isHead'))->toBeFalse();
});

it('restores a version — rolls the working copy back, minting a new HEAD (roll-forward)', function () {
    $owner = User::create(['name' => 'Ada']);
    $note = seedNote($owner);

    // v1 captures the original.
    $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions")->assertStatus(201);

    // Diverge.
    $note->fresh()->fill(['title' => 'Edited Title'])->save();

    // Restore to the readable slug v1.
    $restore = $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions/v1/restore");
    $restore->assertStatus(201);
    // Roll-FORWARD: a new version is minted (v2) and is HEAD; nothing rewound.
    expect($restore->json('data.version'))->toBe(2)
        ->and($restore->json('data.isHead'))->toBeTrue();

    // The working copy is exactly v1's content.
    expect($note->fresh()->title)->toBe('Original Title');
});

it('404s a restore to an unknown version ref', function () {
    $owner = User::create(['name' => 'Ada']);
    $note = seedNote($owner);

    $this->actingAs($owner)
        ->postJson("/api/notes/{$note->id}/versions/v99/restore")
        ->assertStatus(404);
});

it('gates: a non-owner may VIEW the history but not SAVE or RESTORE', function () {
    $owner = User::create(['name' => 'Ada']);
    $stranger = User::create(['name' => 'Grace']);
    $note = seedNote($owner);

    // Owner mints a version to have something to list/restore.
    $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions")->assertStatus(201);

    // Stranger can read (view ability = true for anyone)...
    $this->actingAs($stranger)->getJson("/api/notes/{$note->id}/versions")->assertStatus(200);

    // ...but cannot mutate (update ability = owner only).
    $this->actingAs($stranger)->postJson("/api/notes/{$note->id}/versions")->assertStatus(403);
    $this->actingAs($stranger)->postJson("/api/notes/{$note->id}/versions/v1/restore")->assertStatus(403);
});

it('404s the whole triplet for a record that does not exist', function () {
    $owner = User::create(['name' => 'Ada']);

    $this->actingAs($owner)
        ->getJson('/api/notes/'.Str::uuid().'/versions')
        ->assertStatus(404);
});
