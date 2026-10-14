# Configuração

*[English version](../CONFIGURATION.md)*

**Configurar > AI Ticket Assistant** (direito: atualizar a configuração geral). A página começa com
um cartão de **Configuração rápida**; as demais opções ficam nas abas abaixo e já vêm com um valor
padrão sensato. Toda opção também pode ser alterada pela linha de comando com a sua chave:

```bash
php bin/console plugins:ticketassistant:configure --set=daily_budget_usd=10 --set=kb_max_results=5
php bin/console plugins:ticketassistant:configure --show     # todas as chaves e valores, chaves de API mascaradas
```

Alguns padrões seguem o idioma padrão do GLPI: em português do Brasil, o mascaramento de CPF/CNPJ vem
ligado e a saudação, a assinatura e o padrão de comunicação vêm em português.

- [Configuração rápida](#configuração-rápida)
- [Ações](#ações)
- [Modelo e geração](#modelo-e-geração)
- [Contexto e anexos](#contexto-e-anexos)
- [Aprendizado e classificação](#aprendizado-e-classificação)
- [Privacidade](#privacidade)
- [Custos e limites](#custos-e-limites)
- [Estilo de escrita](#estilo-de-escrita)
- [Diagnóstico](#diagnóstico)
- [Orientações por categoria](#orientações-por-categoria)
- [Constantes](#constantes)

## Configuração rápida

| Opção | Chave | Padrão | Observações |
|---|---|---|---|
| Provedor de IA | `provider` | `openai` | `openai`, `azure`, `anthropic`, `gemini`, `mistral`, `openrouter`, `ollama`, `compatible` ([PROVIDERS.md](../PROVIDERS.md)) |
| Endereço da API | `base_url` | vazio | vazio = endereço padrão do provedor; obrigatório no Azure e em servidores compatíveis |
| Modelo / Deployment | `model` | vazio | texto livre; o botão ao lado lista os modelos do provedor |
| Versão da API | `api_version` | `2024-10-21` (Azure) | só Azure; `v1` para a API v1 |
| Chave da API | `api_key` | vazio | criptografada com a chave da instalação do GLPI, nunca mais exibida; deixe o campo vazio para manter a atual |
| Habilitar o assistente | `enabled` | desligado | os botões só aparecem com o assistente habilitado **e** a conexão completa |

**Testar a conexão** envia uma requisição de uma palavra com a configuração salva (e um embedding,
quando a busca semântica está ligada) e mostra o modelo, a duração, os tokens e o custo.

## Ações

| Opção | Chave | Padrão |
|---|---|---|
| Analisar o chamado: resumo, estratégia técnica e rascunho de resposta | `action_analyze` | ligado |
| Melhorar meu texto: reescreve o rascunho do técnico (6 modos) | `action_improve` | ligado |
| Classificação: sugere tipo, categoria e urgência | `action_classify` | ligado |
| Base de conhecimento: escreve um artigo a partir de um chamado resolvido | `action_article` | ligado |

## Modelo e geração

| Opção | Chave | Padrão | Observações |
|---|---|---|---|
| Tipo de modelo | `model_type` | `reasoning` | `reasoning` envia o esforço de raciocínio e nenhuma temperatura; `standard` envia a temperatura |
| Temperatura | `temperature` | `0.3` | só modelos padrão |
| Respostas em JSON | `json_mode` | `auto` | `json_schema` (estrito), `json_object`, `prompt` (apenas instruções) |
| Parâmetro do limite de saída | `token_param` | `auto` | `max_completion_tokens` ou `max_tokens` |
| O modelo lê imagens (visão) | `vision` | ligado | desligue em modelos só de texto |
| Detalhe das imagens | `image_detail` | `auto` | `low` é mais barato |
| Tempo limite (segundos) | `http_timeout` | `90` | |
| Novas tentativas (rede, 429, 5xx) | `http_retries` | `2` | respeita o `Retry-After`; erros de cota e de filtro de conteúdo não são repetidos |
| Usar o proxy configurado no GLPI | `use_glpi_proxy` | ligado | endereços locais e privados são sempre acessados diretamente |

Por ação, limite de saída e esforço de raciocínio (`none`, `minimal`, `low`, `medium`, `high`, ou
não enviar):

| Ação | Chave do limite | Padrão | Chave do esforço | Padrão |
|---|---|---|---|---|
| Analisar | `analyze_max_tokens` | 4096 | `analyze_effort` | medium |
| Melhorar meu texto | `improve_max_tokens` | 2048 | `improve_effort` | low |
| Classificação | `classify_max_tokens` | 1024 | `classify_effort` | low |
| Artigo | `article_max_tokens` | 3072 | `article_effort` | low |
| Aprendizado (destilação) | `learn_max_tokens` | 1536 | `learn_effort` | low |

Em modelos de raciocínio o limite de saída inclui os tokens de raciocínio: reserve cerca do dobro do
texto esperado.

## Contexto e anexos

| Opção | Chave | Padrão | Observações |
|---|---|---|---|
| Tamanho do contexto (caracteres) | `max_context_chars` | 24000 | os acompanhamentos mais antigos saem primeiro |
| Acompanhamentos e tarefas privados | `private_items` | `rights` | `rights`: só os que o técnico tem direito de ver; `all`: todos, marcados como notas internas; `none`: nenhum |
| Incluir os ativos vinculados ao chamado | `context_linked_items` | ligado | |
| Incluir os modelos de solução da entidade | `solution_templates` | ligado | |
| Enviar as imagens anexadas | `images_enabled` | ligado | |
| Máximo de imagens | `images_max_count` | 4 | |
| Tamanho máximo enviado (MB) | `images_max_mb` | 4 | imagens maiores são reduzidas, não descartadas (precisa do GD) |
| Maior lado (pixels) | `images_max_px` | 2048 | |
| Ler os PDFs anexados | `pdf_enabled` | ligado | |
| Máximo de PDFs | `pdf_max_count` | 2 | |
| Caracteres por PDF | `pdf_max_chars` | 4000 | |
| PDF escaneado: páginas enviadas como imagem | `pdf_scan_pages` | 2 | precisa do `pdftoppm`; usa a cota de imagens |
| Enviar o próprio arquivo PDF | `pdf_native` | desligado | OpenAI e Anthropic leem o arquivo (tabelas, imagens); consome mais tokens |
| Diretório do poppler-utils | `poppler_path` | vazio | vazio = `PATH` do servidor web |
| Ler arquivos Office/OpenDocument e de texto | `docs_enabled` | ligado | docx, xlsx, pptx, odt, ods, odp, txt, log, csv, json, xml, yaml… |
| Máximo de documentos | `docs_max_count` | 3 | |
| Caracteres por documento | `docs_max_chars` | 4000 | |

São lidos os anexos do chamado **e** dos seus acompanhamentos, tarefas e soluções, do mais novo para
o mais antigo.

## Aprendizado e classificação

| Opção | Chave | Padrão | Observações |
|---|---|---|---|
| Chamados semelhantes enviados com a análise | `kb_max_results` | 3 | 0 = nenhum |
| Similaridade mínima de uma referência | `kb_min_similarity` | 0.10 | cosseno das palavras (busca por texto) ou dos embeddings: com embeddings, 0.3–0.5 |
| Usar só chamados resolvidos das entidades do técnico | `kb_same_entity` | ligado | |
| Destilar os chamados resolvidos com a IA | `learn_enabled` | desligado | ação automática `ailearn` |
| Chamados por execução | `learn_batch` | 20 | |
| Orçamento diário das ações automáticas (US$) | `background_budget_usd` | 1.00 | destilação, embeddings, triagem |
| Habilitar a busca semântica | `embeddings_enabled` | desligado | exige um modelo de embeddings |
| Modelo de embeddings | `embedding_model` | vazio | ex.: `text-embedding-3-small`, `nomic-embed-text` |
| Dimensões | `embedding_dimensions` | 0 | 0 = padrão do modelo |
| Chamados recentes varridos | `embeddings_scan_limit` | 3000 | casos comparados por embeddings em cada busca |
| Usar outra conexão para os embeddings | `emb_separate` | desligado | obrigatório com a Anthropic; chaves `emb_provider`, `emb_base_url`, `emb_api_version`, `emb_api_key` |
| Deixar a IA escolher entre as categorias | `classify_use_llm` | ligado | desligado = só a votação dos chamados semelhantes (gratuita, instantânea) |
| Vizinhos considerados | `classify_neighbors` | 25 | |
| Sugerir uma classificação quando um chamado é aberto | `triage_enabled` | desligado | ação automática `aitriage` |
| Só chamados abertos sem categoria | `triage_only_uncategorized` | ligado | |
| Usar a IA na triagem automática | `triage_use_llm` | desligado | consome tokens, dentro do orçamento das ações automáticas |
| Aplicar automaticamente acima do limiar | `triage_auto_apply` | desligado | meça a taxa de acerto antes |
| Limiar de confiança | `triage_threshold` | 0.85 | |

Como funcionam: [LEARNING-AND-CLASSIFICATION.md](../LEARNING-AND-CLASSIFICATION.md) (inglês).

## Privacidade

| Opção | Chave | Padrão |
|---|---|---|
| Detectores de mascaramento | `mask_detectors` | `secrets,card` (+ `cnpj,cpf` em português do Brasil) |
| Padrões personalizados (`RÓTULO=/regex/`, um por linha) | `mask_custom` | vazio |
| Enviar o nome do solicitante (saudação pelo primeiro nome) | `send_requester_name` | ligado |
| Guardar os textos gerados no log | `log_responses` | ligado |
| Guardar textos gerados (dias, 0 = para sempre) | `response_retention_days` | 90 |
| Guardar registros do log (dias, 0 = para sempre) | `log_retention_days` | 365 |

Detectores: `secrets`, `email`, `card`, `iban`, `cnpj`, `cpf`, `phone`, `ipv4`. Pela linha de comando:
`--set=mask_detectors=secrets,card,cpf,cnpj,email`. O que cada um pega e os limites (por exemplo,
"sem senha: liberado" vira "sem senha: [SECRET]", e o CPF é mascarado pelo formato, sem verificar os
dígitos): [SECURITY-AND-PRIVACY.md](../SECURITY-AND-PRIVACY.md#masking).

## Custos e limites

| Opção | Chave | Padrão | Observações |
|---|---|---|---|
| Preço de entrada (US$ por milhão de tokens) | `price_input` | 0.00 | da tabela de preços do seu modelo |
| Preço de saída (US$ por milhão de tokens) | `price_output` | 0.00 | |
| Orçamento diário dos técnicos (US$) | `daily_budget_usd` | 5.00 | 0 = sem orçamento |
| Usos por técnico por hora | `user_hourly_limit` | 20 | 0 = sem limite |
| Intervalo entre duas análises de um chamado (s) | `ticket_cooldown_seconds` | 60 | durante o intervalo a última análise é exibida de novo |

Os custos são **estimados** pelos tokens informados pelo provedor e por esses preços: com preço 0 os
orçamentos não conseguem barrar nada. Modelos locais: deixe 0 e use o limite por hora.

## Estilo de escrita

| Opção | Chave | Padrão |
|---|---|---|
| Nome da organização (usado nos prompts) | `organization_name` | vazio |
| Idioma das respostas | `response_language` | idioma padrão do GLPI (`auto` = idioma do chamado) |
| Padrão de comunicação das mensagens ao solicitante | `style_guide` | um padrão curto no idioma padrão |
| Assinatura | `signature_template` | `Atenciosamente,<br>{name}` |
| Instruções adicionais para a IA | `extra_instructions` | vazio |

A assinatura é acrescentada pelo servidor, não pelo modelo; marcadores `{name}`, `{firstname}`,
`{entity}`, `{organization}`; HTML permitido `<br>`, `<strong>`, `<em>`; vazio = sem assinatura.

## Diagnóstico

A aba **Diagnóstico** mostra o que o servidor web enxerga: versões do GLPI e do PHP, curl, GD, zip,
`shell_exec`, `pdftotext` e `pdftoppm`, o proxy do GLPI, a biblioteca de chamados resolvidos e o estado
e a última execução de cada ação automática.

## Orientações por categoria

**Configurar > Listas suspensas > Categorias ITIL > uma categoria > aba "Assistente de IA"**:
instruções acrescentadas ao prompt dos chamados daquela categoria (o que pedir, qual procedimento se
aplica, quando escalar), opcionalmente herdadas pelas subcategorias. Direito: atualizar categorias
ITIL.

## Constantes

Definidas no `config/local_define.php` do GLPI (crie o arquivo se não existir):

| Constante | Efeito |
|---|---|
| `TICKETASSISTANT_URL_ALLOWLIST` | array de expressões regulares; quando definida, o plugin só chama endereços que casem com uma delas, ex.: `['#^https://api\.openai\.com/#', '#^http://10\.0\.0\.5:11434/#']` |
