# Development

- [Architecture](#architecture)
- [GLPI 10, 11 and 12 from one code base](#glpi-10-11-and-12-from-one-code-base)
- [Development environment](#development-environment)
- [Tests](#tests)
- [Translations](#translations)
- [End-to-end checklist](#end-to-end-checklist)
- [Release](#release)

## Architecture

```text
setup.php, hook.php          hooks, requirements, installation (tables, rights, automatic actions)
src/
  Assistant.php              entry point of the actions: analyze, improve, classify, apply, article…
  TicketContext.php          what the AI reads: header, timeline within a budget, private policy
  Attachments.php            attachments of the ticket and of its timeline -> images, texts, files
  DocumentReader.php         images (GD), PDF (poppler or PdfText), Office/OpenDocument, text files
  PdfText.php                pure PHP text extraction of digital PDFs
  Privacy.php                masking detectors
  Prompt.php                 system prompts, JSON schemas, languages, style guides
  ResponseParser.php         JSON decoding, Markdown -> HTML, HTML allowlist
  Llm/                       Client (HTTP, retries, proxy, URL guard), drivers (OpenAI-compatible,
                             Azure, Anthropic), provider presets
  Knowledge/                 CaseLibrary (library and search), Similarity (cosine of the words),
                             Embeddings, Classifier (k-NN and evaluation), Suggester (k-NN + AI),
                             Learner (distillation), Triage
  Config.php, ConfigForm.php settings and configuration page
  Diagnostics.php            connection test, model list, automatic actions (page and console)
  TicketHooks.php            buttons on the ticket form, "used" tracking of rewritten texts
  Log.php, Stats.php         audit log, guard rails, statistics page
  KnowledgePage.php          "Learning from solved tickets" page
  CategoryGuideline.php      "AI assistant" tab of ITIL categories
  Profile.php, Menu.php      right and menu
  Console/                   plugins:ticketassistant:* commands (auto-discovered)
  Compat.php                 every GLPI-version-dependent call
front/, ajax/                pages and JSON endpoints
public/js, public/css        ticket script (modal, editor integration), configuration script
locales/                     gettext catalogs (.pot, .po, .mo)
install.sh, install.ps1      interactive installers (Linux, Windows)
tools/                       mock AI provider, Docker environment, translation tool
tests/                       PHPUnit tests, installer tests with a fake GLPI console
```

How an analysis flows:

1. `public/js/assistant.js` posts `action=analyze` to `ajax/assistant.php`, which checks the right,
   the ticket visibility and the CSRF token, releases the PHP session and calls `Assistant::handle()`.
2. Guard rails (budgets, hourly limit, cooldown) and the cache (unchanged ticket) are checked.
3. `TicketContext` builds the text and `Attachments` the images, texts and files; `Privacy` masks
   the text; `CaseLibrary::search()` adds the similar solved tickets.
4. `Prompt` builds the system prompt and the JSON schema; `Llm\Client` calls the provider with
   retries; `ResponseParser` validates and sanitizes the answer.
5. `Log::record()` stores tokens, cost and duration; the JSON answer goes back to the modal.

## GLPI 10, 11 and 12 from one code base

Rules verified in the source of GLPI 10.0.28, 11.0.11 and 12.0.0 (all of them go through
`src/Compat.php`):

- **Request data**: GLPI 10 sanitizes `$_POST` (raw copy in `$_UPOST`) and stores HTML-encoded
  values; 11 and 12 keep raw values. Use `Compat::rawPost()`, `Compat::itemInput()`,
  `Compat::decodeStoredValue()`. `Glpi\Toolbox\Sanitizer` is deprecated in 11 and removed in 12.
- **Database**: `$DB->insert()`/`update()` do not escape on 10 but do on 11+ (escaping twice
  corrupts data): `Compat::dbValue()`. Raw SQL with `doQuery()` (10.0.11+), always quoted with
  `Compat::quote()`. `QueryExpression` is global on 10 and `Glpi\DBAL\QueryExpression` on 11+:
  `Compat::expr()`.
- **Logging**: `Toolbox::logError()` is gone in 11: `Compat::log()` uses `Toolbox::logInFile()`.
- **Assets**: GLPI 11+ serves plugin files only from `public/`; the hook path is `public/js/x.js` on
  10 and `js/x.js` on 11+ (`Compat::assetHookPath()`); `Plugin::getWebDir()` is removed in 12
  (`Compat::url()`).
- **Hooks**: `csrf_compliant` is required on 10; `timeline_actions` renders the button on every
  version; since GLPI 11.0.9 the answer forms load lazily, so the script waits for the TinyMCE editor.
- **Typed properties**: GLPI 12 types `CommonGLPI::$rightname` as `string`: plugin classes must not
  redeclare it (override `canView()`/`canUpdate()` instead).
- **Init**: `plugin_init_ticketassistant()` must never throw (GLPI 11 disables the plugin).
- **Syntax**: PHP 7.4 (GLPI 10 still runs on it): no `match`, union types, named arguments or
  nullsafe operator.

## Development environment

Everything runs locally, without a key and without cost.

```bash
# Mock AI provider (Python 3.8+, standard library only)
python tools/mock-llm/server.py --port 8099 --log tools/mock-llm/requests.jsonl

# GLPI with the plugin mounted from this repository: one version (lighter) or all of them
docker compose -f tools/docker/docker-compose.yml --profile glpi11 up -d
docker compose -f tools/docker/docker-compose.yml --profile all up -d
```

GLPI 10 answers on <http://localhost:8010>, 11 on <http://localhost:8011>, 12 on
<http://localhost:8012> (`glpi` / `glpi`). Containers are not restarted automatically and their
memory is capped; stop them with `--profile all stop`.

Install, configure and fill a test instance from the command line:

```bash
alias glpi11='docker compose -f tools/docker/docker-compose.yml exec -u www-data glpi11 php /var/www/glpi/bin/console'
glpi11 plugin:install --username=glpi ticketassistant && glpi11 plugin:activate ticketassistant
glpi11 plugins:ticketassistant:configure --provider=compatible --base-url=http://host.docker.internal:8099/v1 --model=mock-vision-1 --enable --test
glpi11 plugins:ticketassistant:demo --yes
glpi11 plugins:ticketassistant:sync
```

The demonstration data: an entity "AI Ticket Assistant demo", 12 categories (4 groups and 8
sub-categories), 4 users (without password), 40 solved tickets spread over the last months, and 3
open tickets — a VPN error with a screenshot, a PDF notice, a log file and a private task; a printer
problem; an access request without category.

The mock answers every action with recognizable `[mock]` texts that echo what it received (number of
images, documents read, similar tickets), and simulates failures when a ticket contains
`MOCK_ERROR_429`, `MOCK_ERROR_500`, `MOCK_CONTENT_FILTER`, `MOCK_BAD_JSON` or `MOCK_SLOW`. With
`--log`, every request is written to a JSONL file (images shortened): the best way to see exactly
what the plugin sends. With `--scripted`, the 3 open demonstration tickets get written, realistic
answers (scripted, not generated: for demonstrations and screenshots), and `--latency 3` makes
every chat answer wait 3 seconds, like a real model, to see the loading states.

## Tests

```bash
composer install && vendor/bin/phpunit                         # unit tests (PHP 7.4+)
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
bash tests/installer/installer-test.sh                         # install.sh against a fake GLPI
powershell -File tests\installer\installer-test.ps1 -PhpExe C:\php\php.exe   # install.ps1
node --check public/js/assistant.js public/js/config.js
```

- **Unit tests** (`tests/Unit`) cover what does not need a database: masking, response parsing and
  sanitizing, HTTP client and drivers, text helpers, word similarity, PDF extraction (synthetic
  PDFs: simple and composite fonts, ToUnicode maps, object streams, form XObjects, inline images,
  incremental updates, encrypted and invalid files).
- **Installer tests** run the installers unattended and drive their menus with scripted key presses,
  against `tests/installer/fake-glpi`, a fake console that records every call (and flags an API key
  passed on a command line).
- **CI** (`.github/workflows/ci.yml`): syntax on PHP 7.4 to 8.4, unit tests on 7.4 and 8.3,
  installers on Ubuntu and Windows (PowerShell 5.1 and 7), JavaScript syntax, translation catalogs up
  to date and complete.

## Translations

Strings are written in English in the code (`__('…', 'ticketassistant')`). The tool
`tools/i18n/i18n.py` needs only Python:

```bash
python tools/i18n/i18n.py extract   # code -> locales/ticketassistant.pot
python tools/i18n/i18n.py update    # .pot -> locales/*.po (new strings empty)
python tools/i18n/i18n.py check     # untranslated or fuzzy entries, placeholders
python tools/i18n/i18n.py compile   # .po -> .mo
```

To add a language, copy `locales/pt_BR.po` to `locales/xx_XX.po`, translate, compile, and add it to
`plugin.xml`.

## End-to-end checklist

On GLPI 10, 11 and 12, with the mock provider and the demo data:

1. Install and enable the plugin; configuration page shows **ACTIVE** after configuring; connection
   test and model list work.
2. Ticket "VPN error 809": analysis lists the screenshot, the PDF and the log file; the private task
   appears only for a profile allowed to see it; similar solved tickets are linked.
3. Reply tab: **Insert as followup** and **Insert as solution** fill the editors (lazy forms on
   11.0.9+); **Improve my text** in each mode.
4. Ticket without category: classification, **Apply to the ticket**, **Dismiss**.
5. Closed ticket: knowledge base article, draft created unpublished and linked.
6. Learning page: synchronize, learn (distillation and embeddings enabled), evaluate.
7. New ticket with automatic triage: suggestion button after `php front/cron.php --force aitriage`.
8. Statistics page; feedback 👍/👎; draft "used" after submitting a rewritten followup.
9. ITIL category: AI guidelines saved with quotes, accents and `<`, and found in the prompt
   (mock log) of a ticket of a sub-category.
10. Profile tab: removing the right hides the buttons.
11. No PHP warning in `files/_log/php-errors.log`.

## Release

1. Update `PLUGIN_TICKETASSISTANT_VERSION` in `setup.php`, the `<version>` block of `plugin.xml`
   and `CHANGELOG.md`.
2. Run the tests and the end-to-end checklist.
3. Tag `vX.Y.Z` and push the tag: the release workflow checks the version, builds
   `ticketassistant-X.Y.Z.tar.bz2` and `.zip` with `git archive` (files marked `export-ignore` in
   `.gitattributes` are left out) and attaches them with their SHA-256 sums to a GitHub release,
   whose notes are the section of the version in `CHANGELOG.md`.
