# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Use the private
[security advisory form](https://github.com/flpsantoro/glpi-ai-ticket-assistant/security/advisories/new)
of the repository, with the affected version, the steps to reproduce and the impact.

You will receive an answer within a few days. Fixes are released as a new patch version and
credited in the changelog, unless you prefer to stay anonymous.

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Scope reminders

- Never paste API keys, database dumps, `glpicrypt.key` or real ticket content in issues or
  pull requests. If a key was exposed, revoke it at the provider first.
- What the plugin sends to the AI provider, what is masked and the residual risks are described
  in [docs/SECURITY-AND-PRIVACY.md](docs/SECURITY-AND-PRIVACY.md).
