# HN9 AI Studio

HN9 AI Studio turns a project idea into a short story video. You write the concept, plan the scenes, generate pictures and clips, add a voiceover, and render a final video. Each paid step shows a cost estimate first. You approve the work before the next step uses it.

The app has two parts:

- `HN9-AI-Studio/Backend` is the Laravel API.
- `HN9-AI-Studio/Frontend` is the React app.

Open the app at `http://127.0.0.1:5173`. The API listens at `http://127.0.0.1:8000`.

## Requirements

- PHP 8.3, from Laragon (`C:\laragon\bin\php\php-8.3*`).
- Composer.
- MySQL, with a database named `hn9_ai_studio`.
- Node.js. `Frontend/package.json` does not pin a Node version. The frontend uses React 19 and Vite 7. Use the Node version that already runs `npm ci` on this machine.
- FFmpeg and FFprobe, if you want to join clips, burn subtitles, or render a final video. See [FFmpeg](#ffmpeg).

## Setup

From PowerShell. Do this once on a new machine. Do not run `php artisan migrate:fresh` on a database that already has projects.

```powershell
cd C:\laragon\www\Hn9-Studio\HN9-AI-Studio\Backend
$php = (Get-ChildItem C:\laragon\bin\php\php-8.3* -Directory | Select-Object -First 1).FullName
$env:Path = "$php;" + $env:Path
composer install
Copy-Item .env.example .env
php artisan key:generate
```

`php artisan key:generate` writes `APP_KEY`. After you save API keys in the app, do not change `APP_KEY`. Those keys are encrypted with it.

Create the MySQL database `hn9_ai_studio` if it does not exist. `Backend/.env` already points at it (`DB_CONNECTION=mysql`, `DB_DATABASE=hn9_ai_studio`). Then:

```powershell
php artisan migrate
```

On a brand-new empty database only, you may create the sample user:

```powershell
php artisan db:seed
```

That creates `test@example.com`. Do not run `db:seed` on a database that already has users or projects.

If the AI services list is empty on an existing database, add the provider catalog only:

```powershell
php artisan db:seed --class=AiProviderSeeder
```

That command writes the eight provider rows (OpenAI, Gemini, ElevenLabs, Runway, Luma, Seedance, Claude, OpenRouter). It does not write keys, users, or settings. It is safe to run again.

Then the frontend:

```powershell
cd C:\laragon\www\Hn9-Studio\HN9-AI-Studio\Frontend
npm ci
Copy-Item .env.example .env
```

`Frontend/.env.example` sets `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1`.

## Run

Use two terminals. In the first:

```powershell
cd C:\laragon\www\Hn9-Studio\HN9-AI-Studio\Backend
$php = (Get-ChildItem C:\laragon\bin\php\php-8.3* -Directory | Select-Object -First 1).FullName
$env:Path = "$php;" + $env:Path
php artisan serve
```

In the second:

```powershell
cd C:\laragon\www\Hn9-Studio\HN9-AI-Studio\Frontend
npm run dev
```

Open `http://127.0.0.1:5173`.

A queue worker is optional. Video generation and the final render run in the web request unless you turn the queues on. Set `STORY_VIDEO_QUEUE_ENABLED=true` or `STORY_RENDER_QUEUE_ENABLED=true` in `Backend/.env` only when you also run a worker:

```powershell
php artisan queue:work --queue=default --timeout=1250
```

After any `.env` change, restart that worker with `php artisan queue:restart`.

These recovery commands are not on a schedule. Run them by hand if a job looks stuck. They do not start a new paid provider job.

```powershell
php artisan hn9:story-recover-renders --dry-run
php artisan story:recover-video-jobs --limit=100
```

Drop `--dry-run` on the render command when you want it to recover stale renders. The video command resumes in-flight jobs. It does not submit a new provider job.

## Users and roles

Roles are `admin` or `member`. AI services requires an admin. The last active admin cannot be demoted.

```powershell
php artisan hn9:user-role test@example.com admin
```

Log out and log in again after a role change. A seeded `test@example.com` account may still be a member until you run that command.

## AI services

Open **AI services** in the sidebar (`/providers`). An admin can save, replace, test, and remove keys for OpenAI, Gemini, ElevenLabs, Runway, Luma, and Seedance. The screen shows only the last four characters.

A non-empty key in `Backend/.env` wins. A key saved in the app fills the config only when the `.env` value is empty. Claude and OpenRouter appear in the catalog and are not editable on this screen.

Test connection is a small authenticated check for OpenAI, Gemini, ElevenLabs, and Runway. Luma and Seedance have no check. For those two, Test connection stores "Key saved; it will be checked on the first generation."

## Environment names

Names only. Values live in `Backend/.env`, which is not committed. See `Backend/.env.example` for the safe defaults.

| Group | Names |
| --- | --- |
| App and database | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Browser access | `CORS_ALLOWED_ORIGINS`, `SANCTUM_TOKEN_EXPIRATION` (10080 minutes in the example), `SANCTUM_STATEFUL_DOMAINS` |
| Providers | `AI_DEFAULT_PROVIDER`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `ELEVENLABS_API_KEY`, `RUNWAYML_API_SECRET`, `LUMA_AGENTS_API_KEY`, `ARK_API_KEY`, `CLAUDE_API_KEY`, `OPENROUTER_API_KEY` |
| Cost (M13) | `STORY_COST_SAFETY_MARGIN`, `STORY_COST_DISPLAY_CURRENCY`, `STORY_COST_USD_INR`, `STORY_COST_TEXT_PER_1K_TOKENS`, `STORY_COST_IMAGE_PER_IMAGE`, `STORY_COST_ELEVENLABS_PER_1K_CHARS`, and the per-second video rates in `.env.example` |
| Voice and subtitles (M16, M19) | `STORY_VOICE_DEFAULT_LANGUAGE`, `STORY_VOICE_TIMESTAMPS_ENABLED`, `STORY_VOICE_TIMING_LEAD_IN_MS`, `STORY_VOICE_TIMING_TAIL_MS`, `STORY_VOICE_TIMING_MIN_SCENE_SECONDS`, `STORY_VOICE_TIMING_MAX_SCENE_SECONDS`, `STORY_VOICE_TIMING_ROUND_TO`, `ELEVENLABS_MODEL_EN`, `ELEVENLABS_MODEL_HI`, `ELEVENLABS_MODEL_GU`, `ELEVENLABS_VOICE_EN`, `ELEVENLABS_VOICE_HI`, `ELEVENLABS_VOICE_GU`, `STORY_SUBTITLE_DISK`, `STORY_SUBTITLE_FONT_NAME`, `STORY_SUBTITLE_FONT_FILE`, `STORY_SUBTITLE_FONT_SIZE` |
| Queues and retry | `STORY_RENDER_QUEUE_ENABLED`, `STORY_RENDER_JOB_TIMEOUT`, `STORY_RENDER_STALE_MINUTES`, `STORY_VIDEO_QUEUE_ENABLED`, `STORY_VIDEO_QUEUE_NAME`, `STORY_RETRY_MAX_PER_UNIT` |
| FFmpeg | `FFMPEG_BINARY`, `FFPROBE_BINARY` |

The frontend uses one name: `VITE_API_BASE_URL`.

## FFmpeg

`php artisan serve` may not see a winget FFmpeg install. Set the full paths in `Backend/.env`:

```powershell
FFMPEG_BINARY=C:\path\to\ffmpeg.exe
FFPROBE_BINARY=C:\path\to\ffprobe.exe
```

Check the subtitles filter:

```powershell
ffmpeg -hide_banner -filters | Select-String subtitles
```

Burned-in subtitles use the font named by `STORY_SUBTITLE_FONT_NAME` (default `Nirmala UI`). Leave `STORY_SUBTITLE_FONT_FILE` empty to use the Windows font at `C:\Windows\Fonts\Nirmala.ttc`.

## Feature tour

Prices are shown in the app as a cost estimate. This README does not list prices.

### Cost, budget, and spend (M13)

The story screen shows an estimate before a paid action, a project budget, and a spend summary. Viewing the estimate and the summary is free. A paid action is blocked with `story_budget_exceeded` when a rate exists and the estimate would pass the budget.

### AI services (M14)

Described above. Keys stay encrypted. `.env` wins when it is non-empty.

### Quick start (M15)

Quick start builds a concept through "Ready for review". It does not approve anything, and it does not create pictures, video, audio, or renders. If the project already has a concept, Quick start stops with a conflict. Apply template fills empty fields only and does not save by itself.

### Voice timing and subtitles (M16)

On Sound, "Match scene length to voiceover" previews a new scene length, rounds to 1 second or 5 seconds, and applies it as a new revision. It warns when clips already exist. That preview and apply are free. SRT and VTT download, and burning subtitles into the video, are free. Only a new voiceover is charged.

### Character lock and prompt preview (M17)

On Cast, approve one reference picture and click Lock look. Generate and Regenerate open a preview first. The preview is free. If the prompt, picture, price, or provider changed after the preview, the app returns `story_prompt_preview_stale` and keeps the preview open.

### Retry, bulk approve, and progress (M18)

A failed clip shows the reason and a Retry button. Retry reuses the same request and counts toward a limit of 5 attempts (`STORY_RETRY_MAX_PER_UNIT`). Bulk approve lists ready items with nothing pre-ticked and says "Not charged now". The header progress bar counts the story steps.

### Versions, voice language, and background render (M19)

The versions drawer can restore an earlier scene video or assembly. Voice language includes English, Hindi, and Gujarati. Gujarati needs the `eleven_v3` model (`ELEVENLABS_MODEL_GU`). A final render can run in the background when the render queue is on, and the bell lists the notification when it finishes.

## Testing

From `HN9-AI-Studio/Backend`, with Laragon PHP on `PATH`:

```powershell
php artisan test
```

Tests use SQLite in memory. They do not use MySQL and they do not call providers. One test stays skipped: `StoryProductionPlanSchemaTest` skips the MySQL CHECK-constraint case on SQLite.

From `HN9-AI-Studio/Frontend`:

```powershell
npm run build
node --test src/services/productionClips.test.js
```

The clip test file has 13 tests.

A subtitle burn test uses a real FFmpeg binary when `ffmpeg` can see the subtitles filter and the Nirmala font. Otherwise that test skips.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| 403 on AI services | The account is a member. Run `php artisan hn9:user-role you@example.com admin`, then log out and log in. |
| `story_budget_exceeded` | The estimate is above the project budget, or no rate is configured for that action. Raise the budget or set the matching `STORY_COST_*` rate. |
| FFmpeg not found | Set `FFMPEG_BINARY` and `FFPROBE_BINARY` to full paths, then restart `php artisan serve`. |
| `story_subtitle_burn_unavailable` or `story_subtitle_font_missing` | Install FFmpeg with the subtitles filter, and set `STORY_SUBTITLE_FONT_FILE` or install Nirmala UI. |
| `story_prompt_preview_stale` | The prompt changed after the preview. Read the preview again, then generate. |
| `story_unit_generation_failed` | The clip failed. Use Retry on that clip. |
| A queued render never finishes | `STORY_RENDER_QUEUE_ENABLED` is true and no worker is running. Start `php artisan queue:work --queue=default --timeout=1250`. |
| Git warns about CRLF | Line endings only. Do not normalize or restore those files as part of a feature change. |

## Project history

| Sprint | Merge | What landed |
| --- | --- | --- |
| M12 | `7243a04` | CORS allow-list, Sanctum token lifetime, and the security pass (`4559a5e`) |
| M13 | `747fca3` | Cost estimate, budget, and spend |
| M14 | `a8e0134` | In-app API keys |
| M15 | `e0e9e66` | Quick start |
| M16 | `cf81201` | Voice timing and subtitles |
| M19 | `fc37a37` | Versions, voice language, and background render |
| M17 | `7ec5609` | Character lock and prompt preview |
| M18 | `6f06e8a` | Retry, bulk approve, and the progress bar |
| M20 | this README | Roadmap and the QA checklist |

The current product notes are in [ROADMAP.md](ROADMAP.md). The click-through list is in [docs/QA-CHECKLIST.md](docs/QA-CHECKLIST.md).

Older design notes live under [Documentation](Documentation/README.md).
