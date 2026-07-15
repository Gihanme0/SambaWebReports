# Git Workflow

## Branches

- `main`: stable branch. Do not develop directly here.
- `feature/*`: normal feature and improvement work.
- `hotfix/*`: urgent production fixes.
- `release/*`: release preparation.
- `review/*`: review branches only when explicitly required.

## Rules

- Pull before work: `git pull origin <branch>`.
- Keep the working tree clean before starting unrelated work.
- Commit only validated milestones.
- Push after a stable milestone.
- Use pull requests before merging to `main`.
- Do not force push without explicit approval.
- Do not run destructive reset/checkout commands without explicit approval.
- Never commit `config.php`, database backups, generated exports, or backup PHP files.

## Commit Message Format

Use a short prefix and an imperative summary:

- `feat:`
- `fix:`
- `refactor:`
- `style:`
- `docs:`
- `perf:`
- `test:`
- `chore:`
- `audit:`

Project examples:

- `feat: modernize inventory analytics UI`
- `fix: correct warehouse balance calculation`
- `fix: prevent duplicate recipe consumption`
- `docs: add inventory validation notes`
- `perf: optimize inventory movement aggregation`
- `audit: document inventory transaction findings`

## Safe Rollback Guidance

Inspect before changing history:

```powershell
git status --short --branch
git log --oneline -5
```

Restore an unstaged file only when the change is yours and no user work will be lost:

```powershell
git restore path/to/file.php
```

Undo the last commit while keeping files staged:

```powershell
git reset --soft HEAD~1
```

Create a revert commit for a pushed change:

```powershell
git revert <commit-hash>
```

Do not use `git reset --hard` or force push unless the user explicitly approves it.
