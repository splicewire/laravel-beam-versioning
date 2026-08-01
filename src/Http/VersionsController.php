<?php

namespace Splicewire\Beam\Versioning\Http;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Data\VersionInputData;

/**
 * The one generic versions controller behind {@see Route::recordVersions()}
 * — distilled from the composition version controller, which was ~90% generic (only the model lookup +
 * ability names were surface-specific). Three moves over the record-agnostic {@see VersionStore}:
 *
 *  - `index`   list a record's versions newest-first, HEAD flagged (resolved once for the list).
 *  - `store`   mint an explicit snapshot with an optional label; advance HEAD; return the new version.
 *  - `restore` roll FORWARD to `{ref}`: apply the frozen input-state, then mint a NEW version equal to
 *              it — HEAD advances, history is never rewound.
 *
 * Per-route config (target model, ability map, optional versionable-resolution method) rides the
 * route's `defaults` as a plain serializable array, so the macro survives `route:cache`. Authorization
 * delegates to the host model's existing policy via the ability map — there is no separate version
 * policy. The `{success, data}` response envelope mirrors the host app's convention (consumers read
 * `data`); nothing app-local is imported. The endpoints self-document under Scribe via the
 * `#[RequestFromData]` / `#[ResponseFromData]` attributes on each method (the DTOs carry the field
 * descriptions), so every `Route::recordVersions()` consumer gets a documented versions triplet.
 *
 * @group Versioning
 */
class VersionsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private VersionStore $store) {}

    /**
     * List Versions
     *
     * A record's durable version history, newest-first, with the current HEAD flagged.
     */
    #[ResponseFromData(VersionData::class)]
    public function index(Request $request, string $id): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['view'], $model);

        $headId = $this->store->head($versionable)?->id;

        $versions = $this->store->versions($versionable)
            ->map(fn (Version $version): VersionData => VersionData::fromVersion($version, $version->id === $headId))
            ->all();

        return $this->ok($versions);
    }

    /**
     * Save Version
     *
     * Freeze the record's current input-state as a new immutable version and advance HEAD to it.
     * Accepts an optional label.
     */
    #[RequestFromData(VersionInputData::class)]
    #[ResponseFromData(VersionData::class)]
    public function store(Request $request, string $id): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['mutate'], $model);

        $input = VersionInputData::from($request->all());

        $version = $this->store->snapshot($versionable, $input->label);

        return $this->ok(VersionData::fromVersion($version, true), 201);
    }

    /**
     * Restore Version
     *
     * Roll the record forward to `{ref}` (a version uuid or a readable slug like `v2`): apply its frozen
     * input-state onto the working copy, then MINT A NEW version of the result. HEAD advances to that
     * fresh version — nothing is rewritten. Returns the minted version.
     */
    #[RequestFromData(VersionInputData::class)]
    #[ResponseFromData(VersionData::class)]
    public function restore(Request $request, string $id, string $ref): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['mutate'], $model);

        $input = VersionInputData::from($request->all());

        $version = $this->store->version($versionable, $ref);

        if ($version === null) {
            return $this->fail("No version [{$ref}] belongs to this record; cannot restore.", 404);
        }

        $versionable->restoreVersionSnapshot($version->snapshot);

        $new = $this->store->snapshot(
            $versionable,
            $input->label ?? "restore of {$version->readableVersion()}",
        );

        return $this->ok(VersionData::fromVersion($new, true), 201);
    }

    /**
     * Pull the per-route config off the route defaults, find the host model, and resolve the
     * {@see Versionable} to snapshot — either the model itself, or the record its `via` method returns
     * (e.g. an Embed reads through its base thread morph). Authorization always targets the host model.
     *
     * @return array{0: object, 1: Versionable, 2: array{view: string, mutate: string}}
     */
    private function resolve(Request $request, string $id): array
    {
        $config = $request->route()->defaults['_versions'] ?? null;

        if (! is_array($config) || ! isset($config['model'])) {
            throw new RuntimeException(
                'Versions route is missing its _versions config. Register it via Route::recordVersions().'
            );
        }

        /** @var class-string $modelClass */
        $modelClass = $config['model'];
        $model = $modelClass::findOrFail($id);

        $via = $config['via'] ?? null;
        $versionable = $via ? $model->{$via}() : $model;

        if (! $versionable instanceof Versionable) {
            throw new RuntimeException(sprintf(
                'Versions target [%s] is not Versionable. The model (or its "%s" method) must implement %s.',
                is_object($versionable) ? $versionable::class : gettype($versionable),
                (string) $via,
                Versionable::class,
            ));
        }

        return [$model, $versionable, $config['abilities']];
    }

    /**
     * The success envelope, mirroring the host app's `{success, data}` shape (consumers read `data`).
     *
     * @param  mixed  $data
     */
    private function ok($data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'data' => $data], $status);
    }

    /** The failure envelope, mirroring {@see ok()} so the two shapes stay in lockstep. */
    private function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
