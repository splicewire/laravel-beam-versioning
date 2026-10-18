<?php

namespace Splicewire\Beam\Versioning\Doctor;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * This package's one capability is the `Route::recordVersions()` macro — so the honest doctor
 * check is whether it actually registered (B2-2 enrollment). Package installed but provider never
 * booted means every `recordVersions` call site mounts nothing, silently.
 */
class RecordVersionsMacroAudit implements DoctorAudit
{
    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return Route::hasMacro('recordVersions')
            ? [Finding::pass('recordVersions macro', 'registered — the versions REST triplet is mountable.')]
            : [Finding::fail('recordVersions macro', 'missing: BeamVersioningServiceProvider did not boot, so every Route::recordVersions() call site mounts NOTHING.')];
    }
}
