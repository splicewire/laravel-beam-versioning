<?php

namespace Splicewire\Beam\Versioning\Data;

use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable {@see Version} WITH its frozen `snapshot`: what the version held. It is the read-one shape behind
 * `GET {resource}/{id}/versions/{ref}` (particle-editor G3), and it carries every {@see VersionData} field, so a
 * client that already renders a list row renders this one too.
 *
 * It is a sibling of {@see VersionData}, not a subclass and not a change to it. The list stays as small as it was:
 * a history panel needs identity and provenance for every row, and the frozen tree for at most one. `snapshot` is
 * untyped (`array<string, mixed>`) because this package is record-agnostic. It never knows what a host's Versionable
 * freezes, so the host types the tree on its own side.
 */
#[TypeScript]
#[Description('One immutable version of a record together with its frozen input-state snapshot.')]
class VersionSnapshotData extends Data
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        #[Example('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')]
        public string $id,
        #[Description('The per-record monotonic version number (1, 2, 3, …).')]
        #[Example(3)]
        public int $version,
        #[Description('The human-readable handle for this version (default `v{n}`).')]
        #[Example('v3')]
        public string $readable,
        #[Description('The frozen input-state this version holds, exactly as the record froze it. Its shape is the host record\'s own.')]
        public array $snapshot,
        #[Description('An optional human annotation — the only mutable field on a version.')]
        #[Example('before the rewrite')]
        public ?string $label = null,
        #[Description('The id of the user who minted this version, if known.')]
        public ?string $createdBy = null,
        public ?string $createdAt = null,
        #[Description('Whether this version is the record\'s current HEAD pin.')]
        public bool $isHead = false,
    ) {}

    /** Project a {@see Version} with its snapshot. `$isHead` is resolved by the caller, as for {@see VersionData}. */
    public static function fromVersion(Version $version, bool $isHead): static
    {
        return new static(
            id: $version->id,
            version: $version->version,
            readable: $version->readableVersion(),
            snapshot: $version->snapshot ?? [],
            label: $version->label,
            createdBy: $version->created_by,
            createdAt: $version->created_at?->toIso8601String(),
            isHead: $isHead,
        );
    }

    /**
     * `snapshot` is declared an object (`array<string, mixed>` → a record), so an EMPTY one goes on the wire as `{}`, not
     * as PHP's `[]`, which JSON-encodes to an array and would contradict the declared type.
     *
     * @return array<string, mixed>
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        $transformed = parent::transform($transformationContext);
        if (($transformed['snapshot'] ?? null) === []) {
            $transformed['snapshot'] = new \stdClass;
        }

        return $transformed;
    }
}
