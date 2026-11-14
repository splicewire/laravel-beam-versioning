<?php

namespace Splicewire\Beam\Versioning;

/** Stable local refusal identity, with no dependency on Beam. */
enum VersioningError: string
{
    case VersionNotOwned = 'version_not_owned';

    public function code(): string
    {
        return $this->value;
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::VersionNotOwned => 'No version [:ref] belongs to this record; cannot restore.',
        };
    }

    /** @param array<string, scalar> $params */
    public function message(array $params = [], ?string $locale = null): string
    {
        $prefix = 'beam-versioning::';
        $allocator = 'Splicewire\\Beam\\Localization\\TextNamespaces';
        if (app()->bound($allocator) && app($allocator)->namespaceOf('splicewire/laravel-beam-versioning') !== null) {
            $prefix = app($allocator)->ownerOfPackage('splicewire/laravel-beam-versioning')->keyPrefix();
        }
        $suffix = 'messages.'.match ($this) {
            self::VersionNotOwned => 'version_not_owned',
        };
        $key = $prefix === null ? null : $prefix.$suffix;
        $line = $key === null ? null : trans($key, $params, $locale);
        if (is_string($line) && $line !== $key) {
            return $line;
        }

        $replace = [];
        foreach ($params as $name => $value) {
            $replace[':'.$name] = (string) $value;
        }

        return strtr($this->defaultMessage(), $replace);
    }
}
