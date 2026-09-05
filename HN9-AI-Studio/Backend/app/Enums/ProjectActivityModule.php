<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\Video;
use Illuminate\Database\Eloquent\Model;

/**
 * Studio modules whose real activity_logs entries appear in Activity Studio.
 * Pipeline/user/provider actions are excluded.
 */
enum ProjectActivityModule: string
{
    use InteractsWithEnum;

    case Project = 'project';
    case Script = 'script';
    case Image = 'image';
    case Video = 'video';
    case Asset = 'asset';

    public function actionPrefix(): string
    {
        return match ($this) {
            self::Asset => 'project_asset.',
            default => $this->value.'.',
        };
    }

    /**
     * Whether an activity action belongs to this studio module.
     * Project must not swallow project_asset.* (same "project." prefix).
     */
    public function matchesAction(string $action): bool
    {
        if ($this === self::Project) {
            return str_starts_with($action, 'project.') && ! str_starts_with($action, 'project_asset.');
        }

        return str_starts_with($action, $this->actionPrefix());
    }

    /**
     * @return class-string<Model>
     */
    public function subjectClass(): string
    {
        return match ($this) {
            self::Project => Project::class,
            self::Script => Script::class,
            self::Image => Image::class,
            self::Video => Video::class,
            self::Asset => ProjectAsset::class,
        };
    }

    public static function fromAction(string $action): ?self
    {
        foreach ([self::Asset, self::Script, self::Image, self::Video, self::Project] as $module) {
            if ($module->matchesAction($action)) {
                return $module;
            }
        }

        return null;
    }

    public static function fromSubject(?Model $subject): ?self
    {
        return match (true) {
            $subject instanceof Project => self::Project,
            $subject instanceof Script => self::Script,
            $subject instanceof Image => self::Image,
            $subject instanceof Video => self::Video,
            $subject instanceof ProjectAsset => self::Asset,
            default => null,
        };
    }
}
