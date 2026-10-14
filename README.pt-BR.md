<img src="docs/images/logo.png" alt="" width="80" align="right">

# GLPI AI Ticket Assistant

[![CI](https://github.com/flpsantoro/glpi-ai-ticket-assistant/actions/workflows/ci.yml/badge.svg)](https://github.com/flpsantoro/glpi-ai-ticket-assistant/actions/workflows/ci.yml)
[![Licença: GPL v2+](https://img.shields.io/badge/licen%C3%A7a-GPL--2.0--or--later-blue.svg)](LICENSE)
![GLPI 10.0.11+ | 11 | 12](https://img.shields.io/badge/GLPI-10.0.11%2B%20%7C%2011%20%7C%2012-0f5ca8)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

**Um assistente de IA para os técnicos do GLPI.** Ele lê o chamado inteiro — texto, histórico, prints
e documentos anexados — e entrega ao técnico um diagnóstico, uma estratégia passo a passo e um
rascunho de resposta no padrão da equipe. Reescreve rascunhos, sugere o tipo e a categoria dos
chamados aprendendo com os que já foram resolvidos e transforma chamados resolvidos em artigos da
base de conhecimento.

O técnico continua no controle: nada é enviado ao solicitante automaticamente.

*[Read in English](README.md)*

![Análise de um chamado com print, PDF e arquivo de log](docs/images/analysis.png)

## Destaques

- **Lê o chamado inteiro.** Descrição, acompanhamentos, tarefas, soluções (inclusive as recusadas),
  ativos vinculados, aprovação e os anexos de cada item da linha do tempo: imagens (visão), PDF
  (digital ou digitalizado), arquivos Word/Excel/PowerPoint/OpenDocument, logs, CSV, JSON.
- **Duas respostas em um clique.** Orientação interna para o técnico (resumo, causa provável, o que
  verificar, quando escalar, informações que faltam) e um rascunho de resposta para o solicitante.
- **Melhorar meu texto.** Seis modos nos formulários de acompanhamento e de solução: melhorar, mais
  formal, mais cordial, mais curto, só corrigir ortografia, traduzir para o idioma do solicitante.
- **Aprende com os chamados resolvidos, sem treinar modelo.** Os chamados resolvidos viram uma
  biblioteca de casos pesquisável (texto completo, embeddings e destilação por IA opcionais). Chamados
  novos recebem os casos mais parecidos como referência e uma sugestão de tipo, categoria e urgência;
  a taxa de acerto é **medida** no seu próprio histórico antes de você confiar nela.
- **Triagem automática** dos chamados novos (inclusive os criados por e-mail), com aplicação
  automática opcional acima de um limiar de confiança escolhido a partir do acerto medido.
- **Artigos da base de conhecimento** redigidos a partir de chamados resolvidos, criados como
  rascunho não publicado para revisão.
- **Qualquer provedor.** OpenAI, Azure OpenAI, Anthropic, Google Gemini, Mistral, OpenRouter, Ollama e
  qualquer servidor compatível com OpenAI (LM Studio, vLLM, LiteLLM…): em nuvem ou 100 % local.
- **Governança embutida.** Orçamentos diários, limite por técnico por hora, log de auditoria com custo
  por chamada, feedback 👍/👎, estatísticas, mascaramento de segredos e dados pessoais, notas privadas
  respeitando os direitos do técnico, chaves de API criptografadas com a chave do GLPI.
- **Um único código para GLPI 10.0.11+, 11 e 12**, PHP 7.4 a 8.5, em inglês, português (Brasil),
  espanhol e francês.

## Telas

| | |
|---|---|
| ![Rascunho de resposta](docs/images/reply.png) | ![Melhorar meu texto](docs/images/improve.png) |
| **Rascunho de resposta** — inserido no editor de acompanhamento ou de solução, nunca enviado sozinho. | **Melhorar meu texto** — seis modos, com a assinatura do técnico adicionada pelo servidor. |
| ![Classificação](docs/images/classification.png) | ![Aprendizado](docs/images/learning.png) |
| **Classificação** — tipo, categoria e urgência a partir de chamados resolvidos parecidos, aplicados em um clique. | **Aprendizado** — biblioteca de chamados resolvidos e acerto medido no seu histórico. |

Telas: GLPI 11 com os dados de demonstração e o provedor de IA simulado em modo roteirizado
(`tools/mock-llm/server.py --scripted`), que acompanham o projeto: os textos da IA foram escritos
para a demonstração, não gerados.

## Como funciona

1. O **montador de contexto** lê o chamado dentro de um limite de caracteres (os acompanhamentos mais
   antigos saem primeiro) e extrai o texto dos documentos; as imagens vão para modelos com visão.
2. As **referências** vêm da biblioteca de chamados resolvidos: os casos mais parecidos (busca por
   texto completo, reordenada por embeddings quando habilitados) com a solução que a equipe aplicou.
3. O **mascaramento** substitui segredos ("senha: …"), cartões, IBAN, CPF/CNPJ, e-mails, telefones ou
   endereços IP, conforme configurado, antes de qualquer coisa sair do servidor.
4. A resposta precisa seguir um **esquema JSON**; o HTML é filtrado antes de ser exibido, e nada é
   gravado no chamado até o técnico decidir.

O "aprendizado" é recuperação, não treinamento: os chamados resolvidos são exemplos rotulados. A
classificação faz uma votação entre os casos mais próximos (instantânea, gratuita) e, opcionalmente,
a IA escolhe entre as categorias candidatas com esses casos como exemplos. A página **Aprendizado com
chamados resolvidos** reprocessa o seu histórico em ordem cronológica (cada chamado classificado só com os mais antigos) e
mostra o acerto top-1/top-3, por categoria e por nível de confiança, as confusões frequentes e os
chamados que parecem mal classificados. Detalhes em
[docs/LEARNING-AND-CLASSIFICATION.md](docs/LEARNING-AND-CLASSIFICATION.md).

## Requisitos

| | |
|---|---|
| GLPI | 10.0.11 ou posterior, 11.0, 12.0 |
| PHP | a versão exigida pelo seu GLPI (7.4+ no GLPI 10), extensões `curl`, `mbstring`, `json` |
| Recomendado | `gd` (reduz imagens grandes), `zip` (arquivos Office), `poppler-utils` (PDFs complexos e digitalizados) |
| IA | uma conta em um provedor, ou um servidor local (Ollama, LM Studio, vLLM…) |
| Ações automáticas | cron do GLPI em modo CLI (`php front/cron.php` a cada minuto) |

## Instalação

### Com o instalador (recomendado)

O instalador encontra as instalações do GLPI da máquina, escolhe um PHP compatível e o usuário do
servidor web, copia o plugin, instala e habilita, conecta o provedor de IA (menu de provedores, lista
dos modelos, teste de conexão), monta a biblioteca de chamados resolvidos e verifica o cron e as
ferramentas de PDF. Setas e Enter, em português ou inglês; pergunta antes de mudar qualquer coisa.

**Linux**

```bash
git clone https://github.com/flpsantoro/glpi-ai-ticket-assistant.git
cd glpi-ai-ticket-assistant
sudo bash install.sh
```

**Windows** (XAMPP, WAMP, Laragon, IIS…), no PowerShell:

```powershell
git clone https://github.com/flpsantoro/glpi-ai-ticket-assistant.git
cd glpi-ai-ticket-assistant
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

Rode de novo depois para atualizar o plugin, trocar o provedor de IA, testar a conexão ou
desinstalar. Modo não interativo para automação: `sudo bash install.sh --help` ou
`.\install.ps1 -Help`.

### Manualmente

1. Baixe `ticketassistant-x.y.z.tar.bz2` nas
   [releases](https://github.com/flpsantoro/glpi-ai-ticket-assistant/releases) e extraia na pasta
   `plugins/` do GLPI, de modo que o caminho fique `plugins/ticketassistant/setup.php`.
2. No GLPI: **Configurar > Plugins**, instale e habilite **GLPI AI Ticket Assistant**, ou:
   ```bash
   php bin/console plugin:install --username=glpi ticketassistant
   php bin/console plugin:activate ticketassistant
   ```
3. Abra **Configurar > AI Ticket Assistant**, escolha o provedor, preencha o modelo e a chave,
   **Salvar**, **Testar a conexão** e **Habilitar o assistente** — ou pela linha de comando:
   ```bash
   printf '%s' "$OPENAI_API_KEY" | php bin/console plugins:ticketassistant:configure \
       --provider=openai --model=gpt-5-mini --api-key-stdin --enable --test
   ```
4. Os perfis de técnico (os que podem atualizar chamados) recebem o direito **Assistente de IA** na
   primeira instalação; ajuste em **Administração > Perfis > Assistente de IA**.

As ações automáticas (`aisync`, `ailearn`, `aitriage`, `aipurge`) rodam em modo CLI: confira se o
cron do GLPI está configurado. Guia completo: [docs/pt-BR/INSTALACAO.md](docs/pt-BR/INSTALACAO.md).

### Testar sem chave de API

O repositório traz um provedor de IA simulado (Python, sem chave, sem custo), um ambiente Docker e
dados de demonstração (uma entidade, categorias, 40 chamados resolvidos e 3 abertos com print, PDF e
arquivo de log):

```bash
python tools/mock-llm/server.py --port 8099
docker compose -f tools/docker/docker-compose.yml --profile glpi11 up -d   # ou glpi10, glpi12, all
```

Quando <http://localhost:8011> responder (a primeira inicialização instala o GLPI, login `glpi` / `glpi`):

```bash
alias glpi='docker compose -f tools/docker/docker-compose.yml exec -u www-data glpi11 php /var/www/glpi/bin/console'
glpi plugin:install --username=glpi ticketassistant && glpi plugin:activate ticketassistant
glpi plugins:ticketassistant:configure --provider=compatible --base-url=http://host.docker.internal:8099/v1 --model=mock-vision-1 --enable --test
glpi plugins:ticketassistant:demo --yes
glpi plugins:ticketassistant:sync
```

## Provedores

| Provedor | Visão | Arquivos PDF | Embeddings | Observações |
|---|---|---|---|---|
| OpenAI | sim | sim | sim | modelos de raciocínio e padrão, esquema JSON estrito |
| Azure OpenAI | sim | — | sim | implantações (`api-version`) ou a API v1 |
| Anthropic (Claude) | sim | sim | — | use uma segunda conexão para embeddings |
| Google Gemini | sim | — | sim | endpoint compatível com OpenAI |
| Mistral AI | sim (Pixtral) | — | sim | |
| OpenRouter | depende do modelo | — | — | uma chave, muitos modelos |
| Ollama | sim (modelos com visão) | — | sim | local, sem chave, sem custo |
| Outra API compatível com OpenAI | depende | — | depende | LM Studio, vLLM, LiteLLM, Groq, DeepSeek… |

Configuração, preços e proxy: [docs/PROVIDERS.md](docs/PROVIDERS.md).

## Privacidade e segurança

- Só o necessário é enviado: texto mascarado, anexos dentro dos limites configurados, o primeiro nome
  do solicitante (opcional). Acompanhamentos e tarefas privados seguem os direitos do técnico.
- As chaves de API são criptografadas com a chave da instalação do GLPI e nunca mais exibidas.
- As chamadas de saída se limitam a http(s), endereços de metadados de nuvem são recusados,
  redirecionamentos não são seguidos; uma lista de permissões pode ser imposta com a constante
  `TICKETASSISTANT_URL_ALLOWLIST`.
- Cada chamada é registrada com tokens, custo, duração e modelo; os textos gerados podem ser guardados
  para auditoria por um período definido.
- O conteúdo do chamado entra no prompt como dado, e a saída da IA é validada e filtrada antes de ser
  exibida.

Leia [docs/SECURITY-AND-PRIVACY.md](docs/SECURITY-AND-PRIVACY.md) antes de enviar chamados reais a um
provedor em nuvem, e confira os termos do provedor sobre retenção e uso dos dados (LGPD).

## Documentação

| Guia | Conteúdo |
|---|---|
| [Guia do usuário](docs/pt-BR/GUIA-DO-USUARIO.md) | para técnicos: análise, resposta, melhorar texto, classificação, artigos |
| [Instalação](docs/pt-BR/INSTALACAO.md) | requisitos, instaladores, instalação manual, cron, atualização, problemas comuns |
| [Configuração](docs/pt-BR/CONFIGURACAO.md) | cada opção, com o valor padrão |
| [Provedores](docs/PROVIDERS.md) (inglês) | OpenAI, Azure, Anthropic, Gemini, Mistral, OpenRouter, Ollama, APIs compatíveis |
| [Aprendizado e classificação](docs/LEARNING-AND-CLASSIFICATION.md) (inglês) | biblioteca de casos, embeddings, triagem, avaliação |
| [Segurança e privacidade](docs/SECURITY-AND-PRIVACY.md) (inglês) | fluxo de dados, mascaramento, direitos, proteções |
| [Operação](docs/OPERATIONS.md) (inglês) | ações automáticas, comandos, orçamentos, estatísticas, erros |
| [Desenvolvimento](docs/DEVELOPMENT.md) (inglês) | arquitetura, compatibilidade GLPI 10/11/12, testes, traduções |

## Perguntas frequentes

**Este é o plugin oficial "GLPI AI"?** Não. O GLPI AI é o plugin comercial da Teclib' para assinantes
do GLPI Network. Este é um plugin comunitário independente, sob a GPL.

**Ele treina um modelo com os meus chamados?** Não. Os chamados resolvidos são pesquisados e usados
como exemplos no prompt; nada passa por fine-tuning. O que o provedor faz com os dados depende dos
termos dele.

**Funciona sem nenhum serviço em nuvem?** Sim, com o Ollama ou outro servidor local compatível com
OpenAI.

**Ele responde ao solicitante sozinho?** Não. Todo texto é um rascunho inserido no editor. Só a
classificação pode ser aplicada automaticamente, e somente se você habilitar.

## Contribuindo

Issues e pull requests são bem-vindos, em português ou inglês. Veja
[CONTRIBUTING.md](CONTRIBUTING.md) e o [changelog](CHANGELOG.md). Problemas de segurança: siga o
[SECURITY.md](SECURITY.md).

## Licença

[GPL-2.0-or-later](LICENSE), como o GLPI. © 2026 Felipe Santoro Alcantara.

GLPI é marca registrada da Teclib'. Este projeto não é afiliado nem endossado pela Teclib'. Outros
nomes de produtos pertencem aos seus donos.
