# Shared agent skills

Third-party skills, vendored unchanged so they come with a fresh clone. Same set as in the talkfrly, caredal and miluseo workspaces, so every project works the same way.

## Provenance

| | |
|---|---|
| Source | `https://github.com/JuliusBrussee/caveman.git`, directory `skills/` |
| Commit | `15581d14007fd01fb3f132016741962f34936ca2` (2026-09-07) |
| License | MIT - `LICENSE` next to this file |

`no-ai-slop/` comes from `https://github.com/petergyang/no-ai-slop.git` (commit `000650b156983f5159695b441477f4e63b25dc85`, 2026-09-01, MIT, `no-ai-slop/LICENSE`).

Only skill directories are copied. Upstream support files (`generated/`, `compile.mjs`, `registry.json`, ...) are left out. Project-specific skills (`talkfrly-*`, `caredal-*`, `miluseo-*`, `tastemaker`) are not copied.

## Skills

| Skill | Does |
|---|---|
| `caveman` | **mandatory** - response style, levels `lite`/`full`/`ultra` |
| `caveman-commit` | commit messages |
| `caveman-review`, `caveman-evidence-review` | change review |
| `caveman-discover`, `caveman-explore` | code reconnaissance |
| `caveman-compress` | compresses context files (Python scripts) |
| `caveman-help`, `caveman-manage`, `caveman-setup`, `caveman-stats`, `caveman-learn`, `caveman-optimize` | caveman housekeeping and tuning |
| `cavecrew` | subagent orchestration |
| `investigate-first`, `lean-build`, `migration`, `safe-refactor`, `surgical-patch`, `verify-and-stop` | code-work procedures |
| `no-ai-slop` | removes phrases typical of AI text from user-facing copy |

## Updating

Copy the directories again from a fresh upstream clone, then bump commit and date above. Do not edit vendored files locally: override behavior with a rule in `CLAUDE.md`.
