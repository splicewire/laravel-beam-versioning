<?php

namespace Splicewire\Beam\Versioning\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The complete successful save/restore response body. */
#[TypeScript]
class VersionResponseData extends Data
{
    public function __construct(
        public bool $success,
        public VersionData $data,
    ) {}
}
