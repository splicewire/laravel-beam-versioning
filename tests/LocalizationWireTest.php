<?php

namespace Splicewire\Beam\Versioning\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;
use Splicewire\Beam\Versioning\Tests\Fixtures\User;

/** Real Route::recordVersions journeys using the unchanged standalone TestCase and original model/policy. */
final class LocalizationWireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->app->setLocale('en');
        $this->app->make('translator')->getLoader()->addPath(__DIR__.'/Fixtures/lang');

        Route::post('/__versions/control/{ref}', fn (string $ref) => new JsonResponse([
            'success' => false,
            'message' => "No version [{$ref}] belongs to this record; cannot restore.",
            'data' => null,
        ], 404));
    }

    public function test_missing_and_foreign_versions_keep_the_exact_english_envelope_and_add_only_code(): void
    {
        $owner = User::create(['name' => 'Ada']);
        $note = $this->note($owner);
        $other = $this->note($owner);
        $saved = $this->actingAs($owner)->postJson("/api/notes/{$other->id}/versions", ['label' => 'foreign authored label']);
        $saved->assertStatus(201);
        $foreignId = $saved->json('data.id');
        $this->assertIsString($foreignId);
        $this->assertTrue(Str::isUuid($foreignId));
        $before = DB::table('versions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertCount(1, $before);

        foreach (['v99', 'unknown-Δ', $foreignId] as $ref) {
            $control = $this->postJson('/__versions/control/'.rawurlencode($ref));
            $response = $this->postJson("/api/notes/{$note->id}/versions/".rawurlencode($ref).'/restore');
            $control->assertStatus(404);
            $response->assertStatus(404);
            $expected = $control->json();
            $expected['code'] = 'version_not_owned';
            $this->assertSame($expected, $response->json(), $ref);
            $this->assertSame(substr($control->getContent(), 0, -1).',"code":"version_not_owned"}', $response->getContent());
            $this->assertSame($control->baseResponse->getEncodingOptions(), $response->baseResponse->getEncodingOptions());
            foreach (['Content-Type', 'Cache-Control'] as $header) {
                $this->assertSame($control->headers->get($header), $response->headers->get($header));
            }
        }
        $this->assertSame($before, DB::table('versions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertNull($note->fresh()->head_version);
    }

    public function test_pseudo_refusal_marks_the_whole_sentence_and_keeps_raw_ref_and_json_shape(): void
    {
        $owner = User::create(['name' => 'Ada']);
        $note = $this->note($owner);
        $this->app->setLocale('en-XA');
        $ref = 'raw-Δ';
        $response = $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions/".rawurlencode($ref).'/restore');
        $response->assertStatus(404);
        $this->assertSame([
            'success' => false,
            'message' => '[!! No version ['.$ref.'] belongs to this record; cannot restore. !!]',
            'data' => null,
            'code' => 'version_not_owned',
        ], $response->json());
        $this->assertSame(0, Version::query()->count());
    }

    public function test_authorization_and_missing_model_still_precede_the_owned_missing_version_refusal(): void
    {
        $owner = User::create(['name' => 'Ada']);
        $stranger = User::create(['name' => 'Grace']);
        $note = $this->note($owner);
        $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions")->assertStatus(201);
        $before = DB::table('versions')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertCount(1, $before);

        $this->app->setLocale('en-XA');
        $denied = $this->actingAs($stranger)->postJson("/api/notes/{$note->id}/versions/v99/restore");
        $denied->assertStatus(403);
        $this->assertArrayNotHasKey('code', $denied->json());
        $this->assertNotSame('version_not_owned', $denied->json('code'));
        $missing = $this->actingAs($owner)->postJson('/api/notes/'.Str::uuid().'/versions/v99/restore');
        $missing->assertStatus(404);
        $this->assertArrayNotHasKey('code', $missing->json());
        $this->assertSame($before, DB::table('versions')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_successful_pseudo_restore_preserves_stored_snapshot_and_default_and_authored_labels(): void
    {
        $owner = User::create(['name' => 'Ada']);
        $note = $this->note($owner);
        $authored = 'Authored Δ :ref / untouched';
        $saved = $this->actingAs($owner)->postJson("/api/notes/{$note->id}/versions", ['label' => $authored]);
        $saved->assertStatus(201);
        $id = $saved->json('data.id');
        $this->assertIsString($id);
        $this->assertTrue(Str::isUuid($id));
        $readable = $saved->json('data.readable');
        $this->assertIsString($readable);
        $this->assertSame('v1-'.Str::slug($authored), $readable);
        $this->assertNotSame('v1', $readable);
        $this->assertSame($id, app(\Rushing\Versioning\Contracts\VersionStore::class)->version($note->fresh(), $readable)?->id);
        $this->assertSame(1, Version::query()->count());
        $first = (array) DB::table('versions')->where('id', $id)->first();
        $this->assertSame($authored, $first['label']);
        $snapshot = $first['snapshot'];
        $this->assertIsString($snapshot);
        $note->fresh()->fill(['title' => 'Later authored title', 'body' => 'Later body'])->save();

        $this->app->setLocale('en-XA');
        $restored = $this->postJson("/api/notes/{$note->id}/versions/".rawurlencode($readable).'/restore');
        $restored->assertStatus(201);
        $this->assertTrue($restored->json('success'));
        $this->assertSame(2, $restored->json('data.version'));
        $this->assertTrue($restored->json('data.isHead'));
        $this->assertSame('restore of '.$readable, $restored->json('data.label'));
        $this->assertArrayNotHasKey('code', $restored->json());
        $second = (array) DB::table('versions')->where('id', $restored->json('data.id'))->first();
        $this->assertSame('restore of '.$readable, $second['label']);
        $this->assertSame($snapshot, $second['snapshot']);
        $this->assertSame('Original Δ title', $note->fresh()->title);
        $this->assertSame('Original body :ref / raw', $note->fresh()->body);
        $this->assertSame($first, (array) DB::table('versions')->where('id', $id)->first());

        $explicit = 'Reader-authored [!! literal !!] Δ';
        $third = $this->postJson("/api/notes/{$note->id}/versions/".rawurlencode($readable).'/restore', ['label' => $explicit]);
        $third->assertStatus(201);
        $this->assertSame(3, $third->json('data.version'));
        $this->assertSame($explicit, $third->json('data.label'));
        $stored = (array) DB::table('versions')->where('id', $third->json('data.id'))->first();
        $this->assertSame($explicit, $stored['label']);
        $this->assertSame($snapshot, $stored['snapshot']);
        $this->assertSame(3, Version::query()->count());
    }

    public function test_the_standalone_native_catalog_resolves_all_local_cases_and_parameters(): void
    {
        $class = 'Splicewire\\Beam\\Versioning\\VersioningError';
        $this->assertTrue(enum_exists($class), 'The local Versioning catalog must exist without Beam.');
        $this->assertFalse($this->app->bound('Splicewire\\Beam\\Localization\\TextNamespaces'));
        $hint = $this->app->make('translator')->getLoader()->namespaces()['beam-versioning'] ?? null;
        $this->assertIsString($hint);
        $this->assertSame(realpath(__DIR__.'/../lang'), realpath($hint));
        $cases = $class::cases();
        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame('version_not_owned', $case->code());
            $line = 'No version [raw-Δ] belongs to this record; cannot restore.';
            $this->assertSame($line, $case->message(['ref' => 'raw-Δ'], 'en'));
            $this->assertSame('[!! '.$line.' !!]', $case->message(['ref' => 'raw-Δ'], 'en-XA'));
        }
    }

    private function note(User $owner): Note
    {
        return Note::create([
            'owner_id' => $owner->id,
            'title' => 'Original Δ title',
            'body' => 'Original body :ref / raw',
        ]);
    }
}
