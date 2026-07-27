<?php

namespace Splicewire\Beam\Versioning\Tests\Fixtures;

/**
 * The host model's existing policy — the versions controller authorizes through THIS, never a
 * separate version policy. Anyone may `view`; only the owner may `update` (mutate). This is the
 * gate the seam's ability map (`view`/`update` by default) resolves against.
 */
class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return true;
    }

    public function update(User $user, Note $note): bool
    {
        return $note->owner_id === $user->id;
    }
}
