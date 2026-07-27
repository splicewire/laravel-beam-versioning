<?php

namespace Splicewire\Beam\Versioning\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\Versionable;

/**
 * A throwaway Versionable record for the package feature tests — the smallest possible consumer of
 * the seam. Its snapshot is just its own scalar columns; restore fills them back. This is all a
 * surface needs to opt into the versions REST triplet via `Route::recordVersions('notes', Note::class)`.
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property string|null $owner_id
 * @property string|null $head_version
 */
class Note extends Model implements Versionable
{
    use HasUuids;
    use VersionableTrait;

    protected $guarded = [];

    public $timestamps = true;

    public function toVersionSnapshot(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
        ];
    }

    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'title' => $snapshot['title'] ?? null,
            'body' => $snapshot['body'] ?? null,
        ])->save();
    }
}
