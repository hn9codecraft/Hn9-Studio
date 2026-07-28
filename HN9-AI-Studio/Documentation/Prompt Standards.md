# Prompt Standards — HN9 AI Studio

Standards for authoring, storing and versioning prompts in `/Prompts`.

## File Naming
- Use kebab-case: `instagram-reel-hook.md`.
- Group by channel/technology (existing subfolders).

## Prompt Template Structure
Each prompt file should include:

```markdown
# <Prompt Title>

## Purpose
What this prompt produces and when to use it.

## Model / Settings
Recommended model, temperature, and constraints.

## Inputs
Variables the prompt expects (e.g. {{topic}}, {{tone}}).

## Prompt
The actual prompt body with placeholders.

## Output Format
The expected structure of the response.

## Examples
Sample input → sample output.
```

## Principles
- **Brand-aware** — reference `/Brand` values, don't hard-code them.
- **Deterministic where possible** — specify output format explicitly.
- **Composable** — prompts should slot into agent workflows.
- **Versioned** — note changes; treat prompts as code.

## Variables Convention
- Use `{{double_curly}}` placeholders for injected values.

_This is a placeholder — extend with concrete house rules as the library grows._
