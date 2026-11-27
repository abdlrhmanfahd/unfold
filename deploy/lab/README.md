# Hosting Unfold on a Computer Lab Server

This kit turns a single Windows (or Linux) computer-lab PC into a self-contained Unfold server for students and instructors across the lab LAN.

## 1. Prerequisites on the Lab Machine

1. **Web + PHP 8.4 + WordPress** (either WordPress Studio locally, or Nginx/Apache + PHP-FPM + MySQL/MariaDB).
   - If migrating from WordPress Studio SQLite to MySQL/MariaDB:
     ```powershell
     studio export unfold-lab.sql --mode db
     mysql -u root -p unfold < unfold-lab.sql
     ```
2. **Python environments** for the two background workers:
   ```powershell
   pip install -r workers/extractor/requirements.txt
   pip install -r workers/world/requirements.txt
   ```
3. **Worker Token**:
   - Sign in to `wp-admin` -> **Projects -> Settings**, tick **Generate a token**, and save the generated `ufw_...` token into `%LOCALAPPDATA%\unfold-extractor\worker-token.txt` (or set `UNFOLD_WORKER_TOKEN`).

## 2. Free AI Provider (No Paid Claude API)

Choose any one of:
- **Google Gemini Free Tier** (recommended cloud): get a free API key from Google AI Studio and set `GEMINI_API_KEY` (or save it to `%LOCALAPPDATA%\unfold-extractor\gemini-key.txt`).
- **Local Ollama** (100% offline inside the lab): install Ollama, run `ollama pull qwen2.5:14b`, and set `UNFOLD_AI_PROVIDER=ollama`.
- **Groq / OpenRouter Free Tier**: set `UNFOLD_AI_PROVIDER=openai_compat`, `UNFOLD_AI_BASE_URL`, `UNFOLD_AI_MODEL`, and `UNFOLD_AI_API_KEY`.
- **Zero-AI fallback**: if none of the above are configured, `workers/extractor/unfold_worker.py` uses `local_rules` so project uploads still work without crashing.

## 3. Email Verification (`@kau.edu.sa` & `@stu.kau.edu.sa`, No Brevo)

Registration accepts only `@kau.edu.sa` and `@stu.kau.edu.sa` by default and verifies ownership with both a **6-digit OTP code** and a **confirmation link**.

1. Go to `wp-admin` -> **Projects -> Settings**.
2. Enter your SMTP server (for example, KAU Office 365 `smtp.office365.com:587` STARTTLS, Gmail Workspace `smtp.gmail.com:587` STARTTLS, or an internal lab SMTP relay).
3. Use **Send test email** on the same screen to confirm real delivery to a `@kau.edu.sa` inbox.

## 4. Keep 3D World & AI Workers Running Automatically

Run both workers together in the terminal:
```powershell
.\deploy\lab\start-lab.ps1
```

Or register them as Windows Scheduled Tasks so they start automatically at logon:
```powershell
.\deploy\lab\install-scheduled-tasks.ps1 -SiteUrl "http://<LAB-PC-IP>"
```
(Remove later with `.\deploy\lab\install-scheduled-tasks.ps1 -Uninstall`.)

## 5. Expose on the Lab LAN Safely

Studio only listens on `localhost` and does not process `.htaccess` rules. Put **Caddy** (`deploy/lab/Caddyfile`) or **Nginx** (`deploy/lab/nginx-unfold.conf`) in front of WordPress so that:
- Students/instructors on the lab network connect over port `80`/`443` (`http://<LAB-PC-IP>`).
- `/wp-content/unfold-private/*` and `/wp-json/wp/v2/users*` return **403 Forbidden**.
- Open port `80` (or `443`) in Windows Defender Firewall for the **Private/Domain** lab network profile, and set `WP_HOME` / `WP_SITEURL` in `wp-config.php` to `http://<LAB-PC-IP>`.
