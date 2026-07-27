<?php

namespace Splicewire\Beam\Versioning\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property string $name
 */
class User extends Authenticatable
{
    use HasUuids;

    protected $guarded = [];

    public $timestamps = true;
}
