<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Origin of a studio script. Manual rows are authored in the editor; AI rows
 * are created from the generation pipeline and remain independently editable.
 */
enum ScriptSource: string
{
    use InteractsWithEnum;

    case Manual = 'manual';
    case Ai = 'ai';
}
