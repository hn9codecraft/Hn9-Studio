# HN9 AI Studio roadmap

This is the product list after sprints M12 through M20. Setup and day-to-day use are in [README.md](README.md). The click-through list is in [docs/QA-CHECKLIST.md](docs/QA-CHECKLIST.md).

## Done

| Sprint | What shipped |
| --- | --- |
| M12 | CORS allow-list, Sanctum token lifetime, security pass |
| M13 | Cost estimate, project budget, spend summary |
| M14 | In-app keys for OpenAI, Gemini, ElevenLabs, Runway, Luma, and Seedance |
| M15 | Quick start through Ready for review, with no paid media |
| M16 | Match scene length to the voiceover; SRT, VTT, and subtitle burn-in |
| M17 | Character lock and prompt preview |
| M18 | Failed-unit retry, bulk approve, progress bar |
| M19 | Version restore, voice language, background render and the bell |
| M20 | This roadmap, the README, and the QA checklist |

Also done in M20, with tests:

- An approved export zip includes the latest subtitle `.srt` and `.vtt` when a track exists. The zip uses the names `subtitles/subtitles.srt` and `subtitles/subtitles.vtt`. Disk paths stay out of the manifest.
- The spend summary counts generated character and style reference pictures. Uploaded pictures are not counted. When `STORY_COST_IMAGE_PER_IMAGE` is empty, those pictures are "not priced" and the spent total does not change.

## Open issues

- Picture generations are in the spend summary only as a count times `STORY_COST_IMAGE_PER_IMAGE`. They are still not video jobs, and a missing image rate stays "not priced".
- Queued video estimates that have not been sent are omitted from the public spent total. They stay in `reserved_usd`.
- Plan text has no price until `STORY_COST_TEXT_PER_1K_TOKENS` is set. The estimator uses `ceil(characters / 4)` as the token count.
- Luma and Seedance keys are not verifiable by Test connection.
- Claude and OpenRouter are in the provider catalog and are not editable. They are not in `RuntimeProviderCredentials::MAP`.
- `eleven_v3` may bill differently from the single configured ElevenLabs audio rate.
- Voiceovers created before timestamp alignment still use approximate subtitle timing. Forced alignment of stored audio is later.
- M15 through M18 screens were not click-tested in the browser during those sprints. Use [docs/QA-CHECKLIST.md](docs/QA-CHECKLIST.md).

## Later

- Optional face-match after a clip, paid and off unless the user turns it on.
- Provider seeds only where that provider's docs confirm them.
- Image spend that uses the provider's own reported cost, when a provider returns one.
- Fold queued-but-unsent estimates into the public total, if the product wants one number.
- Claude and OpenRouter key editing.
- A real Test connection for Luma and Seedance.
- Percent progress from FFmpeg during a render.
- Forced alignment for voiceovers that have no stored timestamps.
