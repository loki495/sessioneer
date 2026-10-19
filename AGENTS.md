# Codex project instructions

Delivery: PR. The default branch has required status checks, so changes land through a throwaway-branch PR (git-workflow skill, GH006); the remote branch name doesn't matter. Commit on the default branch locally; push only after explicit confirmation.

Read `CLAUDE.md` completely. Read `.ai/feature-atlas/SUMMARY.md` if present (gitignored, maintainer-local — regenerate via `/feature-atlas` if missing) before re-deriving feature boundaries, then load relevant subsystem details only as needed. Apply `~/AGENTS.md` if present (that machine's own global Codex instructions, outside this repo and not something a fresh clone has) and map Claude workflows to `claude-import-*` Codex skills.
