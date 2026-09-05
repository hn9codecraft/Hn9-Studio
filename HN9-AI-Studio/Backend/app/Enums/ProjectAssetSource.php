<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Where a studio project asset came from.
 *
 * upload / generated exist for future file-storage and provider workflows.
 */
enum ProjectAssetSource: string
{
    use InteractsWithEnum;

    case Manual = 'manual';
    case External = 'external';
    case Upload = 'upload';
    case Generated = 'generated';

    /**
     * Sources a studio user may set while upload/generation are not configured.
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return [
            self::Manual->value,
            self::External->value,
        ];
    }
}
