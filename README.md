# Unfold

Interactive engineering project presenter built as a WordPress plugin (`wp-content/plugins/unfold`) and block theme (`wp-content/themes/unfold-theme`). Renders block diagrams, subsystem simulations, 3D worlds, and results vs. targets from a structured project JSON document.

## Features

- **KAU University Email Verification (No Brevo)**: Restricts sign-ups to `@kau.edu.sa` and `@stu.kau.edu.sa`, verifying ownership with both a 6-digit OTP code and confirmation link using generic SMTP (`wp-admin → Projects → Settings`), plus Unfold-themed sign-in, forgot/reset password, and show/hide password toggles.
- **Flexible AI Project Extraction + Free Fallbacks**:
  - Admin-configured **Built-in AI Presets** (`Lab Local Ollama`, `Lab Gemini Free`, or campus internal OpenAI-compatible LLMs).
  - **Per-user BYOK** (encrypted at rest per account) with key auto-detection and live model loading.
  - **Quick Extract (No AI)** AST/report parser producing schema-valid drafts offline.
- **Automatic AI Cover Illustration**: Generates a clean 2D vector illustration for each extracted project and attaches it as the project's featured cover image.
- **3D World Worker**: Automatically converts uploaded driving recordings into browser-playable 3D scenes (`workers/world/`).

## Repository Structure

- `wp-content/plugins/unfold/` — Core Unfold plugin (PHP classes, JSON Schema, JS/CSS, vendored front-end libraries).
- `wp-content/themes/unfold-theme/` — Custom WordPress block theme (`theme.json`, templates, parts).
- `workers/extractor/` — Free AI + local-rules extractor & cover illustration worker (`unfold_worker.py`).
- `workers/world/` — 3D world pipeline worker (`world_worker.py`).
- `deploy/lab/` — Computer lab bootstrap kit (`setup-lab.ps1`, `start-lab.ps1`, `install-scheduled-tasks.ps1`, `Caddyfile`, `nginx-unfold.conf`).
- `tests/` — PHPUnit unit tests, JS/schema/theme integrity tests, Python worker tests, and Studio smoke test.

## Hosting on a Computer Lab PC (1-Command Transfer)

> **Do you need WordPress Studio on the lab PC?** **No.** You have two ways to run Unfold on the lab computer:
> - **Option A — Standalone PHP 8.4 + MySQL/MariaDB + Caddy/Nginx (Recommended for permanent lab hosting):** Install PHP 8.4 + MariaDB/MySQL + Caddy/Nginx (no WordPress Studio required), import the DB (`studio export unfold-lab.sql --mode db` from your dev PC), configure `wp-config.php`, and put `deploy/lab/Caddyfile` or `deploy/lab/nginx-unfold.conf` in front.
> - **Option B — WordPress Studio (Fastest local setup):** Install WordPress Studio on the lab PC, import this folder, and expose it on your lab LAN using `deploy/lab/Caddyfile` or `deploy/lab/nginx-unfold.conf`.

On the lab PC, clone the repo and run the automated bootstrap script:

```powershell
git clone https://github.com/abdlrhmanfahd/unfold.git
cd unfold
.\deploy\lab\setup-lab.ps1
```

`setup-lab.ps1` automatically creates repo-local Python `.venv` environments, installs **Ollama** and pulls `qwen2.5:7b` (offline, zero API cost), enables the `Lab Local Ollama` preset, and registers boot auto-start tasks. See [deploy/lab/README.md](deploy/lab/README.md) for full details and firewall/LAN proxy setup.

## Running Tests & Checks

```powershell
npm run check
python -m unittest discover -s tests/python -v
composer install && vendor/bin/phpunit
studio wp eval-file tests/studio-smoke.php
```

## Workflow

All changes go through feature/fix branches and Pull Requests against `main`. See [CONTRIBUTING.md](CONTRIBUTING.md) for details.
