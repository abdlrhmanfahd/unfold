# Contributing & Software Development Cycle (SDLC)

This project follows a strict Pull Request workflow so every change is verified before reaching `main`.

## Branching & PR Rules

1. **Never commit directly to `main`.**
2. Create a descriptive branch from `main`:
   - `fix/<short-description>` for bug fixes
   - `feat/<short-description>` for new features
   - `chore/<short-description>` for tooling, CI, or maintenance
   - `test/<short-description>` for test additions
3. Make focused, well-scoped commits.
4. Run all local checks before opening a PR:
   ```powershell
   npm run check:syntax
   npm run lint
   npm test
   studio wp eval-file tests/studio-smoke.php
   ```
5. Push your branch and open a Pull Request against `main` using the PR template.
6. Wait for both GitHub Actions CI jobs (`php-checks` and `js-and-schema-checks`) to pass.
7. Squash-merge the PR into `main` and delete the source branch.
