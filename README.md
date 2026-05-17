# Tahaffuz · Backend

EPI (Expanded Programme on Immunization) vaccinator-training assistant for
Pakistan. Bilingual (Urdu primary, English secondary) RAG chat backend.

- **Stack:** Laravel 13 · Filament 4 admin · SQLite · Google Gemini (`gemini-2.5-flash` chat, `gemini-2.5-flash-lite` PDF extract, `gemini-embedding-001` 768-dim vectors).
- **Retrieval:** hybrid pure-PHP cosine (`App\Services\Rag\VectorStore`) + SQLite FTS5 BM25, fused with Reciprocal Rank Fusion.
- **Companion app:** [tahaffuz-app](https://github.com/IUKHAN53/tahaffuz-app) (Expo Android).

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # creates admin@tahaffuz.local / admin
php artisan serve --host=0.0.0.0 --port=8000
```

Set `GEMINI_API_KEY` in `.env`. Visit `http://127.0.0.1:8000/admin`.

Ingest knowledge-base docs (PDF/DOCX/TXT) from a directory:

```bash
php artisan rag:ingest --kb=epi-pakistan --dir=../docs
```

## Public API

| Method | Path | Body |
|---|---|---|
| `GET` | `/api/health` | — |
| `GET` | `/api/knowledge-bases` | — |
| `POST` | `/api/chat/text` | `{device_id, message, chat_id?, language?}` (`language`: `en`\|`ur`) |
| `POST` | `/api/chat/audio` | multipart with `audio` field + `device_id` |
| `GET` | `/api/chat/{id}?device_id=…` | — |
| `GET` | `/api/chats?device_id=…` | — |
| `DELETE` | `/api/chat/{id}?device_id=…` | — |

## Notes

- PDF extraction is paged via Gemini (smalot's `pdfparser` first; falls through to Gemini when text is glyph-based — handles CorelDRAW-shaped Urdu PDFs that produce only page-marker noise via traditional parsers).
- `.env` is never committed. Set `GEMINI_API_KEY` on each environment.
- SQLite DB is gitignored; the dev database lives at `database/database.sqlite`.
