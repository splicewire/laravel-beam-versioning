<?php

namespace Splicewire\Beam\Versioning\Data;

use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The record-agnostic API projection of one immutable {@see Version} — a frozen input-state snapshot
 * with a per-record monotonic number and a human-readable handle. Deliberately omits the full
 * `snapshot` payload: the list/restore affordance needs identity + provenance, not the frozen tree.
 *
 * This is the shared shape every versions surface projects (compositions, embeds, …), and it emits its
 * OWN TypeScript — a DTO owns its wire contract, so a host gets the type by installing this package
 * rather than by re-declaring anything.
 *
 * It did not always. The `#[TypeScript]` attribute ships with `spatie/typescript-transformer`, which
 * every other beam-* package inherits transitively through `splicewire/laravel-beam` — and this
 * package deliberately does not depend on beam, so it could not name the attribute and the host was
 * asked to re-declare an annotated subclass instead. That cost a real bug: `Route::recordVersions()`
 * mints its routes from inside this package, so a host had no call site to point at its own subclass,
 * and client codegen fell back to `#[ResponseFromData]` — which names THIS class — emitting hooks
 * against a namespace the host's transformer never emitted. It did not compile. The dependency is now
 * declared honestly (it is small, framework-agnostic, and this package already requires
 * `spatie/laravel-data`), which is cheaper than every host paying for the indirection.
 */
#[TypeScript]
#[Description('One immutable version of a record: a frozen input-state snapshot with a monotonic number and a human-readable handle.')]
class VersionData extends Data
{
    public function __construct(
        #[Example('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')]
        public string $id,
        #[Description('The per-record monotonic version number (1, 2, 3, …).')]
        #[Example(3)]
        public int $version,
        #[Description('The human-readable handle for this version (default `v{n}`).')]
        #[Example('v3')]
        public string $readable,
        #[Description('An optional human annotation — the only mutable field on a version.')]
        #[Example('before the rewrite')]
        public ?string $label = null,
        #[Description('The id of the user who minted this version, if known.')]
        public ?string $createdBy = null,
        public ?string $createdAt = null,
        #[Description('Whether this version is the record\'s current HEAD pin.')]
        public bool $isHead = false,
    ) {}

    /**
     * Project a {@see Version}, resolving its readable handle and HEAD status. `$isHead` is passed in
     * (the caller resolves HEAD once for the whole list rather than per row).
     */
    public static function fromVersion(Version $version, bool $isHead): static
    {
        return new static(
            id: $version->id,
            version: $version->version,
            readable: $version->readableVersion(),
            label: $version->label,
            createdBy: $version->created_by,
            createdAt: $version->created_at?->toIso8601String(),
            isHead: $isHead,
        );
    }
}
