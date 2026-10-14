# Contributing

Thank you for helping! Issues and pull requests are welcome, in English or Portuguese.

## Development environment

Everything runs locally, without an AI key and without cost:

```bash
# 1. Mock AI provider (Python 3.8+, standard library only)
python tools/mock-llm/server.py --port 8099 --log tools/mock-llm/requests.jsonl

# 2. GLPI with the plugin mounted from this repository (glpi10, glpi11, glpi12 or all)
docker compose -f tools/docker/docker-compose.yml --profile glpi11 up -d
```

GLPI 10 answers on <http://localhost:8010>, GLPI 11 on <http://localhost:8011> and GLPI 12 on
<http://localhost:8012> (default accounts `glpi` / `glpi`). Install the plugin, then configure the
provider **Other OpenAI-compatible API** with the address `http://host.docker.internal:8099/v1`
and any model name — or do it all from the command line, see
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md#development-environment). The mock answers every action
and can simulate errors: put `MOCK_ERROR_429`, `MOCK_ERROR_500`, `MOCK_CONTENT_FILTER`,
`MOCK_BAD_JSON` or `MOCK_SLOW` in a ticket.

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for the architecture and the GLPI 10/11/12
compatibility rules.

## Before opening a pull request

```bash
composer install && vendor/bin/phpunit          # unit tests (PHP 7.4+)
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
bash tests/installer/installer-test.sh          # when install.sh changed
python tools/i18n/i18n.py extract               # code -> locales/ticketassistant.pot
python tools/i18n/i18n.py update                # new strings -> locales/*.po
python tools/i18n/i18n.py compile               # .po -> .mo
```

On Windows, test `install.ps1` with
`powershell -File tests\installer\installer-test.ps1 -PhpExe C:\path\php.exe` (keep the file in
UTF-8 **with** byte order mark: Windows PowerShell 5.1 needs it for the accents and box characters).

Rules of the code base:

- One code base for GLPI 10.0.11+, 11 and 12: every version-dependent call goes through
  `src/Compat.php`.
- PHP 7.4 syntax (GLPI 10 still runs on it): no `match`, named arguments, union types or
  `?->`.
- Every user-facing string goes through `__('…', 'ticketassistant')`, in English.
- Nothing is ever sent to the requester automatically.
- Never log or display API keys; pass them to commands through the standard input.
