# Contributing

Thanks for considering contributing!

Start with [docs/ENGINEERING_PLAN.md](docs/ENGINEERING_PLAN.md). It defines the task order, source references, acceptance checks and VS Code handoff. Reference the relevant HSB task ID in each PR and record verification evidence. The reconciliation candidate adds PHP/Node regression checks in `.github/workflows/checks.yml`. Run the commands in the plan and inspect the PR checks. Real WordPress/Sensei/H5P staging remains required; do not report it as verified from test doubles.

## How to contribute
1. Fork the repo, create a feature branch from the reconciled `main` baseline:
   `git checkout -b feature/short-description`
2. Make changes with clear commits.
3. Ensure CI passes (tests/lint).
4. Open a Pull Request. Use our PR template. Describe the change, motivation, and testing.

## Commit messages
- Use clear, imperative style: `Add settings UI for playlists`
- One logical change per commit.

## Branch naming
- `feature/<short>` for features
- `fix/<short>` for bug fixes
- `docs/<short>` for documentation
- `chore/<short>` for maintenance

## Coding standards
- WordPress PHP: follow WP coding standards (PHPCS if available).
- Node: use ESLint/Prettier if available.
- Python: follow PEP8; use `black`/`ruff` if available.

## Releases
- Follow [HSB-010's release and rollback procedure](docs/HSB_010_VALIDATION.md). Current `1.2.0-dev` packages are validation candidates; do not tag or deploy them.
- Run `python3 tests/package_test.py`, `python3 scripts/build-plugin.py` and the regression/integration matrix. New runtime files must be tracked and accepted by the package allowlist.
- After the staging gate and maintainer approval, update the plugin header, `FKHS_VER`, packaged readme, translation catalogs (including compiled MO) and a dated changelog entry together. Leave the independent database schema version unchanged unless its migration changes.
- Verify `python3 scripts/build-plugin.py --tag vX.Y.Z` from a clean checkout. Only then create and push an annotated tag for that same revision.
- Tag automation reruns all eight checks, validates tag/header/changelog identity and compares the release ZIP with the tested artifact. It creates a **draft**, with explicit write permission only in the drafting job. A maintainer reviews evidence and publishes separately; neither a branch push nor a green test run publishes a release.

## Security
Report vulnerabilities via [SECURITY.md](SECURITY.md).
