# Unfold Free AI Extractor Worker

Replaces the paid Claude/Anthropic API requirement with free cloud or 100% local extraction options.

## Providers (`UNFOLD_AI_PROVIDER`)

1. **`gemini` (Free Cloud — Recommended)**
   - Uses Google Gemini Free Tier (`gemini-2.5-flash`) with native PDF support.
   - Set `GEMINI_API_KEY` (or save your key to `%LOCALAPPDATA%\unfold-extractor\gemini-key.txt`).
2. **`ollama` (100% Free & Offline Local LLM)**
   - Install [Ollama](https://ollama.com/) on the lab PC and pull a model:
     ```powershell
     ollama pull qwen2.5:14b
     ```
   - Set `UNFOLD_AI_PROVIDER=ollama` (or just leave Ollama running; auto-detected when no cloud key is set).
3. **`openai_compat` (Groq Free Tier / OpenRouter `:free` / LM Studio)**
   - Set `UNFOLD_AI_PROVIDER=openai_compat`, `UNFOLD_AI_BASE_URL`, `UNFOLD_AI_MODEL`, and `UNFOLD_AI_API_KEY`.
4. **`local_rules` (Zero-AI Fallback)**
   - Runs automatically if no API key and no local Ollama server are present. Extracts metadata, CSV headers, code files, and media lists deterministically so uploads always open cleanly in the review editor.

## Install & Run

```powershell
python -m pip install -r workers/extractor/requirements.txt
python workers/extractor/unfold_worker.py
```
