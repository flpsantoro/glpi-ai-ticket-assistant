# Changelog

All notable changes of the plugin. Versions follow [Semantic Versioning](https://semver.org/);
every release updates `PLUGIN_TICKETASSISTANT_VERSION` in `setup.php`.

## [Unreleased]

### Added
- The installers find a GLPI running in a container (Docker, Podman, Docker Desktop) and install
  the plugin in it: read-only detection with the PHP of the container, console with `docker exec`
  as the owner of the GLPI files (API key on the standard input), copy with `docker cp` into a
  folder kept by a volume (the marketplace folder of the official `glpi/glpi` image), plugin folder
  mounted from the host left untouched, address of a local AI server seen from the container, cron
  of the container or of the host, backups on the host. Options `--docker` / `-Docker` and
  `-WebUser`.
- The installers update the plugin where GLPI loads it, its marketplace folder included.

### Changed
- Coding style of the GLPI plugins baseline (PER Coding Style 3.0 with PHP-CS-Fixer), checked by
  the CI.
- The PHP extensions are checked by GLPI from the requirements of the plugin, with its translated
  messages.
- `plugin.xml` (plugins catalog): screenshots, link to the changelog, description and tags in French
  and Spanish, exact minimum version of GLPI 10 (10.0.11).

## [1.0.0] — 2026-10-14

First public release: an AI assistant for the technicians of any GLPI installation, with any AI
provider.

### Ticket analysis
- **AI assistant** button in the timeline: summary, technical strategy (probable diagnosis, what to
  check, step by step, when to escalate), reply draft for the requester and the information still
  missing.
- Reads the whole ticket: description, followups, tasks, solutions (refused ones flagged),
  assigned group, linked assets, approval status.
- Attachments of the ticket **and of its followups, tasks and solutions**: images (vision,
  oversized images reduced with GD instead of skipped), PDF (text with poppler-utils or, without it,
  a built-in PHP reader; scanned pages as images with pdftoppm; or the PDF itself for providers that
  read PDF), Office/OpenDocument files (docx, xlsx, pptx, odt, ods, odp) and text files (logs, CSV,
  JSON…).
- Similar solved tickets (above a minimum similarity) and solution templates of the entity as
  references.
- An unchanged ticket shows its last analysis again, at no cost.

### Writing
- **Improve my text** in followup and solution forms, six modes: improve, more formal,
  friendlier, shorter, fix spelling only, translate to the requester's language.
- Server-side signature with placeholders, closing formulas written by the model removed.

### Learning from solved tickets
- Library of solved/closed tickets synchronized daily (incremental, content hash, removal of
  reopened or deleted tickets).
- Optional AI distillation into anonymized, reusable records (summary, resolution steps,
  keywords, suggested type/category, reusable flag) and optional embeddings for semantic search.
- Full-text candidates ranked by the cosine of their meaningful words (stopwords of English,
  Portuguese, Spanish and French, accents and plurals left aside): a bounded similarity that weighs
  the votes, makes the confidence comparable and drops weak references.
- Classification (type, category, urgency) by k nearest solved tickets, optionally refined by
  the AI among the candidates with the similar tickets as examples; one click applies it.
- Automatic triage of new tickets (queue + automatic action), with optional automatic
  application above a confidence threshold.
- Temporal evaluation of the classification (accuracy top-1/top-3, per category, per confidence
  level, confusions, probably misclassified tickets).
- Knowledge base article drafted from a solved ticket, created unpublished and linked to it.
- AI guidelines per ITIL category (optionally inherited by sub-categories).

### Providers
- OpenAI, Azure OpenAI (deployments and v1 API), Anthropic, Google Gemini, Ollama, OpenRouter,
  Mistral and any OpenAI-compatible API (LM Studio, vLLM, LiteLLM, Groq, DeepSeek…).
- Reasoning and standard models, strict JSON schema / JSON mode / prompt only, model listing,
  connection test, GLPI proxy support (local and private addresses reached directly).
- Outbound calls limited to http(s), cloud metadata addresses refused, no redirects followed,
  optional allowlist with the `TICKETASSISTANT_URL_ALLOWLIST` constant.

### Governance
- Guard rails: daily budget of technicians, separate daily budget of automatic actions, hourly
  limit per technician, interval per ticket.
- Audit log with tokens, cost, duration, provider and model; feedback 👍/👎 and use of drafts
  (a rewritten text counts as used when its followup or solution is submitted); statistics;
  retention policy (automatic action).
- Masking of secrets written as "password: …", payment cards (Luhn), IBAN, CPF, CNPJ, e-mail,
  phone numbers, IPv4 and custom patterns.
- Private followups and tasks follow the rights of the technician by default.

### Installation and operations
- Interactive installers for Linux (`install.sh`) and Windows (`install.ps1`, PowerShell 5.1 and 7):
  detection of the GLPI installations, of a compatible PHP and of the web server user, copy or link,
  installation with the GLPI console, AI provider with the list of its models and a connection test,
  library of solved tickets, cron or scheduled task, poppler; update, configuration, status and
  uninstallation menu; unattended mode; English and Portuguese.
- Console commands `plugins:ticketassistant:configure` (every setting, API key through the standard
  input, model list, connection test) and `plugins:ticketassistant:status` (with a machine-readable
  output for monitoring), besides `sync`, `learn`, `evaluate` and `demo`.

### Platform
- One code base for GLPI 10.0.11+, 11.0 and 12.0, PHP 7.4+.
- Translations: English, Brazilian Portuguese, Spanish, French.
- The PHP session is released during AI calls: other GLPI tabs keep working.
- Tests: unit tests on PHP 7.4 to 8.5, installer tests on Linux and Windows, Docker environment with
  GLPI 10, 11 and 12 and a mock AI provider (scripted answers and simulated latency for
  demonstrations).
