<?php

namespace Splicewire\Beam\Versioning\Data;

use Rushing\Versioning\Models\Version;
use Spatie\LaravelData\Data;

/**
 * The record-agnostic API projection of one immutable {@see Version} — a frozen input-state snapshot
 * with a per-record monotonic number and a human-readable handle. Deliberately omits the full
 * `snapshot` payload: the list/restore affordance needs identity + provenance, not the frozen tree.
 *
 * This is the shared shape every versions surface projects (compositions, embeds, …). An engine-tier
 * host may re-declare a thin subclass carrying `#[TypeScript]` / `#[Description]` annotations to route
 * it through the app's `_resources` / Frame projection pipeline (see the consuming app's
 * `App\Data\VersionData`); the mechanics live here, the projection metadata stays app-side.
 */
class VersionData extends Data
{
    public function __construct(
        public string $id,
        public int $version,
        public string $readable,
        public ?string $label = null,
        public ?string $createdBy = null,
        public ?string $createdAt = null,
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
