# splicewire/laravel-beam-versioning

The **HTTP + DTO seam** over [`rushing/laravel-versioning`](../../rushing/laravel-versioning) — the
middle tier of the three-tier versioning stack (ADR-0092 vendor seam; ADR-0127 seam-home decision):

| Tier | Package | Owns |
| --- | --- | --- |
| mechanism | `rushing/laravel-versioning` | `Versionable`, `Version`, `VersionStore` (snapshot / versions / restore / head) |
| **HTTP + DTO** | **`splicewire/laravel-beam-versioning`** | **the generic versions controller, the `Route::recordVersions()` macro, the `VersionData` / `VersionInputData` projections** |
| UI | `@splicewire/beam-versioning` | the version-history React surface, typed off `VersionData` |

## Add versioning to any Versionable model

```php
use Illuminate\Support\Facades\Route;
use App\Models\Composition;

Route::recordVersions('compositions', Composition::class);
// → GET  compositions/{id}/versions               list, newest-first, HEAD flagged
//   GET  compositions/{id}/versions/{ref}         one version WITH its frozen snapshot (view)
//   POST compositions/{id}/versions               mint a labelled snapshot (advances HEAD)
//   POST compositions/{id}/versions/{ref}/restore roll forward to {ref}
```

That is the whole backend. No hand-rolled controller, no separate version policy.

### Abilities

Authorization delegates to the **host model's existing policy** via the macro's ability map. The
default is `view` / `update` (so migrating an existing hand-rolled controller is a one-liner):

```php
Route::recordVersions('embeds', Embed::class, abilities: [
    'view'   => 'embed.view',
    'mutate' => 'embed.update',
]);
```

`index` and `show` authorize `view`; `store` and `restore` authorize `mutate`. There is no version policy —
version access follows the same rules as the record.

`show` returns the version's raw frozen `snapshot` (whatever the record's `toVersionSnapshot()` froze) to anyone
holding `view`. If a record freezes fields its normal read hides, those fields are readable through `show`: freeze
only what a viewer may see, or map `view` to a stricter ability for that resource.

### Morph-aliased records (`via`)

When the model is not itself the `Versionable` — e.g. an `Embed` that reads through its base thread
morph — name the resolving method:

```php
Route::recordVersions('embeds', Embed::class, abilities: [...], via: 'versionable');
// authorizes on the Embed; snapshots/restores through $embed->versionable()
```

## Restore is roll-FORWARD

`restore` applies the referenced version's frozen input-state onto the working copy, then **mints a
new version** equal to it and advances HEAD. History is never rewound — nothing is lost.

## No migrations

`rushing/laravel-versioning`'s `versions` table + the model's `head_version` pin already exist; the
host app owns that tenant migration. This package adds none.

## Verify

```
composer test   # pest — the seam proven in isolation over a throwaway Versionable fixture
composer pint
```
