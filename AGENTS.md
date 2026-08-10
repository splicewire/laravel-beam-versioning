> You are in **splicewire/laravel-beam-versioning** — the HTTP + DTO seam over `rushing/laravel-versioning`, the middle tier of the three-tier versioning stack.

A Laravel package providing a generic versions controller (list / save / restore, roll-forward), a
`Route::recordVersions()` macro that registers the REST triplet for any `Versionable` model, and
the record-agnostic `VersionData` / `VersionInputData` projections. Authorization delegates to the
host model's existing policy via the macro's ability map; the raw `VersionStore` mechanism stays
pure in `rushing/laravel-versioning`.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
