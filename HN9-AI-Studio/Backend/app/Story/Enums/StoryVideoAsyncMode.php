<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryVideoAsyncMode: string
{
    case Sync = 'sync';
    case AsyncPoll = 'async_poll';
    case AsyncWebhook = 'async_webhook';
}
