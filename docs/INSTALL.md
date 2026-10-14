# Installation

- [Requirements](#requirements)
- [Installer for Linux](#installer-for-linux)
- [Installer for Windows](#installer-for-windows)
- [Manual installation](#manual-installation)
- [Automatic actions (cron)](#automatic-actions-cron)
- [Rights](#rights)
- [Upgrade](#upgrade)
- [Uninstall](#uninstall)
- [Troubleshooting](#troubleshooting)

## Requirements

| | Required | Recommended |
|---|---|---|
| GLPI | 10.0.11 or later, 11.0, 12.0 | the latest maintenance release |
| PHP | the version required by your GLPI (the plugin itself runs on 7.4 to 8.5) | |
| PHP extensions | `curl`, `mbstring`, `json`, `zlib` (all required by GLPI too) | `gd` (downscales large images), `zip` (Word, Excel, PowerPoint, OpenDocument files) |
| Tools | | `poppler-utils` (`pdftotext`, `pdftoppm`) with `shell_exec` allowed |
| Network | HTTPS from the GLPI server to the AI provider, directly or through the proxy of GLPI | |
| Automatic actions | GLPI cron in CLI mode | |

Without poppler-utils a built-in reader extracts the text of digital PDFs (office documents,
browsers, scanners with OCR); scanned PDFs without a text layer then need `pdftoppm` or a provider
that reads PDF files natively (OpenAI, Anthropic: option "Send the PDF file itself").

The extensions must be enabled for the PHP of the **web server** (Apache module, PHP-FPM, IIS
FastCGI), not only for the command line. The **Diagnosis** tab of the configuration page shows what
the web server sees.

## Installer for Linux

From the cloned repository (or an extracted release archive):

```bash
sudo bash install.sh
```

The installer is a terminal wizard (arrow keys, Enter, type-to-filter lists, hidden input for the
key) in English or Portuguese, according to `LANG` or `--lang`. It never changes anything without
asking, and each step can be skipped:

1. **System**: PHP binaries (the one in the `PATH`, `/usr/bin/php8.x`, Remi collections…), PHP
   extensions, copy tool.
2. **GLPI**: searches the usual folders (`/var/www`, `/srv`, `/opt`, `/usr/share/glpi`, home
   folders) and the document roots declared in Apache and nginx, shows each GLPI with its version and
   the version of the plugin already in it. It picks a PHP able to run that GLPI (GLPI 11 needs PHP
   8.2) and the web server user (owner of the GLPI files, `GLPI_VAR_DIR` included).
3. **Plugin**: copies the plugin (only the files of the plugin: no tests, documentation or tools)
   or links it to the repository (development: a `git pull` updates GLPI). The previous version is
   saved in `/var/backups/glpi-ticketassistant/` first. The GLPI console then installs and enables
   the plugin **as the web server user**, so that GLPI's cache files keep the right owner. On SELinux
   systems the security context is restored.
4. **AI**: menu of the providers, address when relevant (Azure resource, Ollama, local servers),
   key typed hidden (it reaches GLPI through the standard input, never on a command line), list of
   the models offered by the provider with a filter, connection test, then optional language,
   organization name, token prices and daily budget.
5. **Library**: reads the solved and closed tickets (no AI call, no cost) so that the suggestions
   work immediately.
6. **Cron and PDF**: creates `/etc/cron.d/glpi-cron` when no cron runs GLPI's automatic actions,
   installs `poppler-utils` with the package manager (apt, dnf, yum, zypper, apk, pacman), and can
   create demonstration data on a test instance.

Run it again at any time: when the plugin is already in the chosen GLPI, a menu offers **update**,
**configure the AI provider**, **status and connection test**, **synchronize the library** and
**uninstall**.

The full log goes to `/tmp/ticketassistant-install-<date>.log` (readable by root only). It
contains the commands and their output, never the API key.

### Unattended mode

```bash
OPENAI_API_KEY=sk-... sudo -E bash install.sh --yes --glpi /var/www/glpi \
    --provider openai --model gpt-5-mini --api-key-env OPENAI_API_KEY --cron yes --poppler yes
```

| Option | Meaning |
|---|---|
| `--glpi PATH` | GLPI folder (default: detected; required when several are found) |
| `--php PATH` | PHP binary that runs the GLPI console |
| `--web-user USER` | owner of the GLPI files |
| `--glpi-user LOGIN` | GLPI super-admin login used by the installation (default `glpi`) |
| `--mode copy\|link` | copy the plugin (default) or link it to the repository |
| `--provider ID` | `openai`, `azure`, `anthropic`, `gemini`, `mistral`, `openrouter`, `ollama`, `compatible` |
| `--base-url URL`, `--model NAME`, `--api-version VER` | connection settings |
| `--api-key-env VAR` | environment variable holding the API key |
| `--skip-ai` | do not configure the provider |
| `--sync yes\|no` | build the library of solved tickets (default yes) |
| `--cron yes\|no` | create `/etc/cron.d/glpi-cron` when missing |
| `--poppler yes\|no` | install poppler-utils when missing |
| `--demo` | demonstration data (test instances only) |
| `--status`, `--configure`, `--uninstall` | run only that part |
| `--lang en\|pt`, `--no-color`, `-y/--yes` | interface |

## Installer for Windows

In PowerShell (Windows PowerShell 5.1, built in, or PowerShell 7), from the cloned repository:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

Same steps as on Linux, adapted to Windows:

- GLPI is searched in XAMPP, WAMP, Laragon, IIS (sites and virtual directories of
  `applicationHost.config`), Apache 2.4 and the usual web folders of every fixed drive.
- The PHP next to GLPI is preferred (the PHP of the same XAMPP, WAMP or Laragon), then the one in
  the `PATH`, scoop, Chocolatey and the PHP used by IIS.
- Copy with `robocopy`, or link with a directory junction (no administrator right needed).
  Backups go to `%ProgramData%\glpi-ticketassistant\backups`.
- The cron becomes a scheduled task **GLPI cron** running `php.exe front\cron.php` every minute
  as SYSTEM.
- poppler is searched (scoop, Chocolatey, `C:\poppler*`, the `PATH`); its folder is saved in the
  configuration because a web server running as a service does not see the `PATH` of your session.
- When a folder needs it (`C:\inetpub`, `Program Files`) or for the scheduled task, the installer
  offers to restart itself as administrator.

Options: `-GlpiPath`, `-Php`, `-Mode Copy|Link`, `-GlpiUser`, `-Provider`, `-BaseUrl`, `-Model`,
`-ApiVersion`, `-ApiKeyEnv`, `-SkipAi`, `-Sync yes|no`, `-Cron yes|no`, `-Demo`, `-Status`,
`-Configure`, `-Uninstall`, `-Lang en|pt`, `-Yes`, `-NoColor`, `-Help`.

```powershell
$env:OPENAI_API_KEY = 'sk-...'
.\install.ps1 -Yes -GlpiPath C:\xampp\htdocs\glpi -Provider openai -Model gpt-5-mini -ApiKeyEnv OPENAI_API_KEY -Cron yes
```

## Manual installation

1. Get the plugin:
   - release archive `ticketassistant-x.y.z.tar.bz2` (or `.zip`) extracted in `plugins/`, or
   - `git clone` then copy the folder to `plugins/ticketassistant` (the folder **must** be named
     `ticketassistant`).
2. Give the files to the web server user, e.g. `chown -R www-data: plugins/ticketassistant`.
3. Install and enable: **Setup > Plugins** in GLPI, or as the web server user:
   ```bash
   sudo -u www-data php bin/console plugin:install --username=glpi ticketassistant
   sudo -u www-data php bin/console plugin:activate ticketassistant
   ```
4. Configure in **Setup > AI Ticket Assistant** (see [CONFIGURATION.md](CONFIGURATION.md)) or with
   the console:
   ```bash
   printf '%s' "$KEY" | sudo -u www-data php bin/console plugins:ticketassistant:configure \
       --provider=openai --model=gpt-5-mini --api-key-stdin --enable --test
   ```

## Automatic actions (cron)

| Action | Frequency | What it does |
|---|---|---|
| `aisync` | daily | synchronizes the library of solved tickets (no AI call) |
| `ailearn` | hourly | AI distillation and embeddings of the new cases, when enabled, within the daily budget of automatic actions |
| `aitriage` | every 5 minutes | classifies the tickets queued at creation, when automatic triage is enabled |
| `aipurge` | daily | applies the retention periods of the log |

They are registered in CLI mode: GLPI runs them only when its cron runs from the command line.

- Linux: `* * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php` in `/etc/cron.d/glpi-cron`.
- Windows: scheduled task every minute running `php.exe C:\...\glpi\front\cron.php`.
- Docker: a cron in the container, or `docker exec -u www-data <container> php front/cron.php`
  from the host.

Check **Setup > Automatic actions** (filter on "AI Ticket Assistant"): the last run date must
move. They can also be run by hand: `php front/cron.php --force aisync`.

## Rights

The right **AI assistant** (Administration > Profiles > tab "AI assistant") shows the buttons on
tickets. On the first installation it is given to the profiles of the standard interface that can
update tickets or add followups to the tickets of others; later reinstallations keep your choices.

| Page or action | Required right |
|---|---|
| Buttons on tickets | AI assistant (read) and the right to see the ticket |
| Apply a classification | update the ticket |
| Create a knowledge base article | create knowledge base articles |
| Private followups and tasks in the context | see private followups / tasks (default policy) |
| Configuration page, connection test | update the general setup |
| Learning and Statistics pages | read the general setup |
| AI guidelines of a category | update ITIL categories |

## Upgrade

- With the installer: run it again and choose **Update**. The previous version is saved first.
- By hand: replace the files of `plugins/ticketassistant`, then in **Setup > Plugins** click
  **Update** (or `php bin/console plugin:install --force --username=glpi ticketassistant` and
  `plugin:activate`). Data and configuration are kept; new settings get their default value.

## Uninstall

The installer (**Uninstall**) or **Setup > Plugins > Uninstall** deletes the tables of the plugin
(log, library, suggestions, AI guidelines), its configuration including the API keys, its rights
and its automatic actions. Tickets, followups and knowledge base articles created with the
assistant are ordinary GLPI items and stay.

## Troubleshooting

| Symptom | Check |
|---|---|
| No "AI assistant" button on tickets | plugin enabled; badge **ACTIVE** on the configuration page (assistant enabled and connection complete); right **AI assistant** in the profile; closed tickets only offer the article |
| "Improve my text" missing in the answer form | action enabled in the **Actions** tab; GLPI 11.0.9+ loads the forms lazily: wait for the editor |
| Connection test fails | message of the provider shown next to the button; the address must be reachable **from the GLPI server**; proxy settings of GLPI; see [OPERATIONS.md](OPERATIONS.md#errors) |
| "Outbound URL refused" | `TICKETASSISTANT_URL_ALLOWLIST` is defined and does not match the address, or the address is a cloud metadata endpoint |
| PDF documents ignored | **Diagnosis** tab: `pdftotext`/`pdftoppm`, `shell_exec`; the built-in reader handles digital PDFs only |
| Command "plugins:ticketassistant:…" is not defined | the plugin is not enabled: `plugin:activate ticketassistant` |
| The console refuses to run as root | run it as the web server user (`sudo -u www-data php bin/console …`) |
| Automatic actions never run | GLPI cron in CLI mode (see above) |
