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
use Splicewire\Beam\Versioning\Data\VersionListResponseData;
use Splicewire\Beam\Versioning\Data\VersionResponseData;
use Splicewire\Beam\Versioning\Data\VersionSnapshotData;
use Splicewire\Beam\Versioning\Data\VersionSnapshotResponseData;
use Splicewire\Beam\Versioning\VersioningError;

/**
 * The one generic versions controller behind {@see Route::recordVersions()}
 * — distilled from the composition version controller, which was ~90% generic (only the model lookup +
 * ability names were surface-specific). Four moves over the record-agnostic {@see VersionStore}:
 *
 *  - `index`   list a record's versions newest-first, HEAD flagged (resolved once for the list).
 *  - `show`    read ONE version `{ref}` with its frozen snapshot (the list never carries the tree).
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
 * descriptions), so every `Route::recordVersions()` consumer gets a documented set of versions routes.
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
    #[ResponseFromData(VersionListResponseData::class)]
    public function index(Request $request, string $id): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['view'], $model);

        $headId = $this->store->head($versionable)?->id;

        $versions = $this->store->versions($versionable)
            ->map(fn (Version $version): VersionData => VersionData::fromVersion($version, $version->id === $headId))
            ->all();

        return new JsonResponse(new VersionListResponseData(success: true, data: $versions));
    }

    /**
     * Show Version
     *
     * One version of the record, `{ref}` (a version uuid or a readable slug like `v2`), with the frozen input-state
     * it holds. It is read-only and authorized like the list (the route's `view` ability). A ref that names no version
     * of THIS record is the same 404 restore returns.
     */
    #[ResponseFromData(VersionSnapshotResponseData::class)]
    public function show(Request $request, string $id, string $ref): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['view'], $model);

        $version = $this->store->version($versionable, $ref);

        if ($version === null) {
            return $this->versionNotOwned($ref);
        }

        $isHead = $version->id === $this->store->head($versionable)?->id;

        return new JsonResponse(new VersionSnapshotResponseData(success: true, data: VersionSnapshotData::fromVersion($version, $isHead)));
    }

    /**
     * Save Version
     *
     * Freeze the record's current input-state as a new immutable version and advance HEAD to it.
     * Accepts an optional label.
     */
    #[RequestFromData(VersionInputData::class)]
    #[ResponseFromData(VersionResponseData::class, status: 201)]
    public function store(Request $request, string $id): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['mutate'], $model);

        $input = VersionInputData::from($request->all());

        $version = $this->store->snapshot($versionable, $input->label);

        return new JsonResponse(new VersionResponseData(success: true, data: VersionData::fromVersion($version, true)), 201);
    }

    /**
     * Restore Version
     *
     * Roll the record forward to `{ref}` (a version uuid or a readable slug like `v2`): apply its frozen
     * input-state onto the working copy, then MINT A NEW version of the result. HEAD advances to that
     * fresh version — nothing is rewritten. Returns the minted version.
     */
    #[RequestFromData(VersionInputData::class)]
    #[ResponseFromData(VersionResponseData::class, status: 201)]
    public function restore(Request $request, string $id, string $ref): JsonResponse
    {
        [$model, $versionable, $abilities] = $this->resolve($request, $id);
        $this->authorize($abilities['mutate'], $model);

        $input = VersionInputData::from($request->all());

        $version = $this->store->version($versionable, $ref);

        if ($version === null) {
            return $this->versionNotOwned($ref);
        }

        $versionable->restoreVersionSnapshot($version->snapshot);

        $new = $this->store->snapshot(
            $versionable,
            $input->label ?? "restore of {$version->readableVersion()}",
        );

        return new JsonResponse(new VersionResponseData(success: true, data: VersionData::fromVersion($new, true)), 201);
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

    /** The coded 404 for a `{ref}` that names no version of this record; show and restore answer it alike. */
    private function versionNotOwned(string $ref): JsonResponse
    {
        $error = VersioningError::VersionNotOwned;
        $response = $this->fail($error->message(['ref' => $ref]), 404);
        $body = $response->getData(true);
        $body['code'] = $error->code();

        return $response->setData($body);
    }

    /** The existing failure envelope; success bodies are declared by their response DTOs. */
    private function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
