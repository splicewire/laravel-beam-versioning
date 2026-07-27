<?php

namespace Splicewire\Beam\Versioning\Data;

use Spatie\LaravelData\Data;

/**
 * The write payload for minting or restoring a version: just an optional human label. Everything else
 * about the snapshot (the frozen tree, the monotonic number, the HEAD advance) is derived server-side.
 */
class VersionInputData extends Data
{
    public function __construct(
        public ?string $label = null,
    ) {}
}
