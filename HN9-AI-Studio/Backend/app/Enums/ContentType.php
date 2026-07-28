<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The kind of textual generated content. Mirrors the `type` column on the
 * `generated_contents` table.
 */
enum ContentType: string
{
    use InteractsWithEnum;

    case Script = 'script';
    case Caption = 'caption';
    case Blog = 'blog';
    case Seo = 'seo';
    case Subtitle = 'subtitle';

    public function label(): string
    {
        return match ($this) {
            self::Seo => 'SEO',
            default => ucfirst($this->value),
        };
    }
}
