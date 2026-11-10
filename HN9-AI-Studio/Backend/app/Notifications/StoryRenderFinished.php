<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Story\Models\StoryFinalRender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app notice that a background render finished or failed.
 * Database channel only. No file paths, prompts or secrets.
 */
class StoryRenderFinished extends Notification
{
    use Queueable;

    public function __construct(private StoryFinalRender $render) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->render->status === 'completed'
            ? 'story.render.completed'
            : 'story.render.failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->render->loadMissing('reel.workspace.project');
        $project = $this->render->reel?->workspace?->project;
        $completed = $this->render->status === 'completed';

        return [
            'type' => $completed ? 'story.render.completed' : 'story.render.failed',
            'project_uuid' => $project?->uuid,
            'project_name' => $project?->name,
            'reel_uuid' => $this->render->reel?->uuid,
            'render_uuid' => $this->render->uuid,
            'status' => $this->render->status,
            'message' => $completed
                ? 'Your video is ready.'
                : 'The video could not be built. You can render it again.',
            'link' => is_string($project?->uuid) ? '/studio/'.$project->uuid : null,
        ];
    }
}
