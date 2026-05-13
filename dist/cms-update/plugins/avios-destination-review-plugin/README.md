# Avios Destination Review

Generates SEO-friendly destination reviews via OpenAI for every British Airways destination already loaded in the **How Many Avios · Fare table** plugin.

## Requirements

- `how-many-avios-plugin` must be **activated** and `hma_fares` must contain at least one destination. The plugin shows an actionable banner on the admin page otherwise.
- A valid OpenAI API key configured for the CMS: **System → API keys** (`/admin/system/api-keys`), or via `OPENAI_API_KEY` / `STRUXA_OPENAI_API_KEY` in the environment (same resolution order as `OpenAiApiKeyResolver` elsewhere in Struxa).

## Install

1. `mysql … < plugins/avios-destination-review-plugin/migrations/001_adr_schema.sql`
2. Activate **Avios Destination Review** under Admin → Extensions.
3. Configure the OpenAI key under **System → API keys** if you have not already.

## Data model

All tables are prefixed `adr_` and are dropped by `uninstall.sql`.

- `adr_settings` — singleton row (`id = 1`) with `prompt_template` and image-generation columns. Legacy `openai_api_key` / `openai_model` columns may still exist from older installs but are no longer used; the plugin reads the key and chat model only from the CMS-wide resolver.
- `adr_reviews` — one row per destination (`UNIQUE KEY` on `iata`). Editable via the admin modal.

## Admin URLs

- `/admin/avios-destination-review` — picker, datatable, edit modal.
- `/admin/avios-destination-review/settings` — prompt template and image options (OpenAI key: System → API keys).

## OpenAI contract

The prompt asks for a single JSON object:

```json
{
  "meta_title": "...",
  "meta_description": "...",
  "content_html": "<p>…</p><h2>…</h2>…"
}
```

`response_format: json_object` is set on the API call, and the response is parsed/validated and HTML-sanitised (script/style/iframe removed, on-event attributes stripped) before storage.
