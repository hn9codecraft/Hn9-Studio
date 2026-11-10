# QA checklist

Click through these steps in the browser. The automated suite does not replace this list.

## Before you start

- [ ] API is running: from `HN9-AI-Studio\Backend`, `php artisan serve` (port 8000).
- [ ] App is running: from `HN9-AI-Studio\Frontend`, `npm run dev`. Open `http://127.0.0.1:5173`.
- [ ] You are logged in as an admin. If AI services returns 403, from the Backend folder run `php artisan hn9:user-role test@example.com admin`, then log out and log in.
- [ ] For paid steps only, the project budget is high enough and the matching provider key is saved under AI services. Free steps do not need a key.

Costs: viewing pages, estimates, previews, Quick start through Ready for review, matching scene length, subtitle download, and bulk approve do not charge. Generating a picture, a clip, a voiceover, or a provider video does charge when a rate and a key exist.

## Free-only smoke path

No API key and no spend.

- [ ] Log in and open a project story.
- [ ] Expected: the story steps load, and the header progress bar is visible.
- [ ] Open the cost summary.
- [ ] Expected: the page loads. It does not start a generation.
- [ ] Open Quick start and read the estimate. Do not apply it if the project already has a concept.
- [ ] Expected: the estimate request succeeds or explains the conflict. Nothing is approved.

## M14 — AI services

- [ ] As a member, open AI services.
- [ ] Expected: 403.
- [ ] As an admin, open AI services (`/providers`).
- [ ] Expected: OpenAI, Gemini, ElevenLabs, Runway, Luma, and Seedance can be edited. Claude and OpenRouter are not edited here.
- [ ] Save a key.
- [ ] Expected: the screen shows the last four characters, not the full key.
- [ ] Test connection on OpenAI, Gemini, ElevenLabs, or Runway.
- [ ] Expected: Connected, invalid key, unreachable, or rate limited. This check can contact that provider.
- [ ] Test connection on Luma or Seedance.
- [ ] Expected: "Key saved; it will be checked on the first generation." No charge.

## M13 — Budget

- [ ] Set a project budget, then open a paid action whose estimate is above that budget.
- [ ] Expected: the action is blocked with `story_budget_exceeded`. The estimate itself stays visible.
- [ ] Raise the budget above the estimate.
- [ ] Expected: the block clears. Do not confirm the paid action unless you intend to spend.

## M15 — Quick start

Use a project that has no concept yet, such as a fresh project. The Hn9 Journey sample is only for this click-through if it still has no concept. If a concept exists, expect a conflict and stop.

- [ ] Choose Company journey and 40 seconds. Apply Quick start.
- [ ] Expected: status is Ready for review. Nothing is approved. No pictures, video, audio, or renders were created.
- [ ] Apply template on a project that already has some fields filled.
- [ ] Expected: only empty fields change, and Apply template does not save on its own.

## M16 — Sound and subtitles

- [ ] On Sound, open Match scene length to voiceover. Preview, then apply.
- [ ] Expected: the preview is free. Apply creates a new revision and warns if clips already exist. Rounding is 1 second or 5 seconds.
- [ ] On Final, download SRT and VTT.
- [ ] Expected: both files download. This is free.
- [ ] Burn subtitles, including a Gujarati line if you have one.
- [ ] Expected: the burned video plays and the text is visible. Burn-in is free. A missing FFmpeg or Nirmala font returns `story_subtitle_burn_unavailable` or `story_subtitle_font_missing`.

## M17 — Lock and preview

- [ ] On Cast, approve one reference picture and click Lock look. Then Unlock.
- [ ] Expected: Lock look freezes the look. Unlock allows edits again.
- [ ] Generate a clip and read the preview. Change the scene text. Generate with the old preview still open.
- [ ] Expected: the preview is free. After the text changes, the app reports `story_prompt_preview_stale` and does not start the paid job.

## M18 — Retry, bulk approve, progress

- [ ] Open a failed clip.
- [ ] Expected: the failure reason is shown, and Retry is available until 5 attempts.
- [ ] Open Bulk approve.
- [ ] Expected: nothing is pre-ticked, and the screen says "Not charged now".
- [ ] Read the header progress bar on Story, Cast, Scenes, Sound, and Final.
- [ ] Expected: the same project shows a consistent count.

## M19 — Versions, language, render

- [ ] Open the versions drawer and restore an earlier version.
- [ ] Expected: the chosen version becomes current. The older file is not deleted.
- [ ] Change the voice language to Gujarati.
- [ ] Expected: the language is accepted when `ELEVENLABS_MODEL_GU` is `eleven_v3` (or another wide model). A new voiceover is the paid step, not the language change itself.
- [ ] Start a final render with `STORY_RENDER_QUEUE_ENABLED=true` and a worker running.
- [ ] Expected: the render finishes in the background and the bell lists it. With the queue left off, the render runs in the request instead.

## After the click-through

- [ ] Note any bug that the steps above did not already explain. Add it to `HN9-AI-Studio/ROADMAP.md` under Open issues if it is still open.
