<?php

namespace Splicewire\Beam\Versioning\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The complete successful version history response body. */
#[TypeScript]
class VersionListResponseData extends Data
{
    public function __construct(
        public bool $success,
        /** @var list<VersionData> */
        #[DataCollectionOf(VersionData::class)]
        public array $data,
    ) {}
}
