# Brand

Single source of truth for the **HN9 AI Studio** brand identity. Every AI agent and workflow reads
from this folder so that generated content — reels, posts, blogs, images, voiceovers — stays
consistent and on-brand.

## Files

| File | Purpose |
|------|---------|
| `brand.json` | Core brand identity: name, tagline, mission, values, personality. |
| `company.json` | Legal and contact information for the company. |
| `colors.json` | Brand color palette and usage rules. |
| `fonts.json` | Typography system and font sources. |
| `services.json` | Catalog of services offered by HN9. |
| `audience.json` | Target audience personas. |
| `tone.json` | Voice, tone attributes and per-channel adjustments. |
| `brand-guidelines.md` | Human-readable brand guidelines and rules. |

## Conventions
- JSON files are the machine-readable source consumed by agents; keep them valid JSON.
- `brand-guidelines.md` is the human-readable companion — keep both in sync.
- Update `version` and `lastUpdated` in `brand.json` on every meaningful change.

## Location
`HN9-AI-Studio/Brand`
