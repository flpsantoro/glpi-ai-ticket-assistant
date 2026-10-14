# Configuration

**Setup > AI Ticket Assistant** (right: update the general setup). The page starts with a quick
setup card; every other setting is in the tabs below it and has a sensible default. Each setting can
also be changed from the command line with its key:

```bash
php bin/console plugins:ticketassistant:configure --set=daily_budget_usd=10 --set=kb_max_results=5
php bin/console plugins:ticketassistant:configure --show     # every key and its value, keys masked
```

Some defaults follow the default language of GLPI: with Brazilian Portuguese, CPF/CNPJ masking is on
and the greeting, signature and communication standard are in Portuguese.

- [Quick setup](#quick-setup)
- [Actions](#actions)
- [Model and generation](#model-and-generation)
- [Context and attachments](#context-and-attachments)
- [Learning and classification](#learning-and-classification)
- [Privacy](#privacy)
- [Costs and limits](#costs-and-limits)
- [Writing style](#writing-style)
- [Diagnosis](#diagnosis)
- [Category guidelines](#category-guidelines)
- [Constants](#constants)

## Quick setup

| Setting | Key | Default | Notes |
|---|---|---|---|
| AI provider | `provider` | `openai` | `openai`, `azure`, `anthropic`, `gemini`, `mistral`, `openrouter`, `ollama`, `compatible` ([PROVIDERS.md](PROVIDERS.md)) |
| API address | `base_url` | empty | empty = default address of the provider; required for Azure and compatible servers |
| Model / deployment | `model` | empty | free text; the button next to it lists the models of the provider |
| API version | `api_version` | `2024-10-21` (Azure) | Azure only; `v1` for the v1 API |
| API key | `api_key` | empty | encrypted with the key of the GLPI installation, never displayed again; leave the field empty to keep it |
| Enable the assistant | `enabled` | off | the buttons appear only when enabled **and** the connection is complete |

**Test the connection** sends a one-word request with the saved settings (and an embedding when
semantic search is on) and shows the model, the duration, the tokens and the cost.

## Actions

| Setting | Key | Default |
|---|---|---|
| Analyze the ticket (summary, technical strategy, reply draft) | `action_analyze` | on |
| Improve my text (6 modes, in the followup and solution forms) | `action_improve` | on |
| Classification (type, category, urgency) | `action_classify` | on |
| Knowledge base article from a solved ticket | `action_article` | on |

## Model and generation

| Setting | Key | Default | Notes |
|---|---|---|---|
| Model type | `model_type` | `reasoning` | `reasoning` sends a reasoning effort and no temperature; `standard` sends the temperature |
| Temperature | `temperature` | `0.3` | standard models only |
| JSON answers | `json_mode` | `auto` | `json_schema` (strict), `json_object`, `prompt` (instructions only); `auto` = what the provider supports |
| Output limit parameter | `token_param` | `auto` | `max_completion_tokens` or `max_tokens` |
| The model reads images | `vision` | on | turn off for text-only models |
| Image detail | `image_detail` | `auto` | `low` is cheaper |
| Timeout (s) | `http_timeout` | `90` | |
| New attempts | `http_retries` | `2` | on network errors, 429 and 5xx; `Retry-After` honoured; quota and content filter errors are not retried |
| Use the proxy of GLPI | `use_glpi_proxy` | on | local and private addresses are always reached directly |

Per action, output limit and reasoning effort (`none`, `minimal`, `low`, `medium`, `high`, or
not sent):

| Action | Limit key | Default | Effort key | Default |
|---|---|---|---|---|
| Analyze | `analyze_max_tokens` | 4096 | `analyze_effort` | medium |
| Improve | `improve_max_tokens` | 2048 | `improve_effort` | low |
| Classification | `classify_max_tokens` | 1024 | `classify_effort` | low |
| Article | `article_max_tokens` | 3072 | `article_effort` | low |
| Learning (distillation) | `learn_max_tokens` | 1536 | `learn_effort` | low |

On reasoning models the output limit includes the reasoning tokens: keep about twice the expected
text.

## Context and attachments

| Setting | Key | Default | Notes |
|---|---|---|---|
| Context size (characters) | `max_context_chars` | 24000 | the oldest followups are left out first |
| Private followups and tasks | `private_items` | `rights` | `rights`: those the technician may see; `all`: all, flagged as internal notes; `none` |
| Linked assets | `context_linked_items` | on | |
| Solution templates of the entity as style references | `solution_templates` | on | |
| Send attached images | `images_enabled` | on | |
| Maximum images | `images_max_count` | 4 | |
| Maximum size sent (MB) | `images_max_mb` | 4 | larger images are reduced, not skipped (needs GD) |
| Longest side (pixels) | `images_max_px` | 2048 | |
| Read attached PDFs | `pdf_enabled` | on | |
| Maximum PDFs | `pdf_max_count` | 2 | |
| Characters per PDF | `pdf_max_chars` | 4000 | |
| Scanned PDF: pages sent as images | `pdf_scan_pages` | 2 | needs `pdftoppm`; uses the image budget |
| Send the PDF file itself | `pdf_native` | off | OpenAI and Anthropic read the file (tables, images); costs more tokens |
| poppler-utils folder | `poppler_path` | empty | empty = `PATH` of the web server |
| Office, OpenDocument and text files | `docs_enabled` | on | docx, xlsx, pptx, odt, ods, odp, txt, log, csv, json, xml, yaml… |
| Maximum documents | `docs_max_count` | 3 | |
| Characters per document | `docs_max_chars` | 4000 | |

The attachments of the ticket **and** of its followups, tasks and solutions are read, newest
first.

## Learning and classification

| Setting | Key | Default | Notes |
|---|---|---|---|
| Similar tickets sent with an analysis | `kb_max_results` | 3 | 0 = none |
| Minimum similarity of a reference | `kb_min_similarity` | 0.10 | cosine of the words (full-text) or of the embeddings: with embeddings, 0.3–0.5 |
| Only solved tickets of the entities of the technician | `kb_same_entity` | on | |
| Distill solved tickets with the AI | `learn_enabled` | off | automatic action `ailearn` |
| Tickets per run | `learn_batch` | 20 | |
| Daily budget of automatic actions (US$) | `background_budget_usd` | 1.00 | distillation, embeddings, triage |
| Semantic search | `embeddings_enabled` | off | needs an embeddings model |
| Embeddings model | `embedding_model` | empty | e.g. `text-embedding-3-small`, `nomic-embed-text` |
| Dimensions | `embedding_dimensions` | 0 | 0 = model default |
| Recent tickets scanned | `embeddings_scan_limit` | 3000 | cases compared by embeddings on each search |
| Separate embeddings connection | `emb_separate` | off | required with Anthropic; keys `emb_provider`, `emb_base_url`, `emb_api_version`, `emb_api_key` |
| Let the AI choose among the categories | `classify_use_llm` | on | off = vote of the similar tickets only (free, instant) |
| Neighbours considered | `classify_neighbors` | 25 | |
| Automatic triage of new tickets | `triage_enabled` | off | automatic action `aitriage` |
| Only tickets created without category | `triage_only_uncategorized` | on | |
| Use the AI for automatic triage | `triage_use_llm` | off | costs tokens, within the background budget |
| Apply automatically above the threshold | `triage_auto_apply` | off | measure the accuracy first |
| Confidence threshold | `triage_threshold` | 0.85 | |

How these work: [LEARNING-AND-CLASSIFICATION.md](LEARNING-AND-CLASSIFICATION.md).

## Privacy

| Setting | Key | Default |
|---|---|---|
| Masking detectors | `mask_detectors` | `secrets,card` (+ `cnpj,cpf` for Brazilian Portuguese) |
| Custom patterns (`LABEL=/regex/`, one per line) | `mask_custom` | empty |
| Send the requester's first name | `send_requester_name` | on |
| Keep the generated texts in the log | `log_responses` | on |
| Keep generated texts (days, 0 = forever) | `response_retention_days` | 90 |
| Keep log rows (days, 0 = forever) | `log_retention_days` | 365 |

Detectors: `secrets`, `email`, `card`, `iban`, `cnpj`, `cpf`, `phone`, `ipv4`. With the command line:
`--set=mask_detectors=secrets,card,email`. Details and limits:
[SECURITY-AND-PRIVACY.md](SECURITY-AND-PRIVACY.md).

## Costs and limits

| Setting | Key | Default | Notes |
|---|---|---|---|
| Input price (US$ per million tokens) | `price_input` | 0.00 | from the price list of your model |
| Output price (US$ per million tokens) | `price_output` | 0.00 | |
| Daily budget of the technicians (US$) | `daily_budget_usd` | 5.00 | 0 = none |
| Uses per technician per hour | `user_hourly_limit` | 20 | 0 = no limit |
| Interval between two analyses of a ticket (s) | `ticket_cooldown_seconds` | 60 | the last analysis is shown again meanwhile |

Costs are **estimated** from the tokens reported by the provider and these prices: with prices at
0 the budgets cannot stop anything. Local models: leave 0 and rely on the hourly limit.

## Writing style

| Setting | Key | Default |
|---|---|---|
| Organization name (used in the prompts) | `organization_name` | empty |
| Language of the answers | `response_language` | default language of GLPI (`auto` = language of the ticket) |
| Communication standard for messages to the requester | `style_guide` | a short standard in the default language |
| Signature | `signature_template` | `Best regards,<br>{name}` (localized) |
| Additional instructions for the AI | `extra_instructions` | empty |

The signature is added by the server, not by the model; placeholders `{name}`, `{firstname}`,
`{entity}`, `{organization}`; allowed HTML `<br>`, `<strong>`, `<em>`; empty = no signature.

## Diagnosis

The **Diagnosis** tab shows what the web server sees: GLPI and PHP versions, curl, GD, zip,
`shell_exec`, `pdftotext` and `pdftoppm`, the proxy of GLPI, the library of solved tickets and
the state and last run of each automatic action.

## Category guidelines

**Setup > Dropdowns > ITIL categories > a category > tab "AI assistant"**: instructions added to the
prompt for tickets of that category (what to ask, which procedure applies, when to escalate),
optionally inherited by the sub-categories. Right: update ITIL categories.

## Constants

Defined in `config/local_define.php` of GLPI (create the file if needed):

| Constant | Effect |
|---|---|
| `TICKETASSISTANT_URL_ALLOWLIST` | array of regular expressions; when defined, the plugin only calls addresses matching one of them, e.g. `['#^https://api\.openai\.com/#', '#^http://10\.0\.0\.5:11434/#']` |
