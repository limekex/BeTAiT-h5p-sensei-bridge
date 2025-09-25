# Contributing

Thanks for considering contributing!

## How to contribute
1. Fork the repo, create a feature branch from `develop` (or `main` if you prefer trunk-based):  
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
- Update version numbers and `CHANGELOG.md`.
- Create an annotated tag: `git tag -a vX.Y.Z -m "..."` and `git push origin vX.Y.Z`.
- GitHub Release will be created with notes and attached artifacts (via Actions).

## Security
Report vulnerabilities via [SECURITY.md](SECURITY.md).
