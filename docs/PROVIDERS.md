# AI providers

The plugin talks to the provider directly from the GLPI server, with the PHP curl extension. Choose
the provider in **Setup > AI Ticket Assistant**: the address, the output limit parameter and the
JSON mode get the right defaults, and the model list button asks the provider for its models.

| Provider | Key | Default address | Vision | PDF file | Embeddings | JSON |
|---|---|---|---|---|---|---|
| OpenAI | yes | `https://api.openai.com/v1` | yes | yes | yes | strict schema |
| Azure OpenAI | yes | `https://<resource>.openai.azure.com` | yes | — | yes | JSON mode |
| Anthropic (Claude) | yes | `https://api.anthropic.com` | yes | yes | — | instructions |
| Google Gemini | yes | `https://generativelanguage.googleapis.com/v1beta/openai` | yes | — | yes | JSON mode |
| Mistral AI | yes | `https://api.mistral.ai/v1` | Pixtral | — | yes | JSON mode |
| OpenRouter | yes | `https://openrouter.ai/api/v1` | per model | — | — | JSON mode |
| Ollama | no | `http://localhost:11434/v1` | vision models | — | yes | JSON mode |
| Other OpenAI-compatible API | optional | your server | per model | — | per server | JSON mode |

"PDF file" is the option **Send the PDF file itself** (tables and images of the PDF read by the
model). Without it, the text of PDFs is extracted on the server and sent as text.

## Choosing a model

- **Vision matters**: tickets often carry screenshots of errors. A text-only model only sees their
  file names (turn off "The model reads images" for such models).
- **Reasoning models** (OpenAI GPT-5 family, o-series, Claude with extended thinking, Gemini 2.5)
  give better technical strategies; set **Model type = Reasoning** and keep the output limits about
  twice the expected text, since the reasoning tokens count in them. **Standard models** use the
  temperature instead.
- A small, fast model is usually enough for **Improve my text** and **Classification**; the
  reasoning effort can be set per action.
- Enter the **prices** of the model (US$ per million input and output tokens, Costs and limits tab):
  the budgets and the statistics depend on them.

## OpenAI

Key from <https://platform.openai.com/api-keys>. Models such as `gpt-5-mini` (good default),
`gpt-5.4`, `gpt-4.1-mini`. Strict JSON schema, vision, PDF files, embeddings
(`text-embedding-3-small`).

```bash
printf '%s' "$KEY" | php bin/console plugins:ticketassistant:configure --provider=openai --model=gpt-5-mini --api-key-stdin --enable --test
```

## Azure OpenAI

- **API address**: `https://<resource>.openai.azure.com` (the endpoint of the resource, without
  path).
- **Deployment**: the name of your deployment, not the name of the model.
- **API version**: a dated version such as `2024-10-21`, or `v1` for the newer v1 API (the plugin
  then calls `/openai/v1/…`).
- Key: Azure portal > the resource > Keys and Endpoint.
- Embeddings: the name of an embeddings deployment in **Embeddings model**.
- The model list is not available (deployments are managed in Azure AI Foundry).

## Anthropic (Claude)

Key from <https://console.anthropic.com/settings/keys>. Models such as `claude-sonnet-5-5` and
`claude-haiku-5-5`. Vision and PDF files. The JSON format is enforced by instructions and validated
by the plugin.

Anthropic has no embeddings API: to enable semantic search, turn on **Use another connection for
embeddings** and choose OpenAI, Gemini, Mistral, Ollama, Azure or a compatible server.

## Google Gemini

Key from <https://aistudio.google.com/apikey>. The plugin uses the OpenAI-compatible endpoint of the
Gemini API. Models such as `gemini-2.5-flash` and `gemini-2.5-pro`; embeddings
`gemini-embedding-001`.

## Mistral AI

Key from <https://console.mistral.ai/api-keys>. `mistral-medium-latest` for text,
`pixtral-large-latest` to read images; embeddings `mistral-embed`.

## OpenRouter

One key (<https://openrouter.ai/keys>) for many providers; model names such as
`openai/gpt-5-mini` or `anthropic/claude-sonnet-5-5`. Vision depends on the model. No embeddings: use
a second connection for semantic search.

## Ollama (local models)

No key and no cost per call, and the data stays on your infrastructure.

- **API address**: as seen **from the GLPI server**: `http://localhost:11434/v1` when Ollama runs on
  the same machine, `http://<ollama-host>:11434/v1` otherwise, `http://host.docker.internal:11434/v1`
  when GLPI runs in Docker and Ollama on the host.
- Vision models: `qwen2.5vl`, `gemma3`, `llama3.2-vision`. Embeddings: `nomic-embed-text`, `bge-m3`.
- **Context window**: Ollama cuts prompts longer than its context window, which is small by default.
  Start the server with a larger one, e.g. `OLLAMA_CONTEXT_LENGTH=16384 ollama serve`, or lower
  **Context size** in the plugin.
- Leave the prices at 0 and use the hourly limit per technician.
- Answers depend a lot on the model: test with real tickets and use the evaluation of the
  classification before trusting automatic triage.

## Other OpenAI-compatible APIs

Any server implementing `/v1/chat/completions` (and optionally `/v1/models` and `/v1/embeddings`):

| Server | Typical address |
|---|---|
| LM Studio | `http://localhost:1234/v1` |
| vLLM | `http://<host>:8000/v1` |
| LiteLLM proxy | `http://<host>:4000/v1` |
| Groq | `https://api.groq.com/openai/v1` |
| DeepSeek | `https://api.deepseek.com/v1` |

If the server rejects `response_format`, set **JSON answers = Instructions only**; if it rejects
`max_completion_tokens`, set **Output limit parameter = max_tokens**.

## Network, proxy and allowlist

- The plugin uses the proxy configured in GLPI (**Setup > General > System > Proxy**) unless
  **Use the proxy configured in GLPI** is off; local and private addresses are always reached
  directly.
- Only `http` and `https` addresses are called, redirects are not followed, cloud metadata
  addresses (`169.254.x.x`, `metadata.google.internal`…) are refused.
- To restrict the destinations, define `TICKETASSISTANT_URL_ALLOWLIST` in
  `config/local_define.php` of GLPI:

  ```php
  <?php
  define('TICKETASSISTANT_URL_ALLOWLIST', [
      '#^https://api\.openai\.com/#',
      '#^http://10\.0\.0\.5:11434/#',
  ]);
  ```

## Data handling by the providers

What the provider does with the requests (retention, abuse monitoring, use for training, region)
depends on its terms and on your plan; free tiers of some providers may use requests to improve
their models. Read the data policy of your provider, sign the data processing agreement your
organization needs, and see [SECURITY-AND-PRIVACY.md](SECURITY-AND-PRIVACY.md) for what the plugin
sends.
