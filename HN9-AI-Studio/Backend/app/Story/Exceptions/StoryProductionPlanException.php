<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StoryProductionPlanException extends StoryException
{
    public static function invalidSceneDuration(string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: 'story_production_invalid_duration',
            statusCode: 422,
        );
    }

    public static function invalidUnitLayout(): self
    {
        return new self(
            message: 'The production units do not cover the scene exactly.',
            errorCode: 'story_production_invalid_units',
            statusCode: 422,
        );
    }

    public static function sourceNotReady(string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: 'story_production_source_not_ready',
            statusCode: 422,
        );
    }

    public static function invalidScene(string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: 'story_production_invalid_scene',
            statusCode: 422,
        );
    }

    public static function alreadyPlanned(): self
    {
        return new self(
            message: 'This story already has a production plan. Revise the current plan to use a different story version.',
            errorCode: 'story_production_plan_exists',
            statusCode: 409,
        );
    }

    public static function notCurrent(): self
    {
        return new self(
            message: 'Only the current production plan can be revised.',
            errorCode: 'story_production_plan_not_current',
            statusCode: 409,
        );
    }

    public static function buildFailed(): self
    {
        return new self(
            message: 'The production plan could not be created. Nothing was saved.',
            errorCode: 'story_production_plan_failed',
            statusCode: 500,
        );
    }
}
