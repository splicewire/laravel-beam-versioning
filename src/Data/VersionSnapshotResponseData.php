<?php

namespace Splicewire\Beam\Versioning\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The complete successful read-one-version response body: one version with its frozen snapshot. */
#[TypeScript]
class VersionSnapshotResponseData extends Data
{
    public function __construct(
        public bool $success,
        public VersionSnapshotData $data,
    ) {}
}
