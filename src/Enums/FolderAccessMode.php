<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Enums;

use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;

/**
 * How a folder is protected (SPEC-0103 §5.2).
 *
 * `inherit` is the stored default: the folder takes whatever its nearest
 * protected ancestor says, and a top-level `inherit` is `shared`.
 */
enum FolderAccessMode: string
{
    use MediaText;

    case Inherit = 'inherit';
    case Shared = 'shared';
    case Members = 'members';
    case Password = 'password';

    /** Members-only and password folders: the two modes whose files are not for the public. */
    public function isRestricted(): bool
    {
        return $this === self::Members || $this === self::Password;
    }

    public function label(): string
    {
        return self::text('media.access.mode.'.$this->value);
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
