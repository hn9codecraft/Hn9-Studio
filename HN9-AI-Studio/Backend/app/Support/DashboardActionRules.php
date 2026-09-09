<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DashboardActionPriority;
use InvalidArgumentException;

/**
 * Deterministic Action Center rules. Priority is status-derived, never scored.
 */
final class DashboardActionRules
{
    public const LIMIT = 50;

    /** @var list<string> */
    public const MODULES = ['project', 'script', 'image', 'video', 'asset'];

    /** @var list<string> */
    public const STATUSES = ['failed', 'pending', 'processing', 'draft'];

    public static function priorityForStatus(string $status): DashboardActionPriority
    {
        return match ($status) {
            'failed' => DashboardActionPriority::High,
            'pending', 'processing' => DashboardActionPriority::Medium,
            'draft' => DashboardActionPriority::Low,
            default => throw new InvalidArgumentException("Status {$status} is not actionable."),
        };
    }

    public static function type(string $status, string $module): string
    {
        return $status.'_'.$module;
    }

    public static function description(string $type): string
    {
        return match ($type) {
            'failed_image' => 'Image request failed.',
            'failed_video' => 'Video request failed.',
            'pending_image' => 'Image request is pending.',
            'pending_video' => 'Video request is pending.',
            'processing_image' => 'Image request is processing.',
            'processing_video' => 'Video request is processing.',
            'draft_project' => 'Project is still a draft.',
            'draft_script' => 'Script is still a draft.',
            'draft_image' => 'Image request is still a draft.',
            'draft_video' => 'Video request is still a draft.',
            'draft_asset' => 'Asset is still a draft.',
            default => 'This item needs attention.',
        };
    }

    public static function actionUrl(string $module, string $itemUuid, string $projectUuid): string
    {
        return match ($module) {
            'project' => '/projects/'.$projectUuid,
            'script' => '/projects/'.$projectUuid.'/scripts/'.$itemUuid,
            'image' => '/projects/'.$projectUuid.'/images/'.$itemUuid,
            'video' => '/projects/'.$projectUuid.'/videos/'.$itemUuid,
            'asset' => '/projects/'.$projectUuid.'/assets/'.$itemUuid,
            default => '/projects/'.$projectUuid,
        };
    }

    public static function priorityRank(DashboardActionPriority $priority): int
    {
        return match ($priority) {
            DashboardActionPriority::High => 0,
            DashboardActionPriority::Medium => 1,
            DashboardActionPriority::Low => 2,
        };
    }
}
