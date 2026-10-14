# Security and privacy

Sending tickets to an AI provider means sending the problems, sometimes the personal data, of the
people your service desk helps. This page describes what leaves the server, what is protected and
what is not, so that you can decide with your data protection officer. To report a vulnerability,
see [SECURITY.md](../SECURITY.md).

- [Principles](#principles)
- [What is sent, per action](#what-is-sent-per-action)
- [Masking](#masking)
- [Private followups and tasks](#private-followups-and-tasks)
- [What is stored](#what-is-stored)
- [Access control](#access-control)
- [Untrusted content and AI output](#untrusted-content-and-ai-output)
- [Network](#network)
- [GDPR, LGPD and similar laws](#gdpr-lgpd-and-similar-laws)

## Principles

- **Human in the loop**: every text is a draft that a technician reads, edits and sends; the AI
  never writes to the requester. The only automatic change possible is the classification of new
  tickets, off by default, limited to the categories GLPI allows.
- **On demand**: nothing is sent until a technician clicks a button, except the optional automatic
  actions (distillation, embeddings, triage), which have their own switch and daily budget.
- **Minimization**: limits on the context, the number of attachments and their size; requester's
  first name only (optional), never logins, e-mail addresses or phone numbers from GLPI's fields.
- **Local option**: with Ollama or another local server nothing leaves your network.

## What is sent, per action

| Action | Sent to the provider |
|---|---|
| Analyze the ticket | title, description, followups, tasks and solutions (newest first, within **Context size**), type, category, status, priority, source, assigned group, linked assets, approval status; the attachments within the limits (images, extracted text of PDF and documents, or the PDF file itself if enabled); up to **Similar tickets** solved tickets (masked) with their solution; solution templates of the entity; guidelines of the category; the requester's first name if enabled |
| Improve my text | the draft of the technician, the title of the ticket, the requester's first name if enabled |
| Classification | title and description, the allowed categories (name and description), similar solved tickets with their category |
| Knowledge base article | the ticket and its solution |
| Distillation (background) | the problem and the solution of a solved ticket, as stored in the library (masked) |
| Embeddings | the problem text (and AI summary) of a case, or the text of the ticket being analyzed |
| Connection test | the sentence "Reply with the single word: pong" |

## Masking

Before sending, text goes through the detectors selected in the **Privacy** tab. They replace the
match with a label such as `[EMAIL]`. The library of solved tickets is masked when it is built, and
the `secrets` detector is always applied to it.

| Detector | Matches | Limits |
|---|---|---|
| `secrets` | the value after "password", "senha", "contraseña", "mot de passe", "token", "API key", "secret", "PIN", "OTP", "chave de acesso"… followed by `:` or `=` (up to two words in between) | a password written without such a keyword ("my password is X1y2", "use X1y2") is **not** detected; a sentence such as "no password: granted" becomes "no password: [SECRET]" — the detector prefers hiding a word too many |
| `email` | e-mail addresses | |
| `card` | 13 to 19 digits passing the Luhn check | |
| `iban` | IBAN with a valid checksum | |
| `cpf` | 11 digits shaped like a CPF, with or without punctuation | no check digit validation, on purpose: a mistyped CPF is still hidden, and so are other 11-digit numbers (and the first 11 digits of a longer number, which forms often glue to the CPF) |
| `cnpj` | 14 digits shaped like a CNPJ | same choice as CPF |
| `phone` | international and national formats with separators | a phone number written as a plain run of digits may be missed (or caught by `cpf`) |
| `ipv4` | IPv4 addresses | IPv6 is not masked |
| custom | your patterns, one per line: `LABEL=/regular expression/flags` | e.g. `EMPLOYEE_ID=/\bEMP-\d{6}\b/`, `PLATE=/\b[A-Z]{3}-?\d[A-Z0-9]\d{2}\b/` |

What masking does **not** cover:

- **names, addresses and any other free text** are sent as written (add custom patterns for
  identifiers specific to your organization);
- **images** (screenshots, photos, scanned pages) and PDF files sent natively are not masked:
  disable them if your policy requires it;
- detection is pattern-based: test it on real tickets (the **Statistics** page shows the analyses,
  and with **Keep the generated texts** the log keeps what the AI answered, not what was sent).

## Private followups and tasks

**Private followups and tasks** (Context tab):

- `rights` (default): included only when the technician who clicks may see private followups or
  tasks; they are flagged as internal notes, and the AI is told never to quote them in the reply to
  the requester;
- `all`: always included, flagged;
- `none`: never sent.

The cache of analyses respects this: an analysis that included private items is not shown again to
someone who cannot see them.

## What is stored

| Data | Where | Protection |
|---|---|---|
| API keys | `glpi_configs` | encrypted with the key of the GLPI installation (`glpicrypt.key`), never displayed again |
| Log of calls | `glpi_plugin_ticketassistant_logs` | user, ticket, action, model, tokens, cost, duration, status, feedback; the generated text only if **Keep the generated texts** is on, purged after **Keep generated texts (days)**; rows purged after **Keep log rows (days)** (automatic action `aipurge`) |
| Library of solved tickets | `glpi_plugin_ticketassistant_cases` | masked problem and solution, labels, AI summary and embedding |
| Suggestions | `glpi_plugin_ticketassistant_suggestions` | suggested type/category/urgency and what happened to them |
| Category guidelines | `glpi_plugin_ticketassistant_categoryguidelines` | text written by administrators |

The text sent to the provider is not stored by the plugin. Uninstalling the plugin deletes all of
the above.

## Access control

- Buttons and endpoints require the right **AI assistant** and the right to see the ticket; applying
  a classification requires the right to update it, creating an article the right to create
  knowledge base articles. The checks run on the server for every request.
- The configuration, the connection test and the model list require the right to update the
  general setup; the Learning and Statistics pages the right to read it.
- Requests use GLPI's session and CSRF protection; endpoints only accept POST.
- Guard rails: daily budget of the technicians, hourly limit per technician, interval between two
  analyses of a ticket, separate daily budget of automatic actions.

## Untrusted content and AI output

Ticket content is written by requesters and may try to steer the AI ("ignore your instructions…").

- the ticket is wrapped as data in the prompt, and the instructions tell the AI to treat it as such;
- the AI has no tools: it cannot read other tickets, call URLs or act in GLPI;
- its answer must follow a JSON schema; unexpected fields are ignored, the classification must be one
  of the categories allowed for the ticket;
- its HTML is reduced to a short list of formatting tags (paragraphs, lists, bold, italic, code,
  small headings) and **every attribute is removed**: no links, images, scripts, styles or event
  handlers survive; the signature is added by the server;
- the technician reads every text before sending it.

## Network

- Calls go only to the configured address, with TLS verification, through the proxy of GLPI when
  configured (local and private addresses directly).
- Only `http`/`https`, no redirects followed, cloud metadata addresses refused; optional allowlist:
  `TICKETASSISTANT_URL_ALLOWLIST` ([PROVIDERS.md](PROVIDERS.md#network-proxy-and-allowlist)).
- The API key is sent only in the authentication header expected by the provider.

## GDPR, LGPD and similar laws

The plugin gives you the controls; the compliance decisions are yours. Points to cover:

- **Legal basis and purpose**: assisting technicians in handling requests is usually covered by the
  purpose of the service desk; document it in your record of processing.
- **Processor**: the AI provider processes personal data on your behalf: sign its data processing
  agreement, check the region where data is processed and its retention and training policies, or
  use a local model.
- **Minimization**: keep the masking detectors relevant to your country, disable images or
  private items if needed, keep **Context size** and the attachment limits low.
- **Retention**: set the retention periods of the log; the library follows the tickets (deleted or
  reopened tickets leave it).
- **Transparency**: tell requesters that AI assists your technicians (for instance in the
  confirmation e-mail of new tickets).
- **Rights of the data subjects**: purging a ticket in GLPI removes it from the library at the next
  synchronization; the log can be purged by date.
