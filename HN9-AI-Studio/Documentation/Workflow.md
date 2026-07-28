# Workflow — HN9 AI Studio

Describes how a content request flows through the agent fleet from idea to published asset.

## Standard Content Pipeline

1. **Planner** — turns a goal into a production plan.
2. **Research** — gathers facts, trends and references.
3. **Script Writer** — drafts the channel-ready script.
4. **Storyboard** — breaks the script into scenes/shots.
5. **Image Prompt** / **Video Prompt** — author generation prompts.
6. **Voice** — produces voiceover script and TTS settings.
7. **Subtitle** — generates and times captions.
8. **Caption** — writes social copy, hooks and hashtags.
9. **SEO** — optimizes metadata.
10. **Publisher** — formats and schedules for each platform.

```
Goal ─► Planner ─► Research ─► Script ─► Storyboard ─► Prompts ─► Media
                                                        │
                                     Voice / Subtitle / Caption / SEO
                                                        │
                                                    Publisher ─► Output
```

## Automation
- Workflows are implemented in `n8n` and `Make` (see `/Workflows`).
- Each automation is documented in `/Workflows/Automation Docs`.

_This is a placeholder — refine steps as the pipeline is implemented._
