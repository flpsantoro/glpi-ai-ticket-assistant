# Operations

- [Automatic actions](#automatic-actions)
- [Console commands](#console-commands)
- [Costs, budgets and limits](#costs-budgets-and-limits)
- [Statistics](#statistics)
- [Logs](#logs)
- [Errors](#errors)
- [Monitoring](#monitoring)
- [Backup](#backup)
- [Performance](#performance)

## Automatic actions

| Action | Default frequency | Parameter | What it does |
|---|---|---|---|
| `aisync` | daily | | synchronizes the library of solved tickets (no AI call) |
| `ailearn` | hourly | tickets per run (0 = **Tickets per run** of the configuration) | distillation and embeddings of new cases, when enabled |
| `aitriage` | 5 minutes | tickets per run (50) | suggestions for the tickets queued at creation |
| `aipurge` | daily | | retention periods of the log |

They run in CLI mode only (GLPI cron from the command line, see
[INSTALL.md](INSTALL.md#automatic-actions-cron)); frequency and state can be changed in **Setup >
Automatic actions**. Run one now with `php front/cron.php --force aisync`.

## Console commands

Run them as the web server user, from the GLPI folder (`sudo -u www-data php bin/console …`).

| Command | Options | Purpose |
|---|---|---|
| `plugins:ticketassistant:status` | `--porcelain` | state: readiness, connection, library, automatic actions, cost of the day |
| `plugins:ticketassistant:configure` | `--provider`, `--base-url`, `--model`, `--api-version`, `--api-key-stdin`, `--clear-api-key`, `--enable`, `--disable`, `--set=key=value` (repeatable), `--show`, `--list-models`, `--test` | configuration without the web interface (installers, automation) |
| `plugins:ticketassistant:sync` | `--full` | synchronizes the library now |
| `plugins:ticketassistant:learn` | `--limit=50` | distillation and embeddings now, within the budget of automatic actions |
| `plugins:ticketassistant:evaluate` | `--sample=200`, `--embeddings` | accuracy of the classification on the history (no AI call) |
| `plugins:ticketassistant:demo` | `--username=glpi`, `--yes` | demonstration data — **test instances only** |

The API key is read from the standard input (piped, or asked hidden in a terminal), so that it does
not appear in the process list or the shell history:

```bash
printf '%s' "$KEY" | sudo -u www-data php bin/console plugins:ticketassistant:configure --api-key-stdin --test
```

## Costs, budgets and limits

Each call is logged with the tokens reported by the provider and its **estimated** cost, computed
with the prices of the **Costs and limits** tab. Keep them in line with the price list of your model;
with prices at 0 (local models) costs are 0 and only the hourly limit applies.

| Guard rail | Applies to | When reached |
|---|---|---|
| Daily budget of the technicians | analyses, rewrites, classifications, articles (all technicians together, since midnight) | buttons answer "daily budget exhausted" until midnight; the free classification vote still works |
| Uses per technician per hour | the same actions, per technician | "limit of N uses per hour" |
| Interval between two analyses of a ticket | analyses of the same ticket | the last analysis is shown again |
| Daily budget of automatic actions | distillation, embeddings, triage with the AI | they stop until the next day |

A ticket that did not change since its last analysis (less than 8 hours ago) shows that analysis
again, at no cost; **Generate again** forces a new one.

## Statistics

**Setup > AI Ticket Assistant > Statistics** (right: read the general setup):

- generations today, in 7 and 30 days, with cost, average duration, blocked calls and errors;
- generations per day, per action, per result (ok, blocked, errors) and per model;
- **quality**: share of drafts used (reply drafts inserted or copied, rewritten texts submitted with
  their followup or solution), 👍 and 👎 per action;
- **classification**: suggestions applied by technicians, applied automatically, dismissed, and the
  accuracy measured once the tickets are solved;
- the categories most analyzed.

## Logs

- **Log of calls**: table `glpi_plugin_ticketassistant_logs` (user, ticket, action, mode, provider,
  model, HTTP code, tokens, cost, duration, status, error message, feedback, use, and the generated
  text when **Keep the generated texts** is on). Retention: **Privacy** tab.
- **Technical errors** of the plugin (exceptions, failed hooks): `files/_log/ticketassistant.log` of
  GLPI.
- **Installers**: `/tmp/ticketassistant-install-<date>.log` (Linux) or
  `%TEMP%\ticketassistant-install-<date>.log` (Windows), never with the API key.

## Errors

What technicians see, and what to do:

| Status in the log | Message | Cause and fix |
|---|---|---|
| `error_auth` | the AI service refused the credentials | wrong or revoked key, missing permission on the model, firewall: test the connection |
| `error_notfound` | model or endpoint not found | model name (Azure: deployment name), address, API version |
| `error_quota` | quota or credit exhausted | billing of the provider account |
| `error_rate_limit` | too many requests | retried automatically with the `Retry-After` of the provider; raise the limits of the account or lower the use |
| `error_timeout` | took too long | **Timeout**, a lighter model or reasoning effort, a smaller context |
| `error_network` | could not connect | address reachable from the GLPI server, DNS, proxy of GLPI, `TICKETASSISTANT_URL_ALLOWLIST` |
| `error_content_filter` | blocked by the content filter | the provider refused the content (Azure content safety…); proceed manually |
| `error_badresponse` | invalid answer | the model did not follow the JSON format: use **JSON answers = strict schema / JSON mode**, a larger output limit, or a stronger model |
| `error_config` / `error_unsupported` | not correctly configured | missing setting, feature not available for the provider |
| `error_http` | the AI service returned an error | message of the provider in the log |
| `blocked_budget` | daily budget exhausted | **Costs and limits** |
| `blocked_rate` | hourly limit reached | **Uses per technician per hour** |
| `blocked_cooldown` | (last analysis shown) | **Interval between two analyses** |
| `error_internal` | internal error of the assistant | `files/_log/ticketassistant.log` |

## Monitoring

`plugins:ticketassistant:status --porcelain` prints one `key=value` per line (`ready`, `enabled`,
`provider`, `model`, `api_key`, `missing`, `cases`, `last_sync`, `cost_today_usd`,
`cron_<action>=state,mode,last run`…), easy to check from a monitoring agent:

```bash
sudo -u www-data php bin/console plugins:ticketassistant:status --porcelain | grep -q '^ready=1$' || echo "AI assistant not ready"
```

## Backup

Everything is in the GLPI database (tables `glpi_plugin_ticketassistant_*` and the configuration
in `glpi_configs`). The API keys are encrypted with `config/glpicrypt.key`: restore that file with
the database, or enter the keys again.

## Performance

- AI calls run while the PHP session is released: other GLPI tabs of the technician keep working.
- An analysis usually takes 5 to 40 seconds depending on the model, the reasoning effort and the
  attachments; the web server and PHP time limits must be above **Timeout** (the plugin raises
  `max_execution_time` for its requests when allowed).
- The library uses a FULLTEXT index: searches stay fast with hundreds of thousands of tickets. With
  embeddings, each search also compares the **Recent tickets scanned** (3000 by default) in PHP;
  lower it on very busy servers.
- Distillation and embeddings run in the background, in batches, within their own budget.
