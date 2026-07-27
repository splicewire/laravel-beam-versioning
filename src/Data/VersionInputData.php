<?php

namespace Splicewire\Beam\Versioning\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Data;

/**
 * The write payload for minting or restoring a version: just an optional human label. Everything else
 * about the snapshot (the frozen tree, the monotonic number, the HEAD advance) is derived server-side.
 */
#[Description('The optional label carried when saving or restoring a version.')]
class VersionInputData extends Data
{
    public function __construct(
        #[Description('An optional human annotation for the minted version.')]
        #[Example('before the rewrite')]
        public ?string $label = null,
    ) {}
}
