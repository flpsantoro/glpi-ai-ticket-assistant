# Instalação

*[English version](../INSTALL.md)*

- [Requisitos](#requisitos)
- [Instalador para Linux](#instalador-para-linux)
- [Instalador para Windows](#instalador-para-windows)
- [GLPI em um contêiner](#glpi-em-um-contêiner)
- [Instalação manual](#instalação-manual)
- [Ações automáticas (cron)](#ações-automáticas-cron)
- [Direitos](#direitos)
- [Atualização](#atualização)
- [Desinstalação](#desinstalação)
- [Problemas comuns](#problemas-comuns)

## Requisitos

| | Obrigatório | Recomendado |
|---|---|---|
| GLPI | 10.0.11 ou posterior, 11.0, 12.0 | a última versão de manutenção |
| PHP | a versão exigida pelo seu GLPI (o plugin roda do 7.4 ao 8.5) | |
| Extensões do PHP | `curl`, `mbstring`, `json`, `zlib` (todas exigidas pelo GLPI também) | `gd` (reduz imagens grandes), `zip` (arquivos Word, Excel, PowerPoint, OpenDocument) |
| Ferramentas | | `poppler-utils` (`pdftotext`, `pdftoppm`) com `shell_exec` liberado |
| Rede | HTTPS do servidor do GLPI até o provedor de IA, direto ou pelo proxy do GLPI | |
| Ações automáticas | cron do GLPI em modo CLI | |

Sem o poppler-utils, um leitor embutido extrai o texto de PDFs digitais (gerados por pacotes de
escritório, navegadores e scanners com OCR); PDFs digitalizados sem camada de texto precisam do
`pdftoppm` ou de um provedor que leia PDF (OpenAI, Anthropic: opção "Enviar o próprio arquivo PDF").

As extensões precisam estar ativas no PHP do **servidor web** (módulo do Apache, PHP-FPM, FastCGI do
IIS), não só na linha de comando. A aba **Diagnóstico** da página de configuração mostra o que o
servidor web enxerga.

## Instalador para Linux

A partir do repositório clonado (ou de um pacote de release extraído):

```bash
sudo bash install.sh
```

O instalador é um assistente de terminal (setas, Enter, listas com filtro por digitação, chave
digitada oculta) em português ou inglês, conforme o `LANG` ou `--lang`. Ele nunca muda nada sem
perguntar, e cada etapa pode ser pulada:

1. **Sistema**: binários do PHP (o do `PATH`, `/usr/bin/php8.x`, coleções Remi…), extensões,
   ferramenta de cópia.
2. **GLPI**: procura nas pastas habituais (`/var/www`, `/srv`, `/opt`, `/usr/share/glpi`, pastas
   pessoais), nas raízes de documentos declaradas no Apache e no nginx e nos contêineres em execução
   (veja [GLPI em um contêiner](#glpi-em-um-contêiner)), e mostra cada GLPI com sua versão e a versão
   do plugin que já estiver nele. Escolhe um PHP capaz de rodar esse GLPI (o GLPI 11
   exige PHP 8.2) e o usuário do servidor web (dono dos arquivos do GLPI, inclusive do `GLPI_VAR_DIR`).
3. **Plugin**: copia o plugin (só os arquivos do plugin: sem testes, documentação ou ferramentas) ou
   o vincula ao repositório (desenvolvimento: um `git pull` atualiza o GLPI), onde o GLPI o carrega: a
   pasta marketplace quando o plugin já está lá, senão `plugins/`. Antes, a versão anterior é guardada
   em `/var/backups/glpi-ticketassistant/` (`TICKETASSISTANT_BACKUP_DIR`: outra pasta). Em seguida o
   console do GLPI instala e habilita o plugin **como o usuário do servidor web**, para que os arquivos
   de cache do GLPI mantenham o dono certo. Em sistemas com SELinux, o contexto de segurança é
   restaurado.
4. **IA**: menu dos provedores, endereço quando faz sentido (recurso Azure, Ollama, servidores locais),
   chave digitada oculta (ela chega ao GLPI pela entrada padrão, nunca numa linha de comando), lista dos
   modelos oferecidos pelo provedor com filtro, teste de conexão e, opcionalmente, idioma, nome da
   organização, preços por token e orçamento diário.
5. **Biblioteca**: lê os chamados solucionados e fechados (sem chamar a IA, sem custo) para que as
   sugestões funcionem de imediato.
6. **Cron e PDF**: cria `/etc/cron.d/glpi-cron` quando nenhum cron executa as ações automáticas do
   GLPI, instala o `poppler-utils` com o gerenciador de pacotes (apt, dnf, yum, zypper, apk, pacman) e
   pode criar dados de demonstração numa instância de teste.

Rode de novo quando quiser: se o plugin já estiver no GLPI escolhido, um menu oferece **atualizar**,
**configurar o provedor de IA**, **status e teste de conexão**, **sincronizar a biblioteca** e
**desinstalar**.

O log completo vai para `/tmp/ticketassistant-install-<data>.log` (legível só pelo root), com os
comandos e as saídas, nunca com a chave da API.

### Modo não interativo

```bash
OPENAI_API_KEY=sk-... sudo -E bash install.sh --yes --glpi /var/www/glpi \
    --provider openai --model gpt-5-mini --api-key-env OPENAI_API_KEY --cron yes --poppler yes
```

| Opção | Significado |
|---|---|
| `--glpi CAMINHO` | pasta do GLPI (padrão: detectada; obrigatória quando há mais de uma; com `--docker`, dentro do contêiner) |
| `--docker CONTÊINER` | GLPI neste contêiner em execução (Docker, Podman) |
| `--php CAMINHO` | binário do PHP que roda o console do GLPI |
| `--web-user USUÁRIO` | dono dos arquivos do GLPI (também num contêiner) |
| `--glpi-user LOGIN` | login de um super-admin do GLPI usado na instalação (padrão `glpi`) |
| `--mode copy\|link` | copiar o plugin (padrão) ou vinculá-lo ao repositório |
| `--provider ID` | `openai`, `azure`, `anthropic`, `gemini`, `mistral`, `openrouter`, `ollama`, `compatible` |
| `--base-url URL`, `--model NOME`, `--api-version VER` | configuração da conexão |
| `--api-key-env VAR` | variável de ambiente com a chave da API |
| `--skip-ai` | não configurar o provedor |
| `--sync yes\|no` | montar a biblioteca de chamados resolvidos (padrão yes) |
| `--cron yes\|no` | criar `/etc/cron.d/glpi-cron` (contêiner: `glpi-cron-CONTÊINER`) quando faltar |
| `--poppler yes\|no` | instalar o poppler-utils quando faltar |
| `--demo` | dados de demonstração (só instâncias de teste) |
| `--status`, `--configure`, `--uninstall` | executar só essa parte |
| `--lang en\|pt`, `--no-color`, `-y/--yes` | interface |

## Instalador para Windows

No PowerShell (o Windows PowerShell 5.1, que já vem no sistema, ou o PowerShell 7), a partir do
repositório clonado:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

As mesmas etapas do Linux, adaptadas ao Windows:

- o GLPI é procurado no XAMPP, WAMP, Laragon, IIS (sites e diretórios virtuais do
  `applicationHost.config`), Apache 2.4 e nas pastas web habituais de cada disco fixo;
- o PHP ao lado do GLPI tem preferência (o PHP do mesmo XAMPP, WAMP ou Laragon), depois o do `PATH`,
  do scoop, do Chocolatey e o PHP usado pelo IIS;
- cópia com o `robocopy`, ou vínculo com uma junção de diretório (não precisa de administrador); os
  backups vão para `%ProgramData%\glpi-ticketassistant\backups`;
- o cron vira uma tarefa agendada **GLPI cron** que executa `php.exe front\cron.php` a cada minuto
  como SYSTEM;
- o poppler é procurado (scoop, Chocolatey, `C:\poppler*`, `PATH`) e sua pasta é salva na
  configuração, porque um servidor web rodando como serviço não enxerga o `PATH` da sua sessão;
- quando uma pasta exige (`C:\inetpub`, `Arquivos de Programas`) ou para a tarefa agendada, o
  instalador oferece reiniciar como administrador.

Opções: `-GlpiPath`, `-Docker`, `-Php`, `-WebUser`, `-Mode Copy|Link`, `-GlpiUser`, `-Provider`,
`-BaseUrl`, `-Model`, `-ApiVersion`, `-ApiKeyEnv`, `-SkipAi`, `-Sync yes|no`, `-Cron yes|no`, `-Demo`,
`-Status`, `-Configure`, `-Uninstall`, `-Lang en|pt`, `-Yes`, `-NoColor`, `-Help`.

```powershell
$env:OPENAI_API_KEY = 'sk-...'
.\install.ps1 -Yes -GlpiPath C:\xampp\htdocs\glpi -Provider openai -Model gpt-5-mini -ApiKeyEnv OPENAI_API_KEY -Cron yes
```

## GLPI em um contêiner

Os dois instaladores também funcionam quando o GLPI roda num contêiner: Docker ou Podman no Linux,
Docker Desktop ou Podman no Windows. Eles procuram os contêineres em execução cujo PHP enxerga um
GLPI nas pastas habituais (`/var/www/glpi`, `/var/www/html`, `/usr/share/glpi`…), listam esses GLPI
ao lado das instalações locais (`GLPI 11.0.11  contêiner glpi → /var/www/glpi`) e trabalham pelo
comando de contêineres:

- **Leitura**: um pequeno script PHP, só de leitura, executado com `docker exec` em cada contêiner em
  execução, encontra o GLPI, a versão dele e o plugin já instalado. Só o PHP do contêiner é usado,
  então qualquer imagem do GLPI serve.
- **Console**: `docker exec -u <dono dos arquivos do GLPI>` (`www-data` na imagem oficial) executa o
  console do GLPI; a chave da API passa pela entrada padrão do `docker exec -i`, nunca numa linha de
  comando. O host não precisa de PHP.
- **Arquivos**: o plugin é copiado com `docker cp` para uma pasta nova ao lado da antiga, que ela
  substitui de uma vez; dono e permissões são ajustados como root no contêiner. Antes, a versão
  anterior é guardada no host.
- **Mantido quando o contêiner é recriado**: o plugin vai para onde o GLPI já o encontra; senão para
  `plugins/` quando essa pasta está num volume; senão para a pasta marketplace do GLPI quando ela está
  num volume, como na imagem oficial `glpi/glpi` do GLPI 11 e 12 (`/var/glpi/marketplace`). Quando
  nenhuma das duas está, o instalador avisa: atualizar a imagem recria o contêiner e remove o plugin;
  monte a pasta como volume ou rode o instalador de novo.
- **Montado do host** (um checkout de desenvolvimento): a pasta é usada como está, nada é copiado por
  cima.
- **Endereço da IA**: dentro do contêiner, `localhost` é o próprio contêiner. Para um servidor local
  (Ollama, LM Studio…) o instalador propõe o endereço do host visto do contêiner
  (`host.docker.internal` no Docker Desktop, senão o gateway da rede do contêiner, ex.: `172.17.0.1`);
  o servidor precisa escutar nessa interface (`OLLAMA_HOST=0.0.0.0`).
- **Cron**: a imagem oficial já executa o cron do GLPI (um job do supervisor), e o instalador o
  encontra. Senão, no Linux, ele oferece `/etc/cron.d/glpi-cron-<contêiner>`, que executa
  `docker exec -u www-data <contêiner> php /var/www/glpi/front/cron.php` a cada minuto; no Windows ele
  mostra esse comando para uma tarefa agendada.
- **Ferramentas de PDF**: o `poppler-utils` é procurado no contêiner. Adicione-o à imagem: um pacote
  instalado num contêiner em execução se perde quando o contêiner é recriado.
- **Permissões**: no Linux não é preciso root quando o usuário pode usar o `docker` (grupo `docker`)
  ou o Podman sem root.

```bash
bash install.sh --docker glpi                      # este contêiner, com todas as perguntas
bash install.sh --yes --docker glpi --provider ollama --base-url http://172.17.0.1:11434/v1 --model gemma3
```

```powershell
.\install.ps1 -Docker glpi
.\install.ps1 -Yes -Docker glpi -Provider ollama -BaseUrl http://host.docker.internal:11434/v1 -Model gemma3
```

O comando de contêineres é o `docker`, ou o `podman` quando falta o Docker; `TICKETASSISTANT_DOCKER`
escolhe outro. O job "Docker" do CI instala o plugin assim nas imagens oficiais do GLPI 10, 11 e 12.

## Instalação manual

1. Obtenha o plugin:
   - pacote de release `ticketassistant-x.y.z.tar.bz2` (ou `.zip`) extraído em `plugins/`, ou
   - `git clone` e cópia da pasta para `plugins/ticketassistant` (a pasta **precisa** se chamar
     `ticketassistant`).
2. Entregue os arquivos ao usuário do servidor web, ex.: `chown -R www-data: plugins/ticketassistant`.
3. Instale e habilite: **Configurar > Plugins** no GLPI, ou como o usuário do servidor web:
   ```bash
   sudo -u www-data php bin/console plugin:install --username=glpi ticketassistant
   sudo -u www-data php bin/console plugin:activate ticketassistant
   ```
4. Configure em **Configurar > AI Ticket Assistant** (veja [CONFIGURACAO.md](CONFIGURACAO.md)) ou pelo
   console:
   ```bash
   printf '%s' "$CHAVE" | sudo -u www-data php bin/console plugins:ticketassistant:configure \
       --provider=openai --model=gpt-5-mini --api-key-stdin --enable --test
   ```

## Ações automáticas (cron)

| Ação | Frequência | O que faz |
|---|---|---|
| `aisync` | diária | sincroniza a biblioteca de chamados resolvidos (sem chamar a IA) |
| `ailearn` | de hora em hora | destilação por IA e embeddings dos casos novos, quando habilitados, dentro do orçamento diário das ações automáticas |
| `aitriage` | a cada 5 minutos | classifica os chamados enfileirados na abertura, quando a triagem automática está habilitada |
| `aipurge` | diária | aplica os prazos de retenção do log |

Elas são registradas em modo CLI: o GLPI só as executa quando o cron dele roda pela linha de comando.

- Linux: `* * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php` em `/etc/cron.d/glpi-cron`.
- Windows: tarefa agendada a cada minuto executando `php.exe C:\...\glpi\front\cron.php`.
- Docker: a imagem oficial já o executa no contêiner; senão,
  `docker exec -u www-data <contêiner> php front/cron.php` a partir do host (veja
  [GLPI em um contêiner](#glpi-em-um-contêiner)).

Confira em **Configurar > Ações automáticas** (filtre por "AI Ticket Assistant"): a data da última
execução precisa avançar. Para rodar uma na mão: `php front/cron.php --force aisync`.

## Direitos

O direito **Assistente de IA** (Administração > Perfis > aba "Assistente de IA") mostra os botões nos
chamados. Na primeira instalação ele é dado aos perfis da interface padrão que podem atualizar
chamados ou adicionar acompanhamentos aos chamados de outras pessoas; reinstalações posteriores
mantêm as suas escolhas.

| Página ou ação | Direito necessário |
|---|---|
| Botões nos chamados | Assistente de IA (leitura) e o direito de ver o chamado |
| Aplicar uma classificação | atualizar o chamado |
| Criar artigo na base de conhecimento | criar artigos da base de conhecimento |
| Acompanhamentos e tarefas privados no contexto | ver acompanhamentos / tarefas privados (política padrão) |
| Página de configuração, teste de conexão | atualizar a configuração geral |
| Páginas de Aprendizado e Estatísticas | ler a configuração geral |
| Orientações de IA de uma categoria | atualizar categorias ITIL |

## Atualização

- Com o instalador: rode de novo e escolha **Atualizar**. A versão anterior é guardada antes.
- Na mão: substitua os arquivos de `plugins/ticketassistant` e, em **Configurar > Plugins**, clique em
  **Atualizar** (ou `php bin/console plugin:install --force --username=glpi ticketassistant` e
  `plugin:activate`). Dados e configuração são mantidos; opções novas recebem o valor padrão.

## Desinstalação

O instalador (**Desinstalar**) ou **Configurar > Plugins > Desinstalar** apaga as tabelas do plugin
(log, biblioteca, sugestões, orientações de IA), a configuração inclusive as chaves de API, os direitos
e as ações automáticas. Chamados, acompanhamentos e artigos da base de conhecimento criados com o
assistente são itens comuns do GLPI e permanecem.

## Problemas comuns

| Sintoma | Verifique |
|---|---|
| Nenhum botão "Assistente de IA" nos chamados | plugin habilitado; selo **ATIVO** na página de configuração (assistente habilitado e conexão completa); direito **Assistente de IA** no perfil; chamados fechados só oferecem o artigo |
| "Melhorar meu texto" não aparece no formulário de resposta | ação habilitada na aba **Ações**; no GLPI 11.0.9+ os formulários carregam sob demanda: espere o editor |
| O teste de conexão falha | mensagem do provedor ao lado do botão; o endereço precisa ser acessível **a partir do servidor do GLPI**; proxy do GLPI; veja [OPERATIONS.md](../OPERATIONS.md#errors) |
| "Outbound URL refused" | `TICKETASSISTANT_URL_ALLOWLIST` está definida e não cobre o endereço, ou o endereço é de metadados de nuvem |
| PDFs ignorados | aba **Diagnóstico**: `pdftotext`/`pdftoppm`, `shell_exec`; o leitor embutido só lê PDFs digitais |
| Comando "plugins:ticketassistant:…" não definido | o plugin não está habilitado: `plugin:activate ticketassistant` |
| O console se recusa a rodar como root | rode como o usuário do servidor web (`sudo -u www-data php bin/console …`) |
| Ações automáticas nunca rodam | cron do GLPI em modo CLI (veja acima) |
