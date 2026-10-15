<#
.SYNOPSIS
    GLPI AI Ticket Assistant — installer for Windows.

.DESCRIPTION
    From the cloned repository, in PowerShell:

        powershell -ExecutionPolicy Bypass -File .\install.ps1

    Finds the GLPI installations of the machine (XAMPP, WAMP, Laragon, IIS,
    Apache…) and of its containers (Docker Desktop, Podman), copies (or links)
    the plugin, installs and enables it with the
    GLPI console, connects the AI provider, builds the library of solved
    tickets and checks the scheduled task of the GLPI cron and the PDF tools.
    Nothing is changed without asking (or -Yes).

    Windows PowerShell 5.1 and PowerShell 7+.
    SPDX-License-Identifier: GPL-2.0-or-later

.EXAMPLE
    .\install.ps1
    Interactive installation (menus, arrows, Enter).

.EXAMPLE
    $env:OPENAI_API_KEY = 'sk-...'
    .\install.ps1 -Yes -GlpiPath C:\xampp\htdocs\glpi -Provider openai -Model gpt-5-mini -ApiKeyEnv OPENAI_API_KEY -Cron yes
    Unattended installation.

.EXAMPLE
    .\install.ps1 -Yes -Docker glpi -Provider ollama -BaseUrl http://host.docker.internal:11434/v1 -Model gemma3
    Unattended installation in the container "glpi" (Docker Desktop).
#>
[CmdletBinding()]
param(
    [string]$GlpiPath = '',
    [string]$Docker = '',
    [string]$Php = '',
    [string]$WebUser = '',
    [ValidateSet('', 'Copy', 'Link')][string]$Mode = '',
    [string]$GlpiUser = '',
    [ValidateSet('', 'openai', 'azure', 'anthropic', 'gemini', 'mistral', 'openrouter', 'ollama', 'compatible')][string]$Provider = '',
    [string]$BaseUrl = '',
    [string]$Model = '',
    [string]$ApiVersion = '',
    [string]$ApiKeyEnv = '',
    [switch]$SkipAi,
    [ValidateSet('', 'yes', 'no')][string]$Sync = '',
    [ValidateSet('', 'yes', 'no')][string]$Cron = '',
    [switch]$Demo,
    [switch]$Status,
    [switch]$Configure,
    [switch]$Uninstall,
    [ValidateSet('', 'en', 'pt', 'fr')][string]$Lang = '',
    [switch]$Yes,
    [switch]$NoColor,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$ScriptParameters = $PSBoundParameters
$PluginKey = 'ticketassistant'
$RepoDir = Split-Path -Parent $MyInvocation.MyCommand.Path

function Get-PhpDefine([string]$Name, [string]$File) {
    if (-not (Test-Path -LiteralPath $File)) { return '' }
    $m = Select-String -LiteralPath $File -Pattern ("define\('" + $Name + "',\s*'([^']*)'\)") -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($m) { return $m.Matches[0].Groups[1].Value }
    return ''
}

$PluginVersion = Get-PhpDefine 'PLUGIN_TICKETASSISTANT_VERSION' (Join-Path $RepoDir 'setup.php')
$MinGlpi = Get-PhpDefine 'PLUGIN_TICKETASSISTANT_MIN_GLPI' (Join-Path $RepoDir 'setup.php')
$MaxGlpi = Get-PhpDefine 'PLUGIN_TICKETASSISTANT_MAX_GLPI' (Join-Path $RepoDir 'setup.php')
$LogFile = Join-Path ([IO.Path]::GetTempPath()) ('ticketassistant-install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')

# Folders and files of the repository that are not part of the plugin
$ExcludeDirs = @('.git', '.github', 'docs', 'tests', 'tools', 'vendor', 'node_modules', '.phpunit.cache')
$ExcludeFiles = @('install.sh', 'install.ps1', 'composer.json', 'composer.lock', 'phpunit.xml.dist', '.phpunit.result.cache',
    '.gitattributes', '.gitignore', '.editorconfig', '.php-cs-fixer.dist.php', '.php-cs-fixer.cache', 'CONTRIBUTING.md', '*.log')

$Providers = @('openai', 'azure', 'anthropic', 'gemini', 'mistral', 'openrouter', 'ollama', 'compatible')
$ProviderUrl = @{
    openai = 'https://api.openai.com/v1'; azure = ''; anthropic = 'https://api.anthropic.com'
    gemini = 'https://generativelanguage.googleapis.com/v1beta/openai'; mistral = 'https://api.mistral.ai/v1'
    openrouter = 'https://openrouter.ai/api/v1'; ollama = 'http://localhost:11434/v1'; compatible = 'http://localhost:1234/v1'
}
$ProviderKeysPage = @{
    openai = 'https://platform.openai.com/api-keys'; azure = 'Azure portal > Keys and Endpoint'
    anthropic = 'https://console.anthropic.com/settings/keys'; gemini = 'https://aistudio.google.com/apikey'
    mistral = 'https://console.mistral.ai/api-keys'; openrouter = 'https://openrouter.ai/keys'
}
$ProviderModels = @{
    openai = 'gpt-5-mini gpt-5.4 gpt-4.1-mini'; anthropic = 'claude-sonnet-5-5 claude-haiku-5-5'
    gemini = 'gemini-2.5-flash gemini-2.5-pro'; mistral = 'mistral-medium-latest pixtral-large-latest'
    openrouter = 'openai/gpt-5-mini anthropic/claude-sonnet-5-5'; ollama = 'qwen2.5vl gemma3 llama3.2-vision'
}

# -----------------------------------------------------------------------------
# Messages (English, Brazilian Portuguese, French)
# -----------------------------------------------------------------------------
$MsgEn = @{
    subtitle         = 'Installer for Windows'
    step_checks      = 'Checking the system'
    step_glpi        = 'Locating GLPI'
    step_plugin      = 'Installing the plugin'
    step_ai          = 'Connecting the AI'
    step_library     = 'Learning from solved tickets'
    step_extras      = 'Automatic actions and PDF tools'
    not_repo         = 'Run this script from the cloned repository (setup.php of the plugin not found next to it).'
    not_admin        = 'Not running as administrator: writing into folders such as C:\inetpub or Program Files and creating the scheduled task of the cron need it.'
    elevate          = 'Restart the installer as administrator?'
    php_found        = 'PHP {0}  {1}'
    php_missing      = 'PHP not found. Install it (XAMPP, WAMP, Laragon, windows.php.net) or use -Php C:\path\php.exe.'
    ext_ok           = 'PHP extensions: {0}'
    ext_missing      = 'Missing PHP extensions on the command line: {0}'
    ext_hint         = 'Enable them in the php.ini used by the web server too (extension=curl, mbstring, gd, zip) and restart it.'
    plugin_repo      = 'Plugin {0} from {1}'
    searching        = 'Searching for GLPI installations'
    found_n          = '{0} GLPI installation(s) found'
    none_found       = 'No GLPI installation found automatically.'
    choose_glpi      = 'Which GLPI should receive the plugin?'
    other_path       = "Another folder…`ttype the path of GLPI"
    type_path        = 'Folder of GLPI (the one that contains bin\console)'
    not_glpi         = '{0} is not a GLPI folder (bin\console and src\Ticket.php not found).'
    glpi_too_old     = 'GLPI {0} is not supported: the plugin needs GLPI {1} or later.'
    glpi_untested    = 'GLPI {0} is newer than the versions tested (up to {1}): it may work, report any problem.'
    glpi_selected    = 'GLPI {0}  {1}'
    plugin_none      = 'plugin not installed'
    plugin_files     = 'plugin {0}'
    plugin_link      = 'plugin linked to {0}'
    php_too_old      = 'GLPI {0} needs PHP {1} or later, but {2} is PHP {3}.'
    choose_php       = 'Which PHP should run the GLPI console?'
    plugins_not_writable = 'Cannot write in {0}.'
    menu_title       = 'The plugin {0} is already in this GLPI. What do you want to do?'
    m_update         = "Update to version {0}`tcopies the files and runs the migrations"
    m_reinstall      = "Reinstall version {0}`tcopies the files again, keeps the data"
    m_configure      = "Configure the AI provider`tprovider, model, key, connection test"
    m_status         = "Status and connection test`tconnection, library, automatic actions"
    m_sync           = "Synchronize the library of solved tickets`tno AI call, no cost"
    m_uninstall      = "Uninstall`tremoves the plugin, its data and its configuration"
    m_exit           = 'Exit'
    mode_ask         = 'How should the plugin be placed in GLPI?'
    mode_copy        = "Copy the files`trecommended for production"
    mode_link        = "Link to this repository`tdevelopment: a git pull updates GLPI (junction)"
    in_place         = 'The plugin files are already in {0}'
    backing_up       = 'Saving the previous version'
    backup_done      = 'Previous version saved in {0}'
    copying          = 'Copying the plugin to {0}'
    linking          = 'Linking {0} to the repository'
    login_ask        = 'Login of a GLPI super-admin (used during the installation)'
    installing       = 'Installing in GLPI (tables, rights, automatic actions)'
    activating       = 'Enabling the plugin'
    verifying        = 'Checking that GLPI loaded the plugin'
    console_error    = 'The GLPI console reported an error'
    retry_login      = 'Try again with another login?'
    plugin_inactive  = 'GLPI has not installed or enabled the plugin yet.'
    install_now      = 'Install and enable it now?'
    ai_intro         = 'The assistant needs an AI provider: a cloud service (with a key) or a local server such as Ollama. Nothing is sent before a technician clicks a button.'
    ai_now           = 'Configure the AI provider now?'
    ai_current       = 'Current connection: {0}, model "{1}", {2}.'
    ai_change        = 'Change the AI connection?'
    state_ready      = 'ready'
    choose_provider  = 'Which AI provider?'
    p_openai         = "OpenAI`tGPT models: vision, PDF files, embeddings"
    p_azure          = "Azure OpenAI`tdeployments of your Azure resource"
    p_anthropic      = "Anthropic (Claude)`tvision and PDF files; embeddings need a second provider"
    p_gemini         = "Google Gemini`tvision and embeddings"
    p_mistral        = "Mistral AI`tPixtral reads images; embeddings"
    p_openrouter     = "OpenRouter`tone key, hundreds of models"
    p_ollama         = "Ollama`tlocal models: no key, no cost, data stays in house"
    p_compatible     = "Other OpenAI-compatible API`tLM Studio, vLLM, LiteLLM, Groq, DeepSeek…"
    azure_url        = 'Address of the Azure resource (https://<resource>.openai.azure.com)'
    azure_version    = 'API version ("v1" for the new v1 API)'
    base_url_ask     = 'Address of the API (as seen from the GLPI server)'
    key_where        = 'Create a key at {0}'
    key_ask          = 'API key (hidden; paste it with Ctrl+V or the right button)'
    key_optional     = 'API key (optional for this provider: Enter to skip)'
    key_keep         = 'Keep the API key already saved?'
    key_empty        = 'This provider needs a key.'
    saving           = 'Saving the connection'
    listing          = 'Asking the provider for its models'
    choose_model     = 'Which model?'
    model_ask        = 'Model name'
    deployment_ask   = 'Deployment name (Azure)'
    model_hint       = 'Suggestions: {0}'
    models_none      = 'The provider did not return a list of models: type the name.'
    vision_tip       = 'Prefer a model that reads images: tickets often carry screenshots.'
    testing          = 'Testing the connection'
    test_menu        = 'The connection test failed. What now?'
    tf_retry         = 'Test again'
    tf_change        = 'Change the settings'
    tf_skip          = 'Continue, fix it later in Setup > AI Ticket Assistant'
    enabling_ai      = 'Enabling the assistant'
    personalize      = 'Personalize the answers now (organization, language)?'
    org_ask          = 'Name of the organization or service desk (used in the answers)'
    lang_ask         = 'Language of the answers'
    lang_auto        = 'Same language as the ticket'
    costs_ask        = 'Set the token prices and the daily budget now? (needed for the cost limits)'
    price_in         = 'Price per million input tokens (US$, from the price list of the model)'
    price_out        = 'Price per million output tokens (US$)'
    budget           = 'Daily budget of the technicians (US$, 0 = none)'
    saving_prefs     = 'Saving the preferences'
    sync_ask         = 'Build the library of solved tickets now? (no AI call, no cost)'
    syncing          = 'Reading the solved and closed tickets'
    cron_found       = 'Scheduled task of the GLPI cron found: the automatic actions run in the background.'
    cron_missing     = 'No scheduled task runs the automatic actions of GLPI: the library sync (aisync), learning (ailearn), triage (aitriage) and log purge (aipurge) run in CLI mode.'
    cron_create      = 'Create the scheduled task "{0}" (every minute, as SYSTEM)?'
    cron_created     = 'Scheduled task created: {0}'
    cron_manual      = 'Create a scheduled task running every minute:'
    poppler_ok       = 'poppler found ({0}): complex and scanned PDFs are read.'
    poppler_path_set = 'Folder of poppler saved in the configuration'
    poppler_no_render = 'pdftoppm.exe is not in that folder: scanned PDFs are not read.'
    poppler_missing  = 'poppler is not installed: the built-in reader extracts the text of digital PDFs, but scanned PDFs are not read. To read them, install poppler (e.g. scoop install poppler) and set its bin folder in Setup > AI Ticket Assistant > Context and attachments.'
    demo_ask         = 'Create demonstration data (an entity, categories and 43 tickets)? Only on a TEST instance.'
    demo_running     = 'Creating the demonstration data'
    summary_title    = 'Done'
    s_glpi           = 'GLPI'
    s_plugin         = 'Plugin'
    s_ai             = 'AI'
    s_library        = 'Library'
    s_cron           = 'Cron'
    s_pdf            = 'PDF'
    s_log            = 'Log'
    mode_copy_short  = 'copied'
    mode_link_short  = 'linked to the repository'
    ai_not_configured = 'not configured'
    test_ok          = 'connection OK'
    test_failed      = 'connection test failed'
    cases            = '{0} solved tickets'
    skipped          = 'skipped'
    found            = 'found'
    missing          = 'missing'
    created          = 'created'
    builtin_reader   = 'built-in reader (digital PDFs)'
    next_title       = 'Next steps'
    next_1           = 'Review every setting in GLPI > Setup > AI Ticket Assistant.'
    next_2           = 'Technician profiles received the right "AI assistant": adjust it in Administration > Profiles.'
    next_3           = 'Open a ticket and click "AI assistant" at the bottom of the timeline.'
    un_warning       = 'Uninstalling deletes the data of the plugin: analysis log, library of solved tickets, suggestions, AI guidelines of the categories and its configuration, API keys included.'
    un_confirm       = 'Type {0} to confirm'
    un_cancelled     = 'Uninstallation cancelled.'
    deactivating     = 'Disabling the plugin'
    uninstalling     = 'Uninstalling the plugin'
    remove_folder    = 'Also delete the folder {0}? (a backup is kept)'
    removed          = 'Folder removed; backup in {0}'
    uninstalled      = 'The plugin was uninstalled.'
    cancelled        = 'Cancelled.'
    log_at           = 'Full log: {0}'
    unattended_glpi  = 'Unattended mode: use -GlpiPath or -Docker (found: {0}).'
    php_missing_docker = 'PHP not found on this computer: only a GLPI in a container can receive the plugin.'
    docker_where     = 'container {0} → {1}'
    docker_unavailable = 'Docker (or Podman) does not answer: is Docker Desktop installed and running?'
    docker_no_access = 'Docker (or Podman) is installed but does not answer: start Docker Desktop to search the containers too.'
    docker_no_glpi   = 'No GLPI found in the container {0} (is it running?): use -GlpiPath, the folder of GLPI inside the container.'
    docker_not_glpi  = '{0} is not a GLPI folder in the container {1}.'
    docker_php       = 'PHP {0} in the container'
    docker_ext_hint  = 'Add them to the image of the container.'
    web_user         = 'Web server user: {0}'
    web_user_ask     = 'Which user runs the web server in the container (owner of the GLPI files)?'
    perms            = 'Owner {0} and permissions'
    docker_no_link   = 'A container cannot be linked to this repository: the files are copied.'
    docker_mounted   = 'The folder {0} of the container is mounted from {1}: it is used as it is (update the files there).'
    docker_mounted_kept = 'The folder {0} of the container is mounted from {1}: its files are left in place.'
    docker_volume    = 'Plugin folder on a volume of the container: {0}'
    docker_no_volume = 'The folder {0} of the container is not on a volume: recreating the container (after an image update, for example) removes the plugin. Mount it as a volume, or run this installer again after recreating the container.'
    docker_url_hint  = 'Inside the container, localhost is the container itself: use the address of this computer as seen from the container ({0}). The AI server must accept those connections (Ollama: OLLAMA_HOST=0.0.0.0).'
    docker_cron_found = 'Cron found in the container ({0}): the automatic actions run in the background.'
    docker_cron_missing = 'No cron runs the automatic actions of GLPI in the container: the library sync (aisync), learning (ailearn), triage (aitriage) and log purge (aipurge) run in CLI mode.'
    docker_cron_manual = 'Run this command every minute (a scheduled task of this computer, or a cron job in the image):'
    docker_poppler_ok = 'poppler-utils found in the container: complex and scanned PDFs are read.'
    docker_poppler_missing = 'poppler-utils is not in the container: the built-in reader extracts the text of digital PDFs, but scanned PDFs are not read. Add poppler-utils to the image (packages installed in a running container are lost when it is recreated).'
    mode_mount_short = 'folder mounted from this computer'
    menu_hint        = '↑↓ move · Enter choose'
    filter_hint      = '↑↓ move · type to filter · Enter choose · Esc type a name'
    filter_label     = 'Filter'
    n_of_m           = '{0} of {1}'
    yes              = 'Yes'
    no               = 'No'
}
$MsgPt = @{
    subtitle         = 'Instalador para Windows'
    step_checks      = 'Verificando o sistema'
    step_glpi        = 'Localizando o GLPI'
    step_plugin      = 'Instalando o plugin'
    step_ai          = 'Conectando a IA'
    step_library     = 'Aprendendo com os chamados resolvidos'
    step_extras      = 'Ações automáticas e ferramentas de PDF'
    not_repo         = 'Execute este script a partir do repositório clonado (o setup.php do plugin não está ao lado dele).'
    not_admin        = 'Sem privilégios de administrador: gravar em pastas como C:\inetpub ou Arquivos de Programas e criar a tarefa agendada do cron exigem isso.'
    elevate          = 'Reiniciar o instalador como administrador?'
    php_missing      = 'PHP não encontrado. Instale (XAMPP, WAMP, Laragon, windows.php.net) ou use -Php C:\caminho\php.exe.'
    ext_ok           = 'Extensões do PHP: {0}'
    ext_missing      = 'Extensões do PHP ausentes na linha de comando: {0}'
    ext_hint         = 'Habilite-as também no php.ini usado pelo servidor web (extension=curl, mbstring, gd, zip) e reinicie-o.'
    plugin_repo      = 'Plugin {0} de {1}'
    searching        = 'Procurando instalações do GLPI'
    found_n          = '{0} instalação(ões) do GLPI encontrada(s)'
    none_found       = 'Nenhuma instalação do GLPI encontrada automaticamente.'
    choose_glpi      = 'Em qual GLPI instalar o plugin?'
    other_path       = "Outra pasta…`tdigitar o caminho do GLPI"
    type_path        = 'Pasta do GLPI (a que contém bin\console)'
    not_glpi         = '{0} não é uma pasta do GLPI (bin\console e src\Ticket.php não encontrados).'
    glpi_too_old     = 'O GLPI {0} não é suportado: o plugin precisa do GLPI {1} ou mais recente.'
    glpi_untested    = 'O GLPI {0} é mais novo que as versões testadas (até {1}): deve funcionar, relate qualquer problema.'
    plugin_none      = 'plugin não instalado'
    plugin_link      = 'plugin vinculado a {0}'
    php_too_old      = 'O GLPI {0} precisa do PHP {1} ou mais recente, mas {2} é o PHP {3}.'
    choose_php       = 'Qual PHP deve executar o console do GLPI?'
    plugins_not_writable = 'Não é possível gravar em {0}.'
    menu_title       = 'O plugin {0} já está neste GLPI. O que deseja fazer?'
    m_update         = "Atualizar para a versão {0}`tcopia os arquivos e roda as migrações"
    m_reinstall      = "Reinstalar a versão {0}`tcopia os arquivos de novo, mantém os dados"
    m_configure      = "Configurar o provedor de IA`tprovedor, modelo, chave, teste de conexão"
    m_status         = "Status e teste de conexão`tconexão, biblioteca, ações automáticas"
    m_sync           = "Sincronizar a biblioteca de chamados resolvidos`tsem chamar a IA, sem custo"
    m_uninstall      = "Desinstalar`tremove o plugin, seus dados e sua configuração"
    m_exit           = 'Sair'
    mode_ask         = 'Como o plugin deve ser colocado no GLPI?'
    mode_copy        = "Copiar os arquivos`trecomendado para produção"
    mode_link        = "Vincular a este repositório`tdesenvolvimento: um git pull atualiza o GLPI (junção)"
    in_place         = 'Os arquivos do plugin já estão em {0}'
    backing_up       = 'Guardando a versão anterior'
    backup_done      = 'Versão anterior guardada em {0}'
    copying          = 'Copiando o plugin para {0}'
    linking          = 'Vinculando {0} ao repositório'
    login_ask        = 'Login de um super-admin do GLPI (usado durante a instalação)'
    installing       = 'Instalando no GLPI (tabelas, direitos, ações automáticas)'
    activating       = 'Habilitando o plugin'
    verifying        = 'Verificando se o GLPI carregou o plugin'
    console_error    = 'O console do GLPI informou um erro'
    retry_login      = 'Tentar de novo com outro login?'
    plugin_inactive  = 'O GLPI ainda não instalou ou não habilitou o plugin.'
    install_now      = 'Instalar e habilitar agora?'
    ai_intro         = 'O assistente precisa de um provedor de IA: um serviço em nuvem (com chave) ou um servidor local como o Ollama. Nada é enviado antes de um técnico clicar em um botão.'
    ai_now           = 'Configurar o provedor de IA agora?'
    ai_current       = 'Conexão atual: {0}, modelo "{1}", {2}.'
    ai_change        = 'Alterar a conexão com a IA?'
    state_ready      = 'pronta'
    choose_provider  = 'Qual provedor de IA?'
    p_openai         = "OpenAI`tmodelos GPT: visão, arquivos PDF, embeddings"
    p_azure          = "Azure OpenAI`timplantações do seu recurso Azure"
    p_anthropic      = "Anthropic (Claude)`tvisão e arquivos PDF; embeddings exigem um segundo provedor"
    p_gemini         = "Google Gemini`tvisão e embeddings"
    p_mistral        = "Mistral AI`to Pixtral lê imagens; embeddings"
    p_openrouter     = "OpenRouter`tuma chave, centenas de modelos"
    p_ollama         = "Ollama`tmodelos locais: sem chave, sem custo, os dados ficam na casa"
    p_compatible     = "Outra API compatível com OpenAI`tLM Studio, vLLM, LiteLLM, Groq, DeepSeek…"
    azure_url        = 'Endereço do recurso Azure (https://<recurso>.openai.azure.com)'
    azure_version    = 'Versão da API ("v1" para a nova API v1)'
    base_url_ask     = 'Endereço da API (como o servidor do GLPI o enxerga)'
    key_where        = 'Crie uma chave em {0}'
    key_ask          = 'Chave da API (oculta; cole com Ctrl+V ou o botão direito)'
    key_optional     = 'Chave da API (opcional para este provedor: Enter para pular)'
    key_keep         = 'Manter a chave da API já salva?'
    key_empty        = 'Este provedor exige uma chave.'
    saving           = 'Salvando a conexão'
    listing          = 'Consultando os modelos do provedor'
    choose_model     = 'Qual modelo?'
    model_ask        = 'Nome do modelo'
    deployment_ask   = 'Nome da implantação (Azure)'
    model_hint       = 'Sugestões: {0}'
    models_none      = 'O provedor não retornou a lista de modelos: digite o nome.'
    vision_tip       = 'Prefira um modelo que leia imagens: chamados costumam trazer prints.'
    testing          = 'Testando a conexão'
    test_menu        = 'O teste de conexão falhou. E agora?'
    tf_retry         = 'Testar de novo'
    tf_change        = 'Alterar as configurações'
    tf_skip          = 'Continuar e corrigir depois em Configurar > AI Ticket Assistant'
    enabling_ai      = 'Ativando o assistente'
    personalize      = 'Personalizar as respostas agora (organização, idioma)?'
    org_ask          = 'Nome da organização ou da central de serviços (usado nas respostas)'
    lang_ask         = 'Idioma das respostas'
    lang_auto        = 'O mesmo idioma do chamado'
    costs_ask        = 'Definir agora os preços por token e o orçamento diário? (necessários para os limites de custo)'
    price_in         = 'Preço por milhão de tokens de entrada (US$, da tabela de preços do modelo)'
    price_out        = 'Preço por milhão de tokens de saída (US$)'
    budget           = 'Orçamento diário dos técnicos (US$, 0 = sem limite)'
    saving_prefs     = 'Salvando as preferências'
    sync_ask         = 'Montar agora a biblioteca de chamados resolvidos? (sem chamar a IA, sem custo)'
    syncing          = 'Lendo os chamados solucionados e fechados'
    cron_found       = 'Tarefa agendada do cron do GLPI encontrada: as ações automáticas rodam em segundo plano.'
    cron_missing     = 'Nenhuma tarefa agendada executa as ações automáticas do GLPI: a sincronização da biblioteca (aisync), o aprendizado (ailearn), a triagem (aitriage) e a limpeza do log (aipurge) rodam em modo CLI.'
    cron_create      = 'Criar a tarefa agendada "{0}" (a cada minuto, como SYSTEM)?'
    cron_created     = 'Tarefa agendada criada: {0}'
    cron_manual      = 'Crie uma tarefa agendada executando a cada minuto:'
    poppler_ok       = 'poppler encontrado ({0}): PDFs complexos e digitalizados são lidos.'
    poppler_path_set = 'Pasta do poppler salva na configuração'
    poppler_no_render = 'O pdftoppm.exe não está nessa pasta: PDFs digitalizados não são lidos.'
    poppler_missing  = 'O poppler não está instalado: o leitor embutido extrai o texto de PDFs digitais, mas PDFs digitalizados não são lidos. Para lê-los, instale o poppler (ex.: scoop install poppler) e informe a pasta bin em Configurar > AI Ticket Assistant > Contexto e anexos.'
    demo_ask         = 'Criar dados de demonstração (uma entidade, categorias e 43 chamados)? Somente em instância de TESTE.'
    demo_running     = 'Criando os dados de demonstração'
    summary_title    = 'Pronto'
    s_ai             = 'IA'
    s_library        = 'Biblioteca'
    mode_copy_short  = 'copiado'
    mode_link_short  = 'vinculado ao repositório'
    ai_not_configured = 'não configurada'
    test_ok          = 'conexão OK'
    test_failed      = 'o teste de conexão falhou'
    cases            = '{0} chamados resolvidos'
    skipped          = 'pulado'
    found            = 'encontrado'
    missing          = 'ausente'
    created          = 'criado'
    builtin_reader   = 'leitor embutido (PDFs digitais)'
    next_title       = 'Próximos passos'
    next_1           = 'Revise todas as opções em GLPI > Configurar > AI Ticket Assistant.'
    next_2           = 'Os perfis de técnico receberam o direito "AI assistant": ajuste em Administração > Perfis.'
    next_3           = 'Abra um chamado e clique em "AI assistant" no fim da linha do tempo.'
    un_warning       = 'Desinstalar apaga os dados do plugin: log de análises, biblioteca de chamados resolvidos, sugestões, orientações de IA das categorias e a configuração, inclusive as chaves de API.'
    un_confirm       = 'Digite {0} para confirmar'
    un_cancelled     = 'Desinstalação cancelada.'
    deactivating     = 'Desabilitando o plugin'
    uninstalling     = 'Desinstalando o plugin'
    remove_folder    = 'Apagar também a pasta {0}? (um backup é mantido)'
    removed          = 'Pasta removida; backup em {0}'
    uninstalled      = 'O plugin foi desinstalado.'
    cancelled        = 'Cancelado.'
    log_at           = 'Log completo: {0}'
    unattended_glpi  = 'Modo não interativo: use -GlpiPath ou -Docker (encontradas: {0}).'
    php_missing_docker = 'PHP não encontrado neste computador: só um GLPI em contêiner pode receber o plugin.'
    docker_where     = 'contêiner {0} → {1}'
    docker_unavailable = 'O Docker (ou o Podman) não responde: o Docker Desktop está instalado e em execução?'
    docker_no_access = 'O Docker (ou o Podman) está instalado, mas não responde: inicie o Docker Desktop para procurar também nos contêineres.'
    docker_no_glpi   = 'Nenhum GLPI encontrado no contêiner {0} (ele está em execução?): use -GlpiPath, a pasta do GLPI dentro do contêiner.'
    docker_not_glpi  = '{0} não é uma pasta do GLPI no contêiner {1}.'
    docker_php       = 'PHP {0} no contêiner'
    docker_ext_hint  = 'Adicione-as à imagem do contêiner.'
    web_user         = 'Usuário do servidor web: {0}'
    web_user_ask     = 'Qual usuário roda o servidor web no contêiner (dono dos arquivos do GLPI)?'
    perms            = 'Dono {0} e permissões'
    docker_no_link   = 'Um contêiner não pode ser vinculado a este repositório: os arquivos são copiados.'
    docker_mounted   = 'A pasta {0} do contêiner é montada de {1}: ela é usada como está (atualize os arquivos lá).'
    docker_mounted_kept = 'A pasta {0} do contêiner é montada de {1}: seus arquivos são mantidos.'
    docker_volume    = 'Pasta do plugin em um volume do contêiner: {0}'
    docker_no_volume = 'A pasta {0} do contêiner não está em um volume: recriar o contêiner (após atualizar a imagem, por exemplo) remove o plugin. Monte-a como volume ou rode este instalador de novo depois de recriar o contêiner.'
    docker_url_hint  = 'Dentro do contêiner, localhost é o próprio contêiner: use o endereço deste computador visto do contêiner ({0}). O servidor de IA precisa aceitar essas conexões (Ollama: OLLAMA_HOST=0.0.0.0).'
    docker_cron_found = 'Cron encontrado no contêiner ({0}): as ações automáticas rodam em segundo plano.'
    docker_cron_missing = 'Nenhum cron executa as ações automáticas do GLPI no contêiner: a sincronização da biblioteca (aisync), o aprendizado (ailearn), a triagem (aitriage) e a limpeza do log (aipurge) rodam em modo CLI.'
    docker_cron_manual = 'Execute este comando a cada minuto (uma tarefa agendada deste computador ou um cron na imagem):'
    docker_poppler_ok = 'poppler-utils encontrado no contêiner: PDFs complexos e digitalizados são lidos.'
    docker_poppler_missing = 'O poppler-utils não está no contêiner: o leitor embutido extrai o texto de PDFs digitais, mas PDFs digitalizados não são lidos. Adicione o poppler-utils à imagem (pacotes instalados em um contêiner em execução se perdem quando ele é recriado).'
    mode_mount_short = 'pasta montada deste computador'
    menu_hint        = '↑↓ mover · Enter escolher'
    filter_hint      = '↑↓ mover · digite para filtrar · Enter escolher · Esc digitar um nome'
    filter_label     = 'Filtro'
    n_of_m           = '{0} de {1}'
    yes              = 'Sim'
    no               = 'Não'
}
$MsgFr = @{
    subtitle         = 'Installateur pour Windows'
    step_checks      = 'Vérification du système'
    step_glpi        = 'Recherche de GLPI'
    step_plugin      = 'Installation du plugin'
    step_ai          = "Connexion de l'IA"
    step_library     = 'Apprentissage des tickets résolus'
    step_extras      = 'Actions automatiques et outils PDF'
    not_repo         = 'Lancez ce script depuis le dépôt cloné (setup.php du plugin introuvable à côté).'
    not_admin        = 'Pas lancé en administrateur : écrire dans des dossiers comme C:\inetpub ou Program Files et créer la tâche planifiée du cron le demandent.'
    elevate          = "Relancer l'installateur en administrateur ?"
    php_missing      = 'PHP introuvable. Installez-le (XAMPP, WAMP, Laragon, windows.php.net) ou utilisez -Php C:\chemin\php.exe.'
    ext_ok           = 'Extensions PHP : {0}'
    ext_missing      = 'Extensions PHP manquantes en ligne de commande : {0}'
    ext_hint         = 'Activez-les aussi dans le php.ini utilisé par le serveur web (extension=curl, mbstring, gd, zip) et redémarrez-le.'
    plugin_repo      = 'Plugin {0} depuis {1}'
    searching        = 'Recherche des installations de GLPI'
    found_n          = '{0} installation(s) de GLPI trouvée(s)'
    none_found       = 'Aucune installation de GLPI trouvée automatiquement.'
    choose_glpi      = 'Quel GLPI doit recevoir le plugin ?'
    other_path       = "Un autre dossier…`tsaisir le chemin de GLPI"
    type_path        = 'Dossier de GLPI (celui qui contient bin\console)'
    not_glpi         = "{0} n'est pas un dossier GLPI (bin\console et src\Ticket.php introuvables)."
    glpi_too_old     = "GLPI {0} n'est pas pris en charge : le plugin demande GLPI {1} ou plus récent."
    glpi_untested    = "GLPI {0} est plus récent que les versions testées (jusqu'à {1}) : il peut fonctionner, signalez tout problème."
    plugin_none      = 'plugin non installé'
    plugin_link      = 'plugin lié à {0}'
    php_too_old      = 'GLPI {0} demande PHP {1} ou plus récent, mais {2} est PHP {3}.'
    choose_php       = 'Quel PHP doit lancer la console GLPI ?'
    plugins_not_writable = "Impossible d'écrire dans {0}."
    menu_title       = 'Le plugin {0} est déjà dans ce GLPI. Que voulez-vous faire ?'
    m_update         = "Mettre à jour vers la version {0}`tcopie les fichiers et lance les migrations"
    m_reinstall      = "Réinstaller la version {0}`tcopie à nouveau les fichiers, garde les données"
    m_configure      = "Configurer le fournisseur d'IA`tfournisseur, modèle, clé, test de connexion"
    m_status         = "État et test de connexion`tconnexion, bibliothèque, actions automatiques"
    m_sync           = "Synchroniser la bibliothèque des tickets résolus`tsans appel à l'IA, sans coût"
    m_uninstall      = "Désinstaller`tretire le plugin, ses données et sa configuration"
    m_exit           = 'Quitter'
    mode_ask         = 'Comment placer le plugin dans GLPI ?'
    mode_copy        = "Copier les fichiers`trecommandé en production"
    mode_link        = "Lier à ce dépôt`tdéveloppement : un git pull met GLPI à jour (jonction)"
    in_place         = 'Les fichiers du plugin sont déjà dans {0}'
    backing_up       = 'Sauvegarde de la version précédente'
    backup_done      = 'Version précédente sauvegardée dans {0}'
    copying          = 'Copie du plugin vers {0}'
    linking          = 'Liaison de {0} au dépôt'
    login_ask        = "Identifiant d'un super-administrateur GLPI (utilisé pendant l'installation)"
    installing       = 'Installation dans GLPI (tables, droits, actions automatiques)'
    activating       = 'Activation du plugin'
    verifying        = 'Vérification que GLPI a chargé le plugin'
    console_error    = 'La console GLPI a signalé une erreur'
    retry_login      = 'Réessayer avec un autre identifiant ?'
    plugin_inactive  = "GLPI n'a pas encore installé ou activé le plugin."
    install_now      = "L'installer et l'activer maintenant ?"
    ai_intro         = "L'assistant a besoin d'un fournisseur d'IA : un service cloud (avec une clé) ou un serveur local comme Ollama. Rien n'est envoyé avant qu'un technicien clique sur un bouton."
    ai_now           = "Configurer le fournisseur d'IA maintenant ?"
    ai_current       = 'Connexion actuelle : {0}, modèle "{1}", {2}.'
    ai_change        = "Changer la connexion à l'IA ?"
    state_ready      = 'prête'
    choose_provider  = "Quel fournisseur d'IA ?"
    p_openai         = "OpenAI`tmodèles GPT : vision, fichiers PDF, embeddings"
    p_azure          = "Azure OpenAI`tdéploiements de votre ressource Azure"
    p_anthropic      = "Anthropic (Claude)`tvision et fichiers PDF ; les embeddings demandent un second fournisseur"
    p_gemini         = "Google Gemini`tvision et embeddings"
    p_mistral        = "Mistral AI`tPixtral lit les images ; embeddings"
    p_openrouter     = "OpenRouter`tune clé, des centaines de modèles"
    p_ollama         = "Ollama`tmodèles locaux : sans clé, sans coût, les données restent chez vous"
    p_compatible     = "Autre API compatible OpenAI`tLM Studio, vLLM, LiteLLM, Groq, DeepSeek…"
    azure_url        = 'Adresse de la ressource Azure (https://<ressource>.openai.azure.com)'
    azure_version    = 'Version de l''API ("v1" pour la nouvelle API v1)'
    base_url_ask     = "Adresse de l'API (vue depuis le serveur GLPI)"
    key_where        = 'Créez une clé sur {0}'
    key_ask          = "Clé d'API (masquée ; collez-la avec Ctrl+V ou le bouton droit)"
    key_optional     = "Clé d'API (facultative pour ce fournisseur : Entrée pour passer)"
    key_keep         = "Garder la clé d'API déjà enregistrée ?"
    key_empty        = "Ce fournisseur a besoin d'une clé."
    saving           = 'Enregistrement de la connexion'
    listing          = 'Demande de la liste des modèles au fournisseur'
    choose_model     = 'Quel modèle ?'
    model_ask        = 'Nom du modèle'
    deployment_ask   = 'Nom du déploiement (Azure)'
    model_hint       = 'Suggestions : {0}'
    models_none      = "Le fournisseur n'a pas renvoyé de liste de modèles : saisissez le nom."
    vision_tip       = "Préférez un modèle qui lit les images : les tickets contiennent souvent des captures d'écran."
    testing          = 'Test de la connexion'
    test_menu        = 'Le test de connexion a échoué. Que faire ?'
    tf_retry         = 'Tester à nouveau'
    tf_change        = 'Changer les réglages'
    tf_skip          = 'Continuer, corriger plus tard dans Configuration > AI Ticket Assistant'
    enabling_ai      = "Activation de l'assistant"
    personalize      = 'Personnaliser les réponses maintenant (organisation, langue) ?'
    org_ask          = "Nom de l'organisation ou du service d'assistance (utilisé dans les réponses)"
    lang_ask         = 'Langue des réponses'
    lang_auto        = 'La même langue que le ticket'
    costs_ask        = 'Définir les prix des tokens et le budget quotidien maintenant ? (nécessaire pour les limites de coût)'
    price_in         = 'Prix par million de tokens en entrée (US$, d''après la grille tarifaire du modèle)'
    price_out        = 'Prix par million de tokens en sortie (US$)'
    budget           = 'Budget quotidien des techniciens (US$, 0 = aucun)'
    saving_prefs     = 'Enregistrement des préférences'
    sync_ask         = "Construire la bibliothèque des tickets résolus maintenant ? (sans appel à l'IA, sans coût)"
    syncing          = 'Lecture des tickets résolus et clos'
    cron_found       = 'Tâche planifiée du cron de GLPI trouvée : les actions automatiques tournent en arrière-plan.'
    cron_missing     = "Aucune tâche planifiée ne lance les actions automatiques de GLPI : la synchronisation de la bibliothèque (aisync), l'apprentissage (ailearn), le triage (aitriage) et la purge des journaux (aipurge) tournent en mode CLI."
    cron_create      = 'Créer la tâche planifiée "{0}" (chaque minute, en tant que SYSTEM) ?'
    cron_created     = 'Tâche planifiée créée : {0}'
    cron_manual      = "Créez une tâche planifiée qui s'exécute chaque minute :"
    poppler_ok       = 'poppler trouvé ({0}) : les PDF complexes et numérisés sont lus.'
    poppler_path_set = 'Dossier de poppler enregistré dans la configuration'
    poppler_no_render = "pdftoppm.exe n'est pas dans ce dossier : les PDF numérisés ne sont pas lus."
    poppler_missing  = "poppler n'est pas installé : le lecteur intégré extrait le texte des PDF numériques, mais les PDF numérisés ne sont pas lus. Pour les lire, installez poppler (ex. scoop install poppler) et indiquez son dossier bin dans Configuration > AI Ticket Assistant > Contexte et pièces jointes."
    demo_ask         = 'Créer des données de démonstration (une entité, des catégories et 43 tickets) ? Seulement sur une instance de TEST.'
    demo_running     = 'Création des données de démonstration'
    summary_title    = 'Terminé'
    s_ai             = 'IA'
    s_library        = 'Bibliothèque'
    mode_copy_short  = 'copié'
    mode_link_short  = 'lié au dépôt'
    ai_not_configured = 'non configurée'
    test_ok          = 'connexion OK'
    test_failed      = 'test de connexion échoué'
    cases            = '{0} tickets résolus'
    skipped          = 'ignoré'
    found            = 'trouvé'
    missing          = 'absent'
    created          = 'créé'
    builtin_reader   = 'lecteur intégré (PDF numériques)'
    next_title       = 'Prochaines étapes'
    next_1           = 'Revoyez chaque réglage dans GLPI > Configuration > AI Ticket Assistant.'
    next_2           = 'Les profils des techniciens ont reçu le droit "Assistant IA" : ajustez-le dans Administration > Profils.'
    next_3           = 'Ouvrez un ticket et cliquez sur "Assistant IA" en bas de la chronologie.'
    un_warning       = "La désinstallation supprime les données du plugin : journal des analyses, bibliothèque des tickets résolus, suggestions, consignes IA des catégories et sa configuration, clés d'API comprises."
    un_confirm       = 'Saisissez {0} pour confirmer'
    un_cancelled     = 'Désinstallation annulée.'
    deactivating     = 'Désactivation du plugin'
    uninstalling     = 'Désinstallation du plugin'
    remove_folder    = 'Supprimer aussi le dossier {0} ? (une sauvegarde est conservée)'
    removed          = 'Dossier supprimé ; sauvegarde dans {0}'
    uninstalled      = 'Le plugin a été désinstallé.'
    cancelled        = 'Annulé.'
    log_at           = 'Journal complet : {0}'
    unattended_glpi  = 'Mode non interactif : utilisez -GlpiPath ou -Docker (trouvées : {0}).'
    php_missing_docker = 'PHP introuvable sur cet ordinateur : seul un GLPI dans un conteneur peut recevoir le plugin.'
    docker_where     = 'conteneur {0} → {1}'
    docker_unavailable = 'Docker (ou Podman) ne répond pas : Docker Desktop est-il installé et lancé ?'
    docker_no_access = 'Docker (ou Podman) est installé mais ne répond pas : lancez Docker Desktop pour chercher aussi dans les conteneurs.'
    docker_no_glpi   = 'Aucun GLPI trouvé dans le conteneur {0} (est-il lancé ?) : utilisez -GlpiPath, le dossier de GLPI dans le conteneur.'
    docker_not_glpi  = "{0} n'est pas un dossier GLPI dans le conteneur {1}."
    docker_php       = 'PHP {0} dans le conteneur'
    docker_ext_hint  = "Ajoutez-les à l'image du conteneur."
    web_user         = 'Utilisateur du serveur web : {0}'
    web_user_ask     = 'Quel utilisateur fait tourner le serveur web dans le conteneur (propriétaire des fichiers de GLPI) ?'
    perms            = 'Propriétaire {0} et permissions'
    docker_no_link   = 'Un conteneur ne peut pas être lié à ce dépôt : les fichiers sont copiés.'
    docker_mounted   = 'Le dossier {0} du conteneur est monté depuis {1} : il est utilisé tel quel (mettez les fichiers à jour là-bas).'
    docker_mounted_kept = 'Le dossier {0} du conteneur est monté depuis {1} : ses fichiers sont laissés en place.'
    docker_volume    = 'Dossier du plugin sur un volume du conteneur : {0}'
    docker_no_volume = "Le dossier {0} du conteneur n'est pas sur un volume : recréer le conteneur (après une mise à jour de l'image, par exemple) supprime le plugin. Montez-le comme volume, ou relancez cet installateur après avoir recréé le conteneur."
    docker_url_hint  = "Dans le conteneur, localhost est le conteneur lui-même : utilisez l'adresse de cet ordinateur vue depuis le conteneur ({0}). Le serveur d'IA doit accepter ces connexions (Ollama : OLLAMA_HOST=0.0.0.0)."
    docker_cron_found = 'Cron trouvé dans le conteneur ({0}) : les actions automatiques tournent en arrière-plan.'
    docker_cron_missing = "Aucun cron ne lance les actions automatiques de GLPI dans le conteneur : la synchronisation de la bibliothèque (aisync), l'apprentissage (ailearn), le triage (aitriage) et la purge des journaux (aipurge) tournent en mode CLI."
    docker_cron_manual = "Lancez cette commande chaque minute (une tâche planifiée de cet ordinateur, ou un cron dans l'image) :"
    docker_poppler_ok = 'poppler-utils trouvé dans le conteneur : les PDF complexes et numérisés sont lus.'
    docker_poppler_missing = "poppler-utils n'est pas dans le conteneur : le lecteur intégré extrait le texte des PDF numériques, mais les PDF numérisés ne sont pas lus. Ajoutez poppler-utils à l'image (les paquets installés dans un conteneur en cours d'exécution sont perdus quand il est recréé)."
    mode_mount_short = 'dossier monté depuis cet ordinateur'
    menu_hint        = '↑↓ déplacer · Entrée choisir'
    filter_hint      = '↑↓ déplacer · taper pour filtrer · Entrée choisir · Échap saisir un nom'
    filter_label     = 'Filtre'
    n_of_m           = '{0} sur {1}'
    yes              = 'Oui'
    no               = 'Non'
}

# Language of the installer: -Lang, else the language of Windows. The first question of an
# interactive run lets the user change it (Select-Language).
$UiLang = 'en'
if ($Lang) { $UiLang = $Lang }
elseif ((Get-UICulture).Name -like 'pt*' -or (Get-Culture).Name -like 'pt*') { $UiLang = 'pt' }
elseif ((Get-UICulture).Name -like 'fr*' -or (Get-Culture).Name -like 'fr*') { $UiLang = 'fr' }

function T([string]$Key) {
    $format = $null
    if ($script:UiLang -eq 'pt' -and $script:MsgPt.ContainsKey($Key)) { $format = $script:MsgPt[$Key] }
    elseif ($script:UiLang -eq 'fr' -and $script:MsgFr.ContainsKey($Key)) { $format = $script:MsgFr[$Key] }
    elseif ($script:MsgEn.ContainsKey($Key)) { $format = $script:MsgEn[$Key] }
    else { $format = $Key }
    if ($args.Count -gt 0) { return [string]::Format($format, [object[]]$args) }
    return $format
}

function Show-Usage {
    @"
GLPI AI Ticket Assistant $PluginVersion — installer for Windows

Usage: powershell -ExecutionPolicy Bypass -File .\install.ps1 [options]

Without options the installer asks every question in terminal menus.

  -GlpiPath PATH       GLPI folder (default: detected; with -Docker, inside the container)
  -Docker CONTAINER    GLPI in a running container (Docker Desktop, Podman; default: detected):
                       the console runs with "docker exec", the plugin is copied with "docker cp"
  -Php PATH            php.exe that runs the GLPI console (default: detected)
  -WebUser USER        container: owner of the GLPI files (default: detected)
  -Mode Copy|Link      copy the plugin (default) or link it to the repository (junction; containers: copy)
  -GlpiUser LOGIN      GLPI super-admin login used by the installation (default: glpi)
  -Provider ID         AI provider: $($Providers -join ', ')
  -BaseUrl URL         API address (Azure resource, local server…)
  -Model NAME          model (Azure: deployment name)
  -ApiVersion VER      Azure API version
  -ApiKeyEnv VAR       read the API key from the environment variable VAR
  -SkipAi              do not configure the AI provider
  -Sync yes|no         build the library of solved tickets (default: yes)
  -Cron yes|no         create the scheduled task of the GLPI cron when missing
  -Demo                create demonstration data (test instances only)
  -Status              show the state of the assistant and test the connection
  -Configure           only configure the AI provider
  -Uninstall           uninstall the plugin
  -Lang en|pt|fr       language of the installer (default: asked first; unattended: the language of Windows)
  -Yes                 unattended: accept the defaults, ask nothing
  -NoColor             plain output
  -Help                this help

The container command is docker.exe, or podman.exe when docker is missing
(`$env:TICKETASSISTANT_DOCKER to choose another one). Previous versions are saved in
%ProgramData%\glpi-ticketassistant\backups (`$env:TICKETASSISTANT_BACKUP_DIR: another folder).
"@ | Write-Host
}

# -----------------------------------------------------------------------------
# Terminal interface
# -----------------------------------------------------------------------------
$script:Interactive = $false
$script:AnsiPattern = [string][char]27 + '\[[0-9;]*m'
$script:Fancy = $false
$script:Width = 80
$script:RunOutput = ''

function Initialize-Ui {
    $redirected = $false
    try { $redirected = [Console]::IsInputRedirected -or [Console]::IsOutputRedirected } catch { $redirected = $true }
    $script:Interactive = (-not $Yes) -and (-not $redirected) -and ($Host.Name -ne 'Windows PowerShell ISE Host')
    try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { }
    try { $script:Width = [Math]::Max(40, [Math]::Min(100, $Host.UI.RawUI.WindowSize.Width)) } catch { $script:Width = 80 }
    # Windows Terminal and VS Code render every symbol; the classic console uses its font (Consolas)
    $script:Fancy = [bool]$env:WT_SESSION -or $env:TERM_PROGRAM -eq 'vscode'
    if ($script:Fancy) {
        $script:Sym = @{ ok = '✔'; err = '✖'; warn = '▲'; info = '●'; ask = '?'; pointer = '❯'; bullet = '•'; star = '✦'; dot = '·'; caret = '▏' }
        $script:Spinner = @('⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏')
        $script:Box = @('╭', '─', '╮', '│', '╰', '╯'); $script:Rule = '━'
    } else {
        $script:Sym = @{ ok = '√'; err = '×'; warn = '!'; info = 'i'; ask = '?'; pointer = '►'; bullet = '•'; star = '*'; dot = '·'; caret = '_' }
        $script:Spinner = @('|', '/', '-', '\')
        $script:Box = @('┌', '─', '┐', '│', '└', '┘'); $script:Rule = '═'
    }
}

function W([string]$Text, [string]$Color = '') {
    if ($Color -and -not $NoColor) { Write-Host $Text -NoNewline -ForegroundColor $Color } else { Write-Host $Text -NoNewline }
}
function WL([string]$Text = '', [string]$Color = '') { W $Text $Color; Write-Host '' }

function Get-Visible([string]$Text, [int]$Max) {
    if ($Max -lt 4) { return '' }
    if ($Text.Length -gt $Max) { return $Text.Substring(0, $Max - 1) + '…' }
    return $Text
}

function Show-Banner {
    WL
    $lines = @(
        '▀█▀ █ █▀▀ █▄▀ █▀▀ ▀█▀   ▄▀█ █▀ █▀ █ █▀ ▀█▀ ▄▀█ █▄ █ ▀█▀',
        ' █  █ █▄▄ █ █ ██▄  █    █▀█ ▄█ ▄█ █ ▄█  █  █▀█ █ ▀█  █ '
    )
    $palette = @('Cyan', 'Cyan', 'DarkCyan', 'Blue', 'Blue', 'Magenta', 'Magenta', 'DarkMagenta')
    foreach ($line in $lines) {
        W '   '
        $chars = $line.ToCharArray()
        for ($i = 0; $i -lt $chars.Length; $i++) {
            W ([string]$chars[$i]) $palette[[int][Math]::Floor($i * $palette.Count / $chars.Length)]
        }
        WL
    }
    WL ('   ' + $script:Sym.star + ' GLPI AI Ticket Assistant ' + $script:Sym.dot + ' ' + (T 'subtitle') + ' ' + $script:Sym.dot + ' v' + $PluginVersion) 'DarkGray'
}

$script:StepTotal = 6
function Show-Step([int]$Number, [string]$Title) {
    $label = " $Number/$($script:StepTotal)  $Title "
    $fill = [Math]::Max(3, $script:Width - $label.Length - 6)
    WL
    W (' ' + ($script:Rule * 2)) 'Cyan'; W $label 'White'; WL ($script:Rule * $fill) 'Cyan'
    WL
}

function Show-Title([string]$Title) {
    $label = " $Title "
    $fill = [Math]::Max(3, $script:Width - $label.Length - 6)
    WL
    W (' ' + ($script:Rule * 2)) 'Green'; W $label 'Green'; WL ($script:Rule * $fill) 'Green'
    WL
}

function Write-Ok([string]$Text) { W ('  ' + $script:Sym.ok + ' ') 'Green'; WL $Text }
function Write-Err([string]$Text) { W ('  ' + $script:Sym.err + ' ') 'Red'; WL $Text }
function Write-Warn([string]$Text) { W ('  ' + $script:Sym.warn + ' ') 'Yellow'; WL $Text }
function Write-Info([string]$Text) { W ('  ' + $script:Sym.info + ' ') 'Cyan'; WL $Text }
function Write-Note([string]$Text) { WL ('    ' + $Text) 'DarkGray' }

function Split-Words([string]$Text, [int]$Width) {
    $lines = New-Object System.Collections.Generic.List[string]
    $line = ''
    foreach ($word in ($Text -split '\s+' | Where-Object { $_ })) {
        if (-not $line) { $line = $word }
        elseif ($line.Length + 1 + $word.Length -gt $Width) { $lines.Add($line); $line = $word }
        else { $line += ' ' + $word }
    }
    if ($line) { $lines.Add($line) }
    return , $lines
}

function Show-Box([string]$Kind, [string]$Title, [string[]]$Paragraphs) {
    $color = switch ($Kind) { 'ok' { 'Green' } 'warn' { 'Yellow' } 'error' { 'Red' } default { 'Cyan' } }
    $inner = $script:Width - 8
    $head = ''
    if ($Title) { $head = " $Title " }
    $b = $script:Box
    W ('  ' + $b[0] + $b[1]) $color; W $head $color; WL (($b[1] * [Math]::Max(1, $inner + 1 - $head.Length)) + $b[2]) $color
    foreach ($paragraph in $Paragraphs) {
        foreach ($line in (Split-Words $paragraph $inner)) {
            W ('  ' + $b[3] + ' ') $color; W ($line.PadRight($inner)); WL (' ' + $b[3]) $color
        }
    }
    WL ('  ' + $b[4] + ($b[1] * ($inner + 2)) + $b[5]) $color
}

function Show-KeyValue([string]$Label, [string]$Value, [string]$Mark = '') {
    W '   '; W $Label 'White'; W (' ' + ('.' * [Math]::Max(1, 14 - $Label.Length)) + ' ') 'DarkGray'
    switch ($Mark) {
        'ok' { W ($script:Sym.ok + ' ') 'Green' }
        'warn' { W ($script:Sym.warn + ' ') 'Yellow' }
        'error' { W ($script:Sym.err + ' ') 'Red' }
    }
    WL $Value
}

# Every key press goes through this function (the tests replace it)
function Read-UiKey { return [Console]::ReadKey($true) }

# Console access goes through these functions (no console: tests, redirected output)
function Get-WindowWidth { try { return [Console]::WindowWidth } catch { return 80 } }
function Get-WindowHeight { try { return [Console]::WindowHeight } catch { return 25 } }
function Get-CursorTop { try { return [Console]::CursorTop } catch { return 0 } }
function Get-CursorLeft { try { return [Console]::CursorLeft } catch { return 0 } }
function Set-CursorPos([int]$Left, [int]$Top) { try { [Console]::SetCursorPosition([Math]::Max(0, $Left), [Math]::Max(0, $Top)) } catch { } }
function Set-CursorVisible([bool]$Visible) { try { [Console]::CursorVisible = $Visible } catch { } }

function Write-Question([string]$Question) { WL; W ('  ' + $script:Sym.ask + ' ') 'Cyan'; WL $Question 'White' }

function Clear-Lines([int]$Top, [int]$Count) {
    Set-CursorPos 0 $Top
    for ($i = 0; $i -lt $Count; $i++) { Write-Host (' ' * ((Get-WindowWidth) - 1)) }
    Set-CursorPos 0 $Top
}

function Write-Answer([string]$Answer) { W ('    ' + $script:Sym.pointer + ' ') 'Green'; WL $Answer }

function Show-Menu([string]$Question, [string[]]$Items, [int]$Default = 0) {
    Write-Question $Question
    if (-not $script:Interactive) {
        Write-Answer (($Items[$Default] -split "`t")[0])
        return $Default
    }
    $selected = $Default
    $count = $Items.Count
    $top = (Get-CursorTop)
    $first = $true
    Set-CursorVisible $false
    try {
        while ($true) {
            Set-CursorPos 0 $top
            for ($i = 0; $i -lt $count; $i++) {
                $parts = $Items[$i] -split "`t", 2
                $label = $parts[0]
                $room = $script:Width - $label.Length - 12
                $width = 0
                if ($i -eq $selected) { W ('   ' + $script:Sym.pointer + ' ') 'Cyan'; W $label 'White' } else { W ('     ' + $label) }
                $width = 5 + $label.Length
                if ($parts.Count -gt 1 -and $room -gt 8) { $desc = '  ' + (Get-Visible $parts[1] $room); W $desc 'DarkGray'; $width += $desc.Length }
                WL (' ' * [Math]::Max(0, (Get-WindowWidth) - 1 - $width))
            }
            $hint = '     ' + (T 'menu_hint')
            WL ($hint + (' ' * [Math]::Max(0, (Get-WindowWidth) - 1 - $hint.Length))) 'DarkGray'
            if ($first) { $top = (Get-CursorTop) - ($count + 1); $first = $false }
            $key = Read-UiKey
            switch ($key.Key) {
                'UpArrow' { $selected = ($selected - 1 + $count) % $count }
                'DownArrow' { $selected = ($selected + 1) % $count }
                'Tab' { $selected = ($selected + 1) % $count }
                'Home' { $selected = 0 }
                'PageUp' { $selected = 0 }
                'End' { $selected = $count - 1 }
                'PageDown' { $selected = $count - 1 }
                'Enter' { break }
                default {
                    $c = [string]$key.KeyChar
                    if ($c -match '^[1-9]$' -and [int]$c -le $count) { $selected = [int]$c - 1 }
                    elseif ($c -eq 'k') { $selected = ($selected - 1 + $count) % $count }
                    elseif ($c -eq 'j') { $selected = ($selected + 1) % $count }
                }
            }
            if ($key.Key -eq 'Enter') { break }
        }
    } finally { Set-CursorVisible $true }
    Clear-Lines $top ($count + 1)
    Write-Answer (($Items[$selected] -split "`t")[0])
    return $selected
}

function Show-Filter([string]$Question, [string[]]$Items) {
    Write-Question $Question
    if (-not $script:Interactive) { return '' }
    $filter = ''
    $selected = 0
    $offset = 0
    $rows = [Math]::Max(4, [Math]::Min(10, (Get-WindowHeight) - 10))
    $top = (Get-CursorTop)
    $first = $true
    $result = ''
    Set-CursorVisible $false
    try {
        while ($true) {
            $view = @($Items | Where-Object { -not $filter -or $_.ToLowerInvariant().Contains($filter.ToLowerInvariant()) })
            $n = $view.Count
            if ($selected -ge $n) { $selected = [Math]::Max(0, $n - 1) }
            if ($selected -lt $offset) { $offset = $selected }
            if ($selected -ge $offset + $rows) { $offset = $selected - $rows + 1 }
            Set-CursorPos 0 $top
            $header = '    ' + (T 'filter_label') + ': '
            W $header 'DarkGray'; W ($filter + $script:Sym.caret) 'White'
            $count = '  ' + (T 'n_of_m' $n $Items.Count)
            W $count 'DarkGray'
            WL (' ' * [Math]::Max(0, (Get-WindowWidth) - 1 - $header.Length - $filter.Length - 1 - $count.Length))
            for ($i = $offset; $i -lt $offset + $rows; $i++) {
                $text = ''
                if ($i -lt $n) {
                    $text = Get-Visible ([string]$view[$i]) ($script:Width - 8)
                    if ($i -eq $selected) { W ('   ' + $script:Sym.pointer + ' ') 'Cyan'; W $text 'White' } else { W ('     ' + $text) }
                }
                WL (' ' * [Math]::Max(0, (Get-WindowWidth) - 1 - 5 - $text.Length))
            }
            $hint = '     ' + (T 'filter_hint')
            WL ($hint + (' ' * [Math]::Max(0, (Get-WindowWidth) - 1 - $hint.Length))) 'DarkGray'
            if ($first) { $top = (Get-CursorTop) - ($rows + 2); $first = $false }
            $key = Read-UiKey
            $done = $false
            switch ($key.Key) {
                'UpArrow' { if ($selected -gt 0) { $selected-- } }
                'DownArrow' { if ($selected -lt $n - 1) { $selected++ } }
                'Tab' { if ($selected -lt $n - 1) { $selected++ } }
                'PageUp' { $selected = [Math]::Max(0, $selected - $rows) }
                'PageDown' { $selected = [Math]::Max(0, [Math]::Min($n - 1, $selected + $rows)) }
                'Home' { $selected = 0 }
                'End' { $selected = [Math]::Max(0, $n - 1) }
                'Backspace' { if ($filter.Length -gt 0) { $filter = $filter.Substring(0, $filter.Length - 1) }; $selected = 0; $offset = 0 }
                'Enter' { if ($n -gt 0) { $result = [string]$view[$selected]; $done = $true } }
                'Escape' { $result = ''; $done = $true }
                default {
                    if (-not [char]::IsControl($key.KeyChar)) { $filter += [string]$key.KeyChar; $selected = 0; $offset = 0 }
                }
            }
            if ($done) { break }
        }
    } finally { Set-CursorVisible $true }
    Clear-Lines $top ($rows + 2)
    if ($result) { Write-Answer $result } else { Write-Answer '…' }
    return $result
}

function Read-Confirm([string]$Question, [bool]$Default = $true) {
    $answer = $Default
    if (-not $script:Interactive) {
        WL; W ('  ' + $script:Sym.ask + ' ') 'Cyan'; W ($Question + ' '); if ($answer) { WL (T 'yes') 'Green' } else { WL (T 'no') 'Green' }
        return $answer
    }
    WL
    $top = (Get-CursorTop)
    Set-CursorVisible $false
    try {
        while ($true) {
            Set-CursorPos 0 $top
            W ('  ' + $script:Sym.ask + ' ') 'Cyan'; W ($Question + '  ')
            if ($answer) {
                Write-Host (' ' + (T 'yes') + ' ') -NoNewline -ForegroundColor Black -BackgroundColor Green; W '  '; W (T 'no') 'DarkGray'
            } else {
                W (T 'yes') 'DarkGray'; W '  '; Write-Host (' ' + (T 'no') + ' ') -NoNewline -ForegroundColor White -BackgroundColor Red
            }
            W '    '
            $key = Read-UiKey
            $c = ([string]$key.KeyChar).ToLowerInvariant()
            if ($key.Key -eq 'Enter') { break }
            if ($c -eq 'y' -or $c -eq 's' -or ($c -eq 'o' -and $script:UiLang -eq 'fr')) { $answer = $true; break }
            if ($c -eq 'n') { $answer = $false; break }
            if ($key.Key -in @('LeftArrow', 'RightArrow', 'Tab', 'Spacebar')) { $answer = -not $answer }
        }
    } finally { Set-CursorVisible $true }
    Clear-Lines $top 1
    W ('  ' + $script:Sym.ask + ' ') 'Cyan'; W ($Question + ' ')
    if ($answer) { WL (T 'yes') 'Green' } else { WL (T 'no') 'Green' }
    return $answer
}

function Read-Text([string]$Question, [string]$Default = '') {
    Write-Question $Question
    if (-not $script:Interactive) { Write-Answer $(if ($Default) { $Default } else { '—' }); return $Default }
    # Line editor pre-filled with the default value
    $value = $Default
    W ('    ' + $script:Sym.pointer + ' ') 'Cyan'
    $left = (Get-CursorLeft)
    $row = (Get-CursorTop)
    W $value
    while ($true) {
        $key = Read-UiKey
        if ($key.Key -eq 'Enter') { break }
        $previous = $value.Length
        if ($key.Key -eq 'Backspace') { if ($value.Length -gt 0) { $value = $value.Substring(0, $value.Length - 1) } }
        elseif ($key.Key -eq 'Escape' -or ($key.Modifiers -band [ConsoleModifiers]::Control -and $key.Key -eq 'U')) { $value = '' }
        elseif (-not [char]::IsControl($key.KeyChar)) { $value += [string]$key.KeyChar }
        else { continue }
        Set-CursorPos $left $row
        W ($value + (' ' * [Math]::Max(0, $previous - $value.Length)))
        Set-CursorPos ([Math]::Min((Get-WindowWidth) - 1, $left + $value.Length)) $row
    }
    WL
    return $value
}

function Read-Secret([string]$Question) {
    Write-Question $Question
    if (-not $script:Interactive) { return '' }
    W ('    ' + $script:Sym.pointer + ' ') 'Cyan'
    $value = New-Object System.Text.StringBuilder
    while ($true) {
        $key = Read-UiKey
        if ($key.Key -eq 'Enter') { break }
        if ($key.Key -eq 'Backspace') {
            if ($value.Length -gt 0) { [void]$value.Remove($value.Length - 1, 1); W "`b `b" }
        } elseif ($key.Key -eq 'Escape') {
            W ("`b `b" * [Math]::Min($value.Length, $script:Width - 10)); [void]$value.Clear()
        } elseif (-not [char]::IsControl($key.KeyChar)) {
            [void]$value.Append($key.KeyChar)
            if ($value.Length -le $script:Width - 10) { W $script:Sym.bullet }
        }
    }
    WL
    return $value.ToString()
}

function Add-Log([string]$Text) { try { Add-Content -LiteralPath $script:LogFile -Value $Text -Encoding UTF8 } catch { } }

function ConvertTo-Argument([string]$Value) {
    if ($Value -eq '') { return '""' }
    if ($Value -notmatch '[\s"]') { return $Value }
    # Rules of CommandLineToArgvW: backslashes before a quote are doubled
    $escaped = [regex]::Replace($Value, '(\\*)"', { param($m) ($m.Groups[1].Value * 2) + '\"' })
    $escaped = [regex]::Replace($escaped, '(\\+)$', { param($m) $m.Groups[1].Value * 2 })
    return '"' + $escaped + '"'
}

# Runs a program with a spinner; output in $script:RunOutput and in the log.
# Standard input (API key) is written to the process, never to a file or to the log.
function Invoke-Step {
    param(
        [string]$Message,
        [string]$FilePath,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory = $script:Glpi,
        [string]$StdinText = $null,
        [int[]]$SuccessCodes = @(0)
    )
    Add-Log ("`r`n### $Message`r`n### $FilePath " + ($Arguments -join ' '))
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.Arguments = (($Arguments | ForEach-Object { ConvertTo-Argument $_ }) -join ' ')
    if ($WorkingDirectory) { $psi.WorkingDirectory = $WorkingDirectory }
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.RedirectStandardInput = $true
    $psi.StandardOutputEncoding = [Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [Text.Encoding]::UTF8
    $exitCode = -1
    $output = ''
    # .NET Framework writes the preamble of Console.InputEncoding to the redirected
    # input as soon as the process starts: a BOM would precede the API key
    $inputEncoding = $null
    try { $inputEncoding = [Console]::InputEncoding; [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { }
    try {
        try { $process = [Diagnostics.Process]::Start($psi) }
        finally { if ($inputEncoding) { try { [Console]::InputEncoding = $inputEncoding } catch { } } }
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        # Raw UTF-8 bytes, written once (no stream writer, no preamble)
        if ($StdinText) {
            $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($StdinText + "`n")
            $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
            $process.StandardInput.BaseStream.Flush()
        }
        $process.StandardInput.BaseStream.Close()
        $frame = 0
        while (-not $process.HasExited) {
            if ($script:Interactive) {
                W ("`r  " + $script:Spinner[$frame % $script:Spinner.Count] + ' ') 'Cyan'; W $Message
                $frame++
            }
            Start-Sleep -Milliseconds 80
        }
        $process.WaitForExit()
        $exitCode = $process.ExitCode
        $output = $stdout.Result + $stderr.Result
    } catch {
        $output = $_.Exception.Message
    }
    if ($script:Interactive) { W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r") }
    $script:RunOutput = $output
    Add-Log $output
    $ok = $SuccessCodes -contains $exitCode
    if ($ok) { Write-Ok $Message } else { Write-Err $Message }
    return $ok
}

function Get-LastOutputLine {
    $lines = @(($script:RunOutput -replace $script:AnsiPattern, '') -split "`r?`n" | Where-Object { $_.Trim() })
    if ($lines.Count) { return $lines[-1].Trim() }
    return ''
}

function Show-OutputBox([string]$Title) {
    $lines = @(($script:RunOutput -replace $script:AnsiPattern, '') -split "`r?`n" | Where-Object { $_.Trim() } | Select-Object -Last 12)
    if (-not $lines.Count) { $lines = @('(no output)') }
    Show-Box 'error' $Title $lines
    Write-Note (T 'log_at' $script:LogFile)
}

# -----------------------------------------------------------------------------
# System helpers
# -----------------------------------------------------------------------------
$script:Glpi = ''
$script:GlpiVersion = ''
$script:PhpExe = ''
$script:PhpVersion = ''
$script:PhpCandidates = @()
$script:GlpiLogin = $(if ($GlpiUser) { $GlpiUser } else { 'glpi' })
$script:GlpiWhere = ''
$script:PluginDir = ''
$script:Container = ''
$script:DockerCmd = @()
$script:ContainerUser = ''
$script:ConsoleExtra = @()
$DockerGlpiDirs = @('/var/www/glpi', '/var/www/html/glpi', '/var/www/html', '/usr/share/glpi', '/srv/www/glpi', '/opt/glpi', '/app', '/var/www')

# Value of a GLPI_* constant written in the files of GLPI (the first definition wins, as in GLPI)
function Get-GlpiDefine([string]$Name, [string]$Dir) {
    foreach ($file in @((Join-Path $Dir 'config\local_define.php'), (Join-Path $Dir 'inc\downstream.php'))) {
        if (-not (Test-Path -LiteralPath $file)) { continue }
        $m = Select-String -LiteralPath $file -Pattern ("define\s*\(\s*['""]" + $Name + "['""]\s*,\s*['""]([^'""]+)['""]") -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($m) { return $m.Matches[0].Groups[1].Value }
    }
    return ''
}

function Get-FixedDrives {
    try { return @([IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady } | ForEach-Object { $_.RootDirectory.FullName }) }
    catch { return @('C:\') }
}

# Runs a program and returns its exit code and outputs (no PowerShell error records:
# with ErrorActionPreference = Stop, Windows PowerShell turns stderr lines into exceptions)
function Invoke-Capture([string]$FilePath, [string[]]$Arguments = @(), [string]$WorkingDirectory = '', [int]$TimeoutMs = 0) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.Arguments = (($Arguments | ForEach-Object { ConvertTo-Argument $_ }) -join ' ')
    if ($WorkingDirectory) { $psi.WorkingDirectory = $WorkingDirectory }
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [Text.Encoding]::UTF8
    try {
        $process = [Diagnostics.Process]::Start($psi)
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        if ($TimeoutMs -gt 0 -and -not $process.WaitForExit($TimeoutMs)) {
            try { $process.Kill() } catch { }
            return [pscustomobject]@{ ExitCode = -1; StdOut = ''; StdErr = 'timeout' }
        }
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; StdOut = $stdout.Result; StdErr = $stderr.Result }
    } catch {
        return [pscustomobject]@{ ExitCode = -1; StdOut = ''; StdErr = $_.Exception.Message }
    }
}

# -----------------------------------------------------------------------------
# GLPI in a container (Docker Desktop, Podman): the files are read with the PHP
# of the container (the only program every GLPI image has), the console runs
# with "docker exec" and the plugin is copied with "docker cp"
# -----------------------------------------------------------------------------

# php -r PROBE OPERATION ARGUMENT…: reads the files of a container. Same code in
# install.sh, without double quotes so that it survives the command line of Windows
$PhpProbe = @'
$a = array_slice($argv, 1);
$op = (string) array_shift($a);
$owner = function ($path) {
    $uid = @fileowner($path);
    if ($uid === false) {
        return '';
    }
    if (function_exists('posix_getpwuid')) {
        $user = posix_getpwuid($uid);
        if ($user) {
            return $user['name'];
        }
    }
    return (string) $uid;
};
$constant = function ($glpi, $name) {
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    foreach (array($glpi . '/config/local_define.php', $glpi . '/inc/downstream.php') as $file) {
        if (preg_match('/define\(\s*[\x27\x22]' . $name . '[\x27\x22]\s*,\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', (string) @file_get_contents($file), $m)) {
            return $m[1];
        }
    }
    return '';
};
switch ($op) {
    case 'glpi':
        foreach ($a as $d) {
            if (is_file($d . '/bin/console') && is_file($d . '/src/Ticket.php')) {
                echo $d;
                exit(0);
            }
        }
        exit(1);
    case 'version':
        if (preg_match('/define\(\s*[\x27\x22]GLPI_VERSION[\x27\x22]\s*,\s*[\x27\x22](\d[^\x27\x22]*)[\x27\x22]/', (string) @file_get_contents($a[0] . '/inc/define.php'), $m)) {
            echo $m[1];
            exit(0);
        }
        $v = array();
        foreach ((array) @scandir($a[0] . '/version') as $n) {
            if (preg_match('/^\d/', (string) $n)) {
                $v[] = $n;
            }
        }
        usort($v, 'version_compare');
        echo (string) end($v);
        exit(0);
    case 'define':
        if (preg_match('/define\(\x27' . preg_quote($a[0], '/') . '\x27,\s*\x27([^\x27]*)\x27\)/', (string) @file_get_contents($a[1]), $m)) {
            echo $m[1];
        }
        exit(0);
    case 'const':
        echo $constant($a[0], $a[1]);
        exit(0);
    case 'plugin':
        $market = $constant($a[0], 'GLPI_MARKETPLACE_DIR');
        if ($market === '') {
            $market = $a[0] . '/marketplace';
        }
        $p = $market . '/' . $a[1];
        if (is_file($p . '/setup.php')) {
            echo $p;
            exit(0);
        }
        $p = $a[0] . '/plugins/' . $a[1];
        if (file_exists($p) || is_link($p)) {
            echo $p;
            exit(0);
        }
        exit(1);
    case 'exists':
        exit(file_exists($a[0]) ? 0 : 1);
    case 'owner':
        foreach ($a as $p) {
            if ($p !== '' && file_exists($p)) {
                $o = $owner($p);
                if ($o !== '' && $o !== 'root' && $o !== '0') {
                    echo $o;
                    exit(0);
                }
            }
        }
        exit(1);
    case 'user':
        if (preg_match('/^\d+$/', $a[0])) {
            exit(0);
        }
        if (function_exists('posix_getpwnam')) {
            exit(posix_getpwnam($a[0]) ? 0 : 1);
        }
        exit(preg_match('/^' . preg_quote($a[0], '/') . ':/m', (string) @file_get_contents('/etc/passwd')) ? 0 : 1);
    case 'tools':
        $found = array();
        foreach ($a as $t) {
            foreach (explode(':', (string) getenv('PATH')) as $d) {
                if ($d !== '' && is_file($d . '/' . $t) && is_executable($d . '/' . $t)) {
                    $found[] = $t;
                    break;
                }
            }
        }
        echo implode(' ', $found);
        exit(0);
    case 'cron':
        $files = array('/etc/crontab', '/etc/supervisor/supervisord.conf', '/etc/supervisord.conf');
        foreach (array('/etc/cron.d', '/var/spool/cron', '/var/spool/cron/crontabs', '/etc/supervisor/conf.d', '/etc/supervisord.d') as $d) {
            foreach ((array) @glob($d . '/*') as $f) {
                $files[] = $f;
            }
        }
        foreach ($files as $f) {
            if (is_file($f) && preg_match('/cron\.php|glpi:cron/', (string) @file_get_contents($f))) {
                echo $f;
                exit(0);
            }
        }
        // A scheduler that runs front/cron.php (the official image starts one with supervisord)
        foreach ((array) @glob('/proc/[0-9]*/cmdline') as $f) {
            $c = trim(str_replace(chr(0), ' ', (string) @file_get_contents($f)));
            if ((int) basename(dirname($f)) !== getmypid() && preg_match('/cron\.php|glpi:cron/', $c)) {
                echo strlen($c) > 60 ? substr($c, 0, 57) . '...' : $c;
                exit(0);
            }
        }
        exit(1);
}
exit(2);
'@

function Initialize-Docker {
    if ($script:DockerCmd.Count) { return $true }
    $engine = $env:TICKETASSISTANT_DOCKER
    if (-not $engine) {
        foreach ($name in @('docker.exe', 'podman.exe')) {
            $found = Get-Command $name -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($found) { $engine = $found.Source; break }
        }
    }
    if (-not $engine) { return $false }
    # One more argument before the commands (the tests run a script with PHP)
    $command = @($engine)
    if ($env:TICKETASSISTANT_DOCKER_ARGS) { $command += $env:TICKETASSISTANT_DOCKER_ARGS }
    $result = Invoke-Capture $command[0] (@($command | Select-Object -Skip 1) + @('ps', '-q')) $RepoDir 15000
    if ($result.ExitCode -ne 0) { return $false }
    $script:DockerCmd = $command
    return $true
}

function Invoke-Docker([string[]]$Arguments, [int]$TimeoutMs = 0) {
    if (-not (Initialize-Docker)) { return [pscustomobject]@{ ExitCode = -1; StdOut = ''; StdErr = (T 'docker_unavailable') } }
    return Invoke-Capture $script:DockerCmd[0] (@($script:DockerCmd | Select-Object -Skip 1) + $Arguments) $RepoDir $TimeoutMs
}

# The probe in a container: its exit code and its output
function Invoke-ProbeResult([string[]]$Arguments, [string]$Container = $script:Container) {
    $result = Invoke-Docker (@('exec', $Container, 'php', '-r', $PhpProbe) + $Arguments) 20000
    return [pscustomobject]@{ Ok = ($result.ExitCode -eq 0); Text = ([string]$result.StdOut).Trim() }
}

function Invoke-Probe([string[]]$Arguments, [string]$Container = $script:Container) {
    $result = Invoke-ProbeResult $Arguments $Container
    if (-not $result.Ok) { return '' }
    return $result.Text
}

# A command as root in the selected container; its output goes to the log
function Invoke-DockerRoot([string[]]$Arguments) {
    $result = Invoke-Docker (@('exec', '-u', '0', $script:Container) + $Arguments)
    Add-Log ("### exec -u 0 $($script:Container) " + ($Arguments -join ' ') + "`r`n" + $result.StdOut + $result.StdErr)
    if ($result.ExitCode -ne 0) { $script:RunOutput = $result.StdOut + $result.StdErr }
    return $result.ExitCode -eq 0
}

function Find-DockerGlpi { # docker:CONTAINER:FOLDER for each running container with a GLPI
    $found = @()
    if (-not (Initialize-Docker)) { return , $found }
    $names = @(((Invoke-Docker @('ps', '--format', '{{.Names}}') 15000).StdOut -split "`r?`n") | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    foreach ($name in $names) {
        $dir = Invoke-Probe (@('glpi') + $DockerGlpiDirs) $name
        if ($dir) { $found += "docker:${name}:$dir" }
    }
    return , $found
}

function Split-Candidate([string]$Candidate) { # docker:CONTAINER:FOLDER, or a folder of this computer
    if ($Candidate.StartsWith('docker:', [StringComparison]::Ordinal)) {
        $rest = $Candidate.Substring(7)
        $i = $rest.IndexOf(':')
        return [pscustomobject]@{ Docker = $rest.Substring(0, $i); Dir = $rest.Substring($i + 1) }
    }
    return [pscustomobject]@{ Docker = ''; Dir = $Candidate }
}

function Get-DockerMounts { # volumes and bind mounts of the selected container
    $text = (Invoke-Docker @('inspect', '-f', '{{json .Mounts}}', $script:Container)).StdOut
    try { return @($text | ConvertFrom-Json | ForEach-Object { $_ } | Where-Object { $_.Destination }) } catch { return @() }
}

function Get-DockerMountAt([string]$Dir) { # source of a mount on exactly this folder ('' = none)
    foreach ($mount in Get-DockerMounts) {
        if ([string]::Equals(([string]$mount.Destination).TrimEnd('/'), $Dir.TrimEnd('/'), [StringComparison]::Ordinal)) { return [string]$mount.Source }
    }
    return ''
}

function Test-DockerVolume([string]$Dir) { # the folder survives a new container
    foreach ($mount in Get-DockerMounts) {
        $destination = ([string]$mount.Destination).TrimEnd('/')
        if ($destination -and ([string]::Equals($Dir, $destination, [StringComparison]::Ordinal) -or $Dir.StartsWith($destination + '/', [StringComparison]::Ordinal))) { return $true }
    }
    return $false
}

function Get-DockerHostAddress { # address of this computer as seen from the selected container
    if (([string](Invoke-Docker @('inspect', '-f', '{{.HostConfig.NetworkMode}}', $script:Container)).StdOut).Trim() -eq 'host') { return 'localhost' }
    if ([string](Invoke-Docker @('info', '--format', '{{.OperatingSystem}}')).StdOut -match 'Docker Desktop') { return 'host.docker.internal' }
    $gateway = @(((Invoke-Docker @('inspect', '-f', '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}', $script:Container)).StdOut -split "`r?`n") |
            ForEach-Object { $_.Trim() } | Where-Object { $_ -match '^[0-9a-fA-F.:]+$' })
    if ($gateway.Count) { return $gateway[0] }
    if ((Split-Path -Leaf $script:DockerCmd[0]) -like 'podman*') { return 'host.containers.internal' }
    return 'host.docker.internal'
}

function Test-DockerPhp { # PHP and extensions of the selected container
    $script:PhpVersion = ([string](Invoke-Docker @('exec', $script:Container, 'php', '-r', 'echo PHP_VERSION;')).StdOut).Trim()
    Write-Ok (T 'docker_php' $script:PhpVersion)
    $modules = @(((Invoke-Docker @('exec', $script:Container, 'php', '-m')).StdOut -split "`r?`n") | ForEach-Object { $_.Trim().ToLowerInvariant() })
    $missing = @(@('curl', 'mbstring', 'json', 'gd', 'zip', 'zlib') | Where-Object { $modules -notcontains $_ })
    if ($missing.Count) { Write-Warn (T 'ext_missing' ($missing -join ' ')); Write-Note (T 'docker_ext_hint') }
    else { Write-Ok (T 'ext_ok' 'curl mbstring json gd zip zlib') }
}

function Find-DockerWebUser { # owner of the GLPI files in the container (www-data in the official image)
    $candidate = $WebUser
    if (-not $candidate) {
        $varDir = Invoke-Probe @('const', $script:Glpi, 'GLPI_VAR_DIR')
        $candidate = Invoke-Probe @('owner', $varDir, "$($script:Glpi)/files", "$($script:Glpi)/config", "$($script:Glpi)/marketplace", "$($script:Glpi)/plugins", $script:Glpi)
        if ($script:Interactive) {
            $default = $candidate
            if (-not $default) { $default = 'www-data' }
            $candidate = Read-Text (T 'web_user_ask') $default
        }
    }
    if ($candidate -and -not (Invoke-ProbeResult @('user', $candidate)).Ok) { Write-Warn "$candidate?"; $candidate = '' }
    $script:ContainerUser = $candidate
    if ($script:ContainerUser) { Write-Ok (T 'web_user' $script:ContainerUser) }
    # The console refuses root on recent GLPI versions unless told otherwise
    if (-not $script:ContainerUser -or $script:ContainerUser -eq 'root' -or $script:ContainerUser -eq '0') {
        $usage = Invoke-Docker @('exec', $script:Container, 'php', "$($script:Glpi)/bin/console", '--help')
        if (($usage.StdOut + $usage.StdErr) -match '--allow-superuser') { $script:ConsoleExtra = @('--allow-superuser') }
    }
}

# GLPI loads a plugin from its marketplace folder first, then from plugins\
function Get-PluginLocation([string]$Dir) { # folder of the plugin in this GLPI ('' = none)
    if ($script:Container) { return (Invoke-Probe @('plugin', $Dir, $PluginKey)) }
    $marketplace = Get-GlpiDefine 'GLPI_MARKETPLACE_DIR' $Dir
    if (-not $marketplace) { $marketplace = Join-Path $Dir 'marketplace' }
    if (Test-Path -LiteralPath (Join-Path $marketplace "$PluginKey\setup.php")) { return (Join-Path $marketplace $PluginKey) }
    $plugins = Join-Path $Dir "plugins\$PluginKey"
    if (Get-Item -LiteralPath $plugins -Force -ErrorAction SilentlyContinue) { return $plugins }
    return ''
}

# Folder that receives the plugin: where GLPI finds it already, or plugins\. In a
# container, a folder on a volume when there is one (the marketplace folder of
# the official image), so that a new container keeps it
function Set-PluginTarget {
    $script:PluginDir = Get-PluginLocation $script:Glpi
    if ($script:PluginDir) { return }
    if (-not $script:Container) { $script:PluginDir = Join-Path $script:Glpi "plugins\$PluginKey"; return }
    $script:PluginDir = "$($script:Glpi)/plugins/$PluginKey"
    if (Test-DockerVolume "$($script:Glpi)/plugins") { return }
    $marketplace = Invoke-Probe @('const', $script:Glpi, 'GLPI_MARKETPLACE_DIR')
    if (-not $marketplace) { $marketplace = "$($script:Glpi)/marketplace" }
    # GLPI 10 serves the files of a plugin only from inside its own folder
    $inside = $marketplace.StartsWith($script:Glpi + '/', [StringComparison]::Ordinal)
    if ((Test-DockerVolume $marketplace) -and ($inside -or (Test-VersionAtLeast $script:GlpiVersion '11'))) { $script:PluginDir = "$marketplace/$PluginKey" }
}

function Get-PluginVersionAt([string]$Dir) { # version of the plugin files in this folder
    if ($script:Container) { return (Invoke-Probe @('define', 'PLUGIN_TICKETASSISTANT_VERSION', "$Dir/setup.php")) }
    return Get-PhpDefine 'PLUGIN_TICKETASSISTANT_VERSION' (Join-Path $Dir 'setup.php')
}

function Test-VersionAtLeast([string]$Version, [string]$Minimum) {
    $a = [regex]::Match($Version, '^\d+(\.\d+){0,3}').Value
    $b = [regex]::Match($Minimum, '^\d+(\.\d+){0,3}').Value
    if (-not $a -or -not $b) { return $false }
    if ($a -notmatch '\.') { $a += '.0' }
    if ($b -notmatch '\.') { $b += '.0' }
    return ([version]$a) -ge ([version]$b)
}

function Test-Admin {
    try { return ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator) } catch { return $false }
}

# Program, arguments and folder that run the GLPI console (a container: docker exec)
function Get-ConsoleCommand([string[]]$Arguments, [bool]$Stdin = $false) {
    # Messages of GLPI and of the plugin in the language of the installer
    $lang = @()
    if ($script:UiLang -eq 'pt') { $lang = @('--lang=pt_BR') } elseif ($script:UiLang -eq 'fr') { $lang = @('--lang=fr_FR') }
    $console = @('--no-interaction', '--no-ansi') + $script:ConsoleExtra + $lang + $Arguments
    if (-not $script:Container) {
        return [pscustomobject]@{ FilePath = $script:PhpExe; Arguments = @(@('bin\console') + $console); Directory = $script:Glpi }
    }
    $options = @('exec', '-w', $script:Glpi)
    if ($script:ContainerUser) { $options += @('-u', $script:ContainerUser) }
    # Standard input only when something is written to it (the API key)
    if ($Stdin) { $options += '-i' }
    $all = @($script:DockerCmd | Select-Object -Skip 1) + $options + @($script:Container, 'php', 'bin/console') + $console
    return [pscustomobject]@{ FilePath = $script:DockerCmd[0]; Arguments = $all; Directory = $RepoDir }
}

function Invoke-Console([string]$Message, [string[]]$Arguments, [string]$StdinText = $null) {
    $command = Get-ConsoleCommand $Arguments ([bool]$StdinText)
    return Invoke-Step -Message $Message -FilePath $command.FilePath -Arguments $command.Arguments -WorkingDirectory $command.Directory -StdinText $StdinText
}

function Get-PhpVersionOf([string]$Exe) {
    $result = Invoke-Capture $Exe @('-r', 'echo PHP_VERSION;')
    if ($result.ExitCode -ne 0) { return '' }
    $found = [regex]::Matches($result.StdOut, '\d+\.\d+\.\d+[^\s]*')
    if ($found.Count) { return $found[$found.Count - 1].Value }
    return ''
}

function Test-GlpiFolder([string]$Dir) {
    if (-not $Dir) { return $false }
    if ($script:Container) { return [bool](Invoke-Probe @('glpi', $Dir)) }
    # Markers present in GLPI 10, 11 and 12 (front\central.php and inc\define.php are gone in 11)
    return (Test-Path -LiteralPath (Join-Path $Dir 'bin\console')) -and (Test-Path -LiteralPath (Join-Path $Dir 'src\Ticket.php'))
}

function Get-GlpiVersionOf([string]$Dir) {
    if ($script:Container) { return (Invoke-Probe @('version', $Dir)) }
    $define = Join-Path $Dir 'inc\define.php'
    if (Test-Path -LiteralPath $define) {
        $m = Select-String -LiteralPath $define -Pattern "define\s*\(\s*['""]GLPI_VERSION['""]\s*,\s*['""](\d[^'""]*)['""]" -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($m) { return $m.Matches[0].Groups[1].Value }
    }
    $versionDir = Join-Path $Dir 'version'
    if (Test-Path -LiteralPath $versionDir) {
        $names = @(Get-ChildItem -LiteralPath $versionDir -Name | Where-Object { $_ -match '^\d' })
        if ($names.Count) { return ($names | Sort-Object { [version]([regex]::Match($_, '^\d+(\.\d+){1,3}').Value) } | Select-Object -Last 1) }
    }
    return ''
}

function Get-InstalledPluginVersion { return (Get-PluginVersionAt $script:PluginDir) } # '' = none

function Read-PluginStatus {
    $script:PluginStatus = @{}
    $command = Get-ConsoleCommand @('plugins:ticketassistant:status', '--porcelain')
    $result = Invoke-Capture $command.FilePath $command.Arguments $command.Directory
    if ($result.ExitCode -ne 0) { return $false }
    foreach ($line in ($result.StdOut -split "`r?`n")) {
        $text = [string]$line
        $i = $text.IndexOf('=')
        if ($i -gt 0) { $script:PluginStatus[$text.Substring(0, $i)] = $text.Substring($i + 1) }
    }
    return $script:PluginStatus.ContainsKey('version')
}
$script:PluginStatus = @{}

# -----------------------------------------------------------------------------
# Step 1: system
# -----------------------------------------------------------------------------
function Find-PhpCandidates {
    $paths = New-Object System.Collections.Generic.List[string]
    if ($Php) { $paths.Add($Php) }
    $command = Get-Command php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command) { $paths.Add($command.Source) }
    $drives = @(Get-FixedDrives)
    $patterns = @('xampp\php\php.exe', 'wamp64\bin\php\php*\php.exe', 'wamp\bin\php\php*\php.exe', 'laragon\bin\php\*\php.exe',
        'php*\php.exe', 'tools\php*\php.exe', 'Program Files\PHP\*\php.exe', 'Program Files\PHP\php.exe', 'Program Files (x86)\PHP\*\php.exe')
    foreach ($drive in $drives) {
        foreach ($pattern in $patterns) {
            foreach ($item in @(Get-Item -Path (Join-Path $drive $pattern) -ErrorAction SilentlyContinue)) { $paths.Add($item.FullName) }
        }
    }
    foreach ($item in @(Get-Item -Path (Join-Path $env:USERPROFILE 'scoop\apps\php*\current\php.exe') -ErrorAction SilentlyContinue)) { $paths.Add($item.FullName) }
    # PHP used by IIS (FastCGI)
    $iis = Join-Path $env:windir 'System32\inetsrv\config\applicationHost.config'
    if (Test-Path -LiteralPath $iis) {
        foreach ($m in (Select-String -LiteralPath $iis -Pattern 'fullPath="([^"]*php-cgi\.exe)"' -AllMatches -ErrorAction SilentlyContinue)) {
            foreach ($match in $m.Matches) {
                $cli = Join-Path (Split-Path -Parent ([Environment]::ExpandEnvironmentVariables($match.Groups[1].Value))) 'php.exe'
                if (Test-Path -LiteralPath $cli) { $paths.Add($cli) }
            }
        }
    }
    $seen = @{}
    $result = @()
    foreach ($path in $paths) {
        if (-not (Test-Path -LiteralPath $path)) { continue }
        $full = (Resolve-Path -LiteralPath $path).Path
        if ($seen.ContainsKey($full.ToLowerInvariant())) { continue }
        $seen[$full.ToLowerInvariant()] = $true
        $version = Get-PhpVersionOf $full
        if ($version) { $result += [pscustomobject]@{ Path = $full; Version = $version } }
    }
    return , $result
}

function Step-Checks {
    Show-Step 1 (T 'step_checks')
    if (-not (Test-Path -LiteralPath (Join-Path $RepoDir 'setup.php')) -or -not $PluginVersion) {
        Write-Err (T 'not_repo'); exit 1
    }
    Write-Ok (T 'plugin_repo' $PluginVersion $RepoDir)
    $script:PhpCandidates = Find-PhpCandidates
    if (-not $script:PhpCandidates.Count) {
        # A GLPI in a container runs with the PHP of the container
        if (Initialize-Docker) { Write-Warn (T 'php_missing_docker'); return }
        Write-Err (T 'php_missing'); exit 1
    }
    $script:PhpExe = $script:PhpCandidates[0].Path
    $script:PhpVersion = $script:PhpCandidates[0].Version
    W ('  ' + $script:Sym.ok + ' ') 'Green'; W (T 'php_found' $script:PhpVersion ''); WL $script:PhpExe 'DarkGray'
    $modules = @(((Invoke-Capture $script:PhpExe @('-m')).StdOut -split "`r?`n") | ForEach-Object { $_.Trim().ToLowerInvariant() })
    $missing = @('curl', 'mbstring', 'json', 'gd', 'zip', 'zlib') | Where-Object { $modules -notcontains $_ }
    if ($missing.Count) { Write-Warn (T 'ext_missing' ($missing -join ' ')); Write-Note (T 'ext_hint') }
    else { Write-Ok (T 'ext_ok' 'curl mbstring json gd zip zlib') }
}

function Restart-Elevated {
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (ConvertTo-Argument $PSCommandPath))
    foreach ($entry in $ScriptParameters.GetEnumerator()) {
        if ($entry.Value -is [switch]) { if ($entry.Value) { $arguments += '-' + $entry.Key } }
        else { $arguments += '-' + $entry.Key; $arguments += (ConvertTo-Argument ([string]$entry.Value)) }
    }
    $shell = (Get-Process -Id $PID).Path
    Start-Process -FilePath $shell -Verb RunAs -ArgumentList $arguments | Out-Null
    exit 0
}

# PHP able to run the selected GLPI (GLPI 11 needs 8.2, GLPI 12 needs 8.3)
function Select-PhpForGlpi {
    $required = '7.4'
    if (Test-VersionAtLeast $script:GlpiVersion '11') { $required = '8.2' }
    if (Test-VersionAtLeast $script:GlpiVersion '12') { $required = '8.3' }
    # Prefer the PHP next to GLPI (same XAMPP / WAMP / Laragon)
    $root = ($script:Glpi -split '\\')[0..1] -join '\'
    $near = @($script:PhpCandidates | Where-Object { $_.Path.StartsWith($root, [StringComparison]::OrdinalIgnoreCase) -and (Test-VersionAtLeast $_.Version $required) })
    if (-not $Php -and $near.Count) { $script:PhpExe = $near[0].Path; $script:PhpVersion = $near[0].Version }
    if (Test-VersionAtLeast $script:PhpVersion $required) { return }
    Write-Warn (T 'php_too_old' $script:GlpiVersion $required $script:PhpExe $script:PhpVersion)
    $usable = @($script:PhpCandidates | Where-Object { Test-VersionAtLeast $_.Version $required })
    if (-not $usable.Count) { return }
    $choice = Show-Menu (T 'choose_php') ($usable | ForEach-Object { "PHP $($_.Version)`t$($_.Path)" }) 0
    $script:PhpExe = $usable[$choice].Path
    $script:PhpVersion = $usable[$choice].Version
}

# -----------------------------------------------------------------------------
# Step 2: GLPI
# -----------------------------------------------------------------------------
function Find-Glpi {
    $dirs = New-Object System.Collections.Generic.List[string]
    $drives = @(Get-FixedDrives)
    $roots = @('xampp\htdocs', 'wamp64\www', 'wamp\www', 'laragon\www', 'inetpub\wwwroot', 'Apache24\htdocs', 'Apache\htdocs',
        'nginx\html', 'www', 'web', 'htdocs', 'sites', 'glpi', 'Program Files\Apache Software Foundation\Apache2.4\htdocs')
    foreach ($drive in $drives) { foreach ($root in $roots) { $dirs.Add((Join-Path $drive $root)) } }
    # Sites and virtual directories of IIS, document roots of Apache
    $iis = Join-Path $env:windir 'System32\inetsrv\config\applicationHost.config'
    if (Test-Path -LiteralPath $iis) {
        foreach ($m in (Select-String -LiteralPath $iis -Pattern 'physicalPath="([^"]+)"' -AllMatches -ErrorAction SilentlyContinue)) {
            foreach ($match in $m.Matches) { $dirs.Add([Environment]::ExpandEnvironmentVariables($match.Groups[1].Value)) }
        }
    }
    foreach ($drive in $drives) {
        foreach ($conf in @('xampp\apache\conf\httpd.conf', 'xampp\apache\conf\extra\httpd-vhosts.conf', 'Apache24\conf\httpd.conf', 'Apache24\conf\extra\httpd-vhosts.conf')) {
            $file = Join-Path $drive $conf
            if (-not (Test-Path -LiteralPath $file)) { continue }
            foreach ($m in (Select-String -LiteralPath $file -Pattern '^\s*DocumentRoot\s+"?([^"]+)"?' -ErrorAction SilentlyContinue )) { $dirs.Add($m.Matches[0].Groups[1].Value.Trim().Replace('/', '\')) }
        }
    }
    $found = New-Object System.Collections.Generic.List[string]
    $seen = @{}
    foreach ($dir in $dirs) {
        if (-not $dir -or -not (Test-Path -LiteralPath $dir -PathType Container)) { continue }
        $candidates = @($dir, (Split-Path -Parent $dir))
        foreach ($child in @(Get-ChildItem -LiteralPath $dir -Directory -ErrorAction SilentlyContinue)) {
            $candidates += $child.FullName
            foreach ($grandchild in @(Get-ChildItem -LiteralPath $child.FullName -Directory -ErrorAction SilentlyContinue)) { $candidates += $grandchild.FullName }
        }
        foreach ($candidate in $candidates) {
            if (-not $candidate -or $seen.ContainsKey($candidate.ToLowerInvariant())) { continue }
            $seen[$candidate.ToLowerInvariant()] = $true
            if (Test-GlpiFolder $candidate) { $found.Add($candidate) }
        }
    }
    return , @($found)
}

function Get-GlpiDescription([string]$Dir) { # one line: plugin state in this GLPI
    $location = Get-PluginLocation $Dir
    if (-not $location) { return (T 'plugin_none') }
    if (-not $script:Container) {
        $item = Get-Item -LiteralPath $location -Force -ErrorAction SilentlyContinue
        if ($item -and $item.LinkType) { return (T 'plugin_link' (@($item.Target)[0])) }
    }
    $version = Get-PluginVersionAt $location
    if ($version) { return (T 'plugin_files' $version) }
    return (T 'plugin_none')
}

function Get-CandidateLabel([string]$Candidate) { # GLPI VERSION  WHERE<TAB>state of the plugin
    $parts = Split-Candidate $Candidate
    $saved = $script:Container
    $script:Container = $parts.Docker
    try {
        $where = $parts.Dir
        if ($parts.Docker) { $where = T 'docker_where' $parts.Docker $parts.Dir }
        return "GLPI $(Get-GlpiVersionOf $parts.Dir)  $where`t$(Get-GlpiDescription $parts.Dir)"
    } finally { $script:Container = $saved }
}

function Step-Glpi {
    Show-Step 2 (T 'step_glpi')
    $dir = ''
    if ($Docker) {
        if (-not (Initialize-Docker)) { Write-Err (T 'docker_unavailable'); exit 1 }
        $script:Container = $Docker
        $dir = $GlpiPath
        if (-not $dir) { $dir = Invoke-Probe (@('glpi') + $DockerGlpiDirs) }
        if (-not $dir) { Write-Err (T 'docker_no_glpi' $Docker); exit 1 }
    } elseif ($GlpiPath) {
        $dir = $GlpiPath
    } else {
        W ('  ' + $script:Sym.info + ' ') 'Cyan'; W ((T 'searching') + '…')
        $local = Find-Glpi
        $containers = Find-DockerGlpi
        $candidates = @($local) + @($containers)
        W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r")
        if (-not $script:DockerCmd.Count -and ($env:TICKETASSISTANT_DOCKER -or (Get-Command docker.exe, podman.exe -ErrorAction SilentlyContinue))) {
            Write-Note (T 'docker_no_access')
        }
        $candidate = ''
        if (-not $candidates.Count) {
            Write-Warn (T 'none_found')
            if (-not $script:Interactive) { Write-Err (T 'unattended_glpi' 0); exit 1 }
        } elseif (-not $script:Interactive) {
            if ($candidates.Count -gt 1) { Write-Err (T 'unattended_glpi' ($candidates -join ', ')); exit 1 }
            $candidate = $candidates[0]
        } else {
            Write-Ok (T 'found_n' $candidates.Count)
            $items = @($candidates | ForEach-Object { Get-CandidateLabel $_ }) + @((T 'other_path'))
            $choice = Show-Menu (T 'choose_glpi') $items 0
            if ($choice -lt $candidates.Count) { $candidate = $candidates[$choice] }
        }
        if ($candidate) {
            $parts = Split-Candidate $candidate
            $script:Container = $parts.Docker
            $dir = $parts.Dir
        }
    }
    while ($true) {
        if (-not $dir) { $dir = Read-Text (T 'type_path') 'C:\xampp\htdocs\glpi' }
        if ($script:Container) {
            if (Test-GlpiFolder $dir) { break }
            Write-Err (T 'docker_not_glpi' $dir $script:Container); exit 1
        }
        $dir = $dir.Trim().TrimEnd('\').Trim('"')
        if (Test-GlpiFolder $dir) { break }
        Write-Err (T 'not_glpi' $dir)
        if (-not $script:Interactive) { exit 1 }
        $dir = ''
    }
    if ($script:Container) {
        $script:Glpi = $dir
        $script:GlpiWhere = T 'docker_where' $script:Container $dir
    } else {
        if (-not $script:PhpExe) { Write-Err (T 'php_missing'); exit 1 }
        $script:Glpi = (Resolve-Path -LiteralPath $dir).Path
        $script:GlpiWhere = $script:Glpi
    }
    $script:GlpiVersion = Get-GlpiVersionOf $script:Glpi
    W ('  ' + $script:Sym.ok + ' ') 'Green'; W (T 'glpi_selected' $script:GlpiVersion ''); WL $script:GlpiWhere 'DarkGray'
    if ($script:Container) {
        Test-DockerPhp
    } elseif (-not (Test-Admin)) {
        # Writing into folders such as C:\inetpub and creating the scheduled task need it (not a container)
        Show-Box 'warn' '' @((T 'not_admin'))
        if ($script:Interactive -and (Read-Confirm (T 'elevate') $false)) { Restart-Elevated }
    }
    if ($script:GlpiVersion) {
        if (-not (Test-VersionAtLeast $script:GlpiVersion $MinGlpi)) { Write-Err (T 'glpi_too_old' $script:GlpiVersion $MinGlpi); exit 1 }
        if (Test-VersionAtLeast $script:GlpiVersion $MaxGlpi) { Write-Warn (T 'glpi_untested' $script:GlpiVersion ($MaxGlpi -replace '\.99$', '')) }
        if (-not $script:Container) { Select-PhpForGlpi }
    }
    if ($script:Container) { Find-DockerWebUser }
    Set-PluginTarget
}

# -----------------------------------------------------------------------------
# Step 3: plugin files and installation in GLPI
# -----------------------------------------------------------------------------
$script:InstallMode = 'Copy'
$script:BackupFile = ''

function Test-Writable([string]$Dir) {
    try {
        if (-not (Test-Path -LiteralPath $Dir)) { New-Item -ItemType Directory -Path $Dir -Force | Out-Null }
        $probe = Join-Path $Dir ('.ticketassistant-' + [guid]::NewGuid().ToString('N'))
        Set-Content -LiteralPath $probe -Value '' -ErrorAction Stop
        Remove-Item -LiteralPath $probe -Force
        return $true
    } catch { return $false }
}

function Backup-Plugin {
    $script:BackupFile = ''
    $dest = $script:PluginDir
    if ($script:Container) {
        if (-not (Invoke-ProbeResult @('exists', $dest)).Ok) { return $true }
    } else {
        $item = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
        if (-not $item) { return $true }
        if ($item.LinkType) { $item.Delete(); return $true }   # removes the junction, not the repository
    }
    $previous = Get-PluginVersionAt $dest
    if (-not $previous) { $previous = 'unknown' }
    $base = $env:TICKETASSISTANT_BACKUP_DIR
    if (-not $base) { $base = Join-Path $env:ProgramData 'glpi-ticketassistant\backups' }
    if (-not (Test-Writable $base)) { $base = Join-Path $env:LOCALAPPDATA 'glpi-ticketassistant\backups' }
    New-Item -ItemType Directory -Path $base -Force | Out-Null
    $script:BackupFile = Join-Path $base ("$PluginKey-$previous-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
    # Pending line while the archive is written, replaced by the result
    if ($script:Interactive) { W ('  ' + $script:Sym.info + ' ') 'Cyan'; W ((T 'backing_up') + '…') }
    $clear = { if ($script:Interactive) { W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r") } }
    $work = ''
    try {
        $source = $dest
        if ($script:Container) {
            # The folder of the container comes here first
            $work = Join-Path ([IO.Path]::GetTempPath()) ('ticketassistant-backup-' + [guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $work -Force | Out-Null
            $source = Join-Path $work $PluginKey
            $result = Invoke-Docker @('cp', "$($script:Container):$dest", $source)
            if ($result.ExitCode -ne 0) { throw ($result.StdOut + $result.StdErr).Trim() }
        }
        Compress-Archive -Path (Join-Path $source '*') -DestinationPath $script:BackupFile -Force
        & $clear; Write-Ok (T 'backing_up')
        Write-Note (T 'backup_done' $script:BackupFile)
        return $true
    } catch {
        & $clear; Write-Err (T 'backing_up'); Write-Note $_.Exception.Message
        return $false
    } finally {
        if ($work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

function Get-CopyArguments([string]$Destination) { # robocopy: the plugin without the files of the repository
    return @($RepoDir, $Destination, '/MIR', '/R:1', '/W:1', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/XD') +
    ($ExcludeDirs | ForEach-Object { Join-Path $RepoDir $_ }) + @('/XF') + $ExcludeFiles
}

# The new files go next to the old ones (GLPI ignores a folder named
# ticketassistant.new), then replace them in one move
function Copy-PluginToContainer {
    $dest = $script:PluginDir
    $stage = Join-Path ([IO.Path]::GetTempPath()) ('ticketassistant-stage-' + [guid]::NewGuid().ToString('N'))
    $copy = Join-Path $stage $PluginKey
    try {
        $result = Invoke-Capture 'robocopy.exe' (Get-CopyArguments $copy) $RepoDir
        Add-Log ($result.StdOut + $result.StdErr)
        $script:RunOutput = $result.StdOut + $result.StdErr
        if ($result.ExitCode -lt 0 -or $result.ExitCode -gt 7) { return $false }
        if (-not (Invoke-DockerRoot @('mkdir', '-p', $dest.Substring(0, $dest.LastIndexOf('/'))))) { return $false }
        if (-not (Invoke-DockerRoot @('rm', '-rf', "$dest.new"))) { return $false }
        $result = Invoke-Docker @('cp', $copy, "$($script:Container):$dest.new")
        Add-Log ("### cp $copy $($script:Container):$dest.new`r`n" + $result.StdOut + $result.StdErr)
        if ($result.ExitCode -ne 0) { $script:RunOutput = $result.StdOut + $result.StdErr; return $false }
        if (-not (Invoke-DockerRoot @('rm', '-rf', $dest))) { return $false }
        return (Invoke-DockerRoot @('mv', "$dest.new", $dest))
    } finally {
        Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Install-PluginFilesDocker {
    $dest = $script:PluginDir
    $script:InstallMode = 'Copy'
    if ($Mode -eq 'Link') { Write-Info (T 'docker_no_link') }
    # A folder mounted from this computer (a development checkout, for example) is used as it is
    $source = Get-DockerMountAt $dest
    if ($source) { $script:InstallMode = 'Mounted'; Write-Ok (T 'docker_mounted' $dest $source); return $true }
    $parent = $dest.Substring(0, $dest.LastIndexOf('/'))
    if (Test-DockerVolume $parent) { Write-Ok (T 'docker_volume' $parent) } else { Show-Box 'warn' '' @((T 'docker_no_volume' $parent)) }
    if (-not (Backup-Plugin)) { return $false }
    $message = T 'copying' (T 'docker_where' $script:Container $dest)
    if ($script:Interactive) { W ('  ' + $script:Sym.info + ' ') 'Cyan'; W ($message + '…') }
    $copied = Copy-PluginToContainer
    if ($script:Interactive) { W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r") }
    if (-not $copied) { Write-Err $message; Show-OutputBox $message; return $false }
    Write-Ok $message
    $user = $script:ContainerUser
    if (-not $user) { $user = 'root' }
    # docker cp gives every file of Windows the mode 755: directories 755, files 644
    $fixed = Invoke-DockerRoot @('chmod', '-R', 'a-x,u=rwX,go=rX', $dest)
    if ($fixed -and $script:ContainerUser) { $fixed = Invoke-DockerRoot @('chown', '-R', $script:ContainerUser, $dest) }
    if ($fixed) { Write-Ok (T 'perms' $user) } else { Write-Err (T 'perms' $user) }
    return $true
}

function Install-PluginFiles {
    if ($script:Container) { return (Install-PluginFilesDocker) }
    $dest = $script:PluginDir
    # Archive extracted directly in plugins\ and installer run from there
    $existing = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
    if ($existing -and -not $existing.LinkType -and ($existing.FullName.TrimEnd('\') -eq $RepoDir.TrimEnd('\'))) {
        $script:InstallMode = 'Copy'
        Write-Ok (T 'in_place' $dest)
        return $true
    }
    if ($Mode) { $script:InstallMode = $Mode }
    elseif ($script:Interactive) {
        $current = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
        $default = 0
        if ($current -and $current.LinkType) { $default = 1 }
        $choice = Show-Menu (T 'mode_ask') @((T 'mode_copy'), (T 'mode_link')) $default
        $script:InstallMode = @('Copy', 'Link')[$choice]
    }
    if (-not (Test-Writable (Split-Path -Parent $dest))) {
        Write-Err (T 'plugins_not_writable' (Split-Path -Parent $dest))
        if ($script:Interactive -and -not (Test-Admin) -and (Read-Confirm (T 'elevate') $true)) { Restart-Elevated }
        return $false
    }
    $current = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
    $sameLink = $current -and $current.LinkType -and ((@($current.Target)[0]).TrimEnd('\') -eq $RepoDir.TrimEnd('\'))
    if ($current -and -not ($sameLink -and $script:InstallMode -eq 'Link')) {
        if (-not (Backup-Plugin)) { return $false }
    }
    if ($script:InstallMode -eq 'Link') {
        if (-not $sameLink) {
            try { New-Item -ItemType Junction -Path $dest -Target $RepoDir | Out-Null; Write-Ok (T 'linking' $dest) }
            catch { Write-Err (T 'linking' $dest); Write-Note $_.Exception.Message; return $false }
        } else { Write-Ok (T 'linking' $dest) }
        return $true
    }
    if (-not (Invoke-Step -Message (T 'copying' $dest) -FilePath 'robocopy.exe' -Arguments (Get-CopyArguments $dest) -WorkingDirectory $RepoDir -SuccessCodes @(0, 1, 2, 3, 4, 5, 6, 7))) {
        Show-OutputBox (T 'copying' $dest)
        return $false
    }
    return $true
}

function Install-InGlpi {
    while ($true) {
        if ($script:Interactive -and -not $GlpiUser) {
            $script:GlpiLogin = Read-Text (T 'login_ask') $script:GlpiLogin
            if (-not $script:GlpiLogin) { $script:GlpiLogin = 'glpi' }
        }
        if (Invoke-Console (T 'installing') @('plugin:install', "--username=$($script:GlpiLogin)", '--force', $PluginKey)) { break }
        Show-OutputBox (T 'console_error')
        if ($script:Interactive -and -not $GlpiUser -and (Read-Confirm (T 'retry_login') $true)) { continue }
        return $false
    }
    if (-not (Invoke-Console (T 'activating') @('plugin:activate', $PluginKey))) { Show-OutputBox (T 'console_error'); return $false }
    if (-not (Invoke-Console (T 'verifying') @('plugins:ticketassistant:status', '--porcelain'))) { Show-OutputBox (T 'console_error'); return $false }
    [void](Read-PluginStatus)
    return $true
}

function Confirm-PluginActive {
    if (Read-PluginStatus) { return $true }
    Write-Warn (T 'plugin_inactive')
    if ($script:Interactive -and -not (Read-Confirm (T 'install_now') $true)) { return $false }
    return (Install-InGlpi)
}

# -----------------------------------------------------------------------------
# Step 4: AI provider
# -----------------------------------------------------------------------------
$script:AiSummary = ''
$script:TestResult = ''

function Get-ProviderLabel([string]$Id) {
    if ($Providers -contains $Id) { return ((T "p_$Id") -split "`t")[0] }
    return $Id
}

function Select-Model([string]$ProviderId, [string]$Current) {
    if ($ProviderId -eq 'azure') { return (Read-Text (T 'deployment_ask') $Current) }
    $models = @()
    if (Invoke-Console (T 'listing') @('plugins:ticketassistant:configure', '--list-models')) {
        $models = @(($script:RunOutput -split "`r?`n") | ForEach-Object { $_.Trim() } | Where-Object { $_ -match '^[A-Za-z0-9][A-Za-z0-9._:/@+-]*$' } | Sort-Object -Unique)
    }
    Write-Note (T 'vision_tip')
    if ($models.Count) {
        $choice = Show-Filter (T 'choose_model') $models
        if ($choice) { return $choice }
    } else { Write-Note (T 'models_none') }
    if ($ProviderModels.ContainsKey($ProviderId)) { Write-Note (T 'model_hint' (($ProviderModels[$ProviderId] -split ' ') -join ', ')) }
    $default = $Current
    if (-not $default -and $ProviderModels.ContainsKey($ProviderId)) { $default = ($ProviderModels[$ProviderId] -split ' ')[0] }
    while ($true) {
        $value = Read-Text (T 'model_ask') $default
        if ($value) { return $value }
        if (-not $script:Interactive) { return $default }
    }
}

function Set-AiConnection {
    [void](Read-PluginStatus)
    $current = 'openai'
    if ($script:PluginStatus.ContainsKey('provider') -and $script:PluginStatus['provider']) { $current = $script:PluginStatus['provider'] }
    $currentModel = ''
    if ($script:PluginStatus.ContainsKey('model')) { $currentModel = $script:PluginStatus['model'] }

    if ($Provider -or $Model) { Set-AiUnattended; return }
    if (-not $script:Interactive -or $SkipAi) { Update-AiSummary; return }

    Show-Box 'info' '' @((T 'ai_intro'))
    if ($script:PluginStatus['ready'] -eq '1') {
        Write-Info (T 'ai_current' (Get-ProviderLabel $current) $currentModel (T 'state_ready'))
        if (-not (Read-Confirm (T 'ai_change') $false)) { Update-AiSummary; return }
    } elseif (-not (Read-Confirm (T 'ai_now') $true)) { Update-AiSummary; return }

    while ($true) {
        $default = [Math]::Max(0, [Array]::IndexOf($Providers, $current))
        $choice = Show-Menu (T 'choose_provider') ($Providers | ForEach-Object { T "p_$_" }) $default
        $new = $Providers[$choice]
        $arguments = @('plugins:ticketassistant:configure', "--provider=$new")
        switch ($new) {
            'azure' {
                $url = ''
                if ($current -eq 'azure') { $url = $script:PluginStatus['base_url'] }
                $arguments += '--base-url=' + (Read-Text (T 'azure_url') $url)
                $arguments += '--api-version=' + (Read-Text (T 'azure_version') '2024-10-21')
            }
            { $_ -in @('ollama', 'compatible') } {
                $url = $ProviderUrl[$new]
                if ($current -eq $new -and $script:PluginStatus['base_url']) { $url = $script:PluginStatus['base_url'] }
                # Inside a container, localhost is the container itself
                if ($script:Container -and $url -match '^(https?://)(localhost|127\.0\.0\.1)([:/].*)?$') {
                    $scheme = $Matches[1]
                    $rest = [string]$Matches[3]
                    $address = Get-DockerHostAddress
                    if ($address -ne 'localhost') { $url = $scheme + $address + $rest; Write-Note (T 'docker_url_hint' $address) }
                }
                $arguments += '--base-url=' + (Read-Text (T 'base_url_ask') $url)
            }
            default { $arguments += '--base-url=' }
        }
        $secret = ''
        $keep = $false
        if ($script:PluginStatus['api_key'] -eq '1' -and $new -eq $current) { $keep = Read-Confirm (T 'key_keep') $true }
        if (-not $keep) {
            if ($new -in @('ollama', 'compatible')) {
                $secret = Read-Secret (T 'key_optional')
                if (-not $secret) { $arguments += '--clear-api-key' }
            } else {
                if ($ProviderKeysPage.ContainsKey($new)) { Write-Note (T 'key_where' $ProviderKeysPage[$new]) }
                while (-not $secret) {
                    $secret = Read-Secret (T 'key_ask')
                    if (-not $secret) { Write-Warn (T 'key_empty') }
                }
            }
        }
        if ($secret) { $arguments += '--api-key-stdin' }
        $saved = Invoke-Console (T 'saving') $arguments $secret
        $secret = $null
        if (-not $saved) { Show-OutputBox (T 'console_error'); continue }
        $current = $new

        $chosen = Select-Model $current $currentModel
        $currentModel = $chosen
        if (-not (Invoke-Console (T 'enabling_ai') @('plugins:ticketassistant:configure', "--model=$chosen", '--enable'))) { Show-OutputBox (T 'console_error'); continue }

        $again = $false
        while ($true) {
            if (Invoke-Console (T 'testing') @('plugins:ticketassistant:configure', '--test')) {
                $script:TestResult = 'ok'; Write-Note (Get-LastOutputLine); break
            }
            $script:TestResult = 'failed'
            Show-OutputBox (T 'test_failed')
            $next = Show-Menu (T 'test_menu') @((T 'tf_retry'), (T 'tf_change'), (T 'tf_skip')) 0
            if ($next -eq 0) { continue }
            if ($next -eq 1) { $again = $true }
            break
        }
        if (-not $again) { break }
    }
    Set-Preferences
    Update-AiSummary
}

function Set-Preferences {
    if (-not $script:Interactive) { return }
    if (Read-Confirm (T 'personalize') $false) {
        $sets = @()
        $organization = Read-Text (T 'org_ask') ''
        if ($organization) { $sets += "--set=organization_name=$organization" }
        $codes = @('auto', 'pt_BR', 'en_GB', 'es_ES', 'fr_FR', 'pt_PT', 'de_DE', 'it_IT', 'nl_NL', 'pl_PL')
        $labels = @((T 'lang_auto'), 'Português (Brasil)', 'English', 'Español', 'Français', 'Português (Portugal)', 'Deutsch', 'Italiano', 'Nederlands', 'Polski')
        $default = 0
        if ($script:UiLang -eq 'pt') { $default = 1 } elseif ($script:UiLang -eq 'fr') { $default = 4 }
        $sets += '--set=response_language=' + $codes[(Show-Menu (T 'lang_ask') $labels $default)]
        if (-not (Invoke-Console (T 'saving_prefs') (@('plugins:ticketassistant:configure') + $sets))) { Show-OutputBox (T 'console_error') }
    }
    if (Read-Confirm (T 'costs_ask') $false) {
        $sets = @(
            '--set=price_input=' + (Read-Text (T 'price_in') '0.00'),
            '--set=price_output=' + (Read-Text (T 'price_out') '0.00'),
            '--set=daily_budget_usd=' + (Read-Text (T 'budget') '5.00')
        )
        if (-not (Invoke-Console (T 'saving_prefs') (@('plugins:ticketassistant:configure') + $sets))) { Show-OutputBox (T 'console_error') }
    }
}

function Set-AiUnattended {
    $arguments = @('plugins:ticketassistant:configure')
    if ($script:Container -and $BaseUrl -match '^https?://(localhost|127\.0\.0\.1)([:/]|$)') {
        $address = Get-DockerHostAddress
        if ($address -ne 'localhost') { Write-Warn (T 'docker_url_hint' $address) }
    }
    if ($Provider) { $arguments += "--provider=$Provider" }
    if ($BaseUrl) { $arguments += "--base-url=$BaseUrl" }
    if ($ApiVersion) { $arguments += "--api-version=$ApiVersion" }
    if ($Model) { $arguments += "--model=$Model" }
    $arguments += '--enable'
    $secret = $null
    if ($ApiKeyEnv) {
        $secret = [Environment]::GetEnvironmentVariable($ApiKeyEnv)
        if (-not $secret) { Write-Err "`$env:$ApiKeyEnv ?"; return }
        $arguments += '--api-key-stdin'
    }
    $saved = Invoke-Console (T 'saving') $arguments $secret
    $secret = $null
    if (-not $saved) { Show-OutputBox (T 'console_error'); return }
    if (Invoke-Console (T 'testing') @('plugins:ticketassistant:configure', '--test')) { $script:TestResult = 'ok'; Write-Note (Get-LastOutputLine) }
    else { $script:TestResult = 'failed'; Show-OutputBox (T 'test_failed') }
    Update-AiSummary
}

function Update-AiSummary {
    [void](Read-PluginStatus)
    if ($script:PluginStatus['model']) { $script:AiSummary = (Get-ProviderLabel $script:PluginStatus['provider']) + ' · ' + $script:PluginStatus['model'] }
    else { $script:AiSummary = T 'ai_not_configured' }
}

# -----------------------------------------------------------------------------
# Steps 5 and 6: library, cron, PDF tools, demonstration data
# -----------------------------------------------------------------------------
$script:LibrarySummary = ''
$script:CronSummary = ''
$script:PdfSummary = ''

function Sync-Library([bool]$Force = $false) {
    if (-not $Force) {
        if ($Sync -eq 'no') { $script:LibrarySummary = T 'skipped'; return }
        if ($script:Interactive -and -not (Read-Confirm (T 'sync_ask') $true)) { $script:LibrarySummary = T 'skipped'; return }
    }
    if (Invoke-Console (T 'syncing') @('plugins:ticketassistant:sync')) { Write-Note (Get-LastOutputLine) } else { Show-OutputBox (T 'console_error') }
    [void](Read-PluginStatus)
    $cases = '0'
    if ($script:PluginStatus['cases']) { $cases = $script:PluginStatus['cases'] }
    $script:LibrarySummary = T 'cases' $cases
}

function Test-CronTask {
    try {
        $tasks = @(Get-ScheduledTask -ErrorAction Stop | Where-Object {
                foreach ($action in @($_.Actions)) { if ("$($action.Execute) $($action.Arguments)" -match 'cron\.php|glpi:cron') { return $true } }
                return $false
            })
        return $tasks.Count -gt 0
    } catch {
        $list = (Invoke-Capture 'schtasks.exe' @('/Query', '/FO', 'CSV', '/V')).StdOut
        return ($list -match 'cron\.php|glpi:cron')
    }
}

# The cron of GLPI in a container: in the container (the official image runs one), or a
# command of this computer that runs it with "docker exec"
function Set-CronTaskDocker {
    $found = Invoke-Probe @('cron')
    if ($found) { Write-Ok (T 'docker_cron_found' $found); $script:CronSummary = T 'found'; return }
    Show-Box 'warn' '' @((T 'docker_cron_missing'))
    $command = @($script:DockerCmd) + @('exec')
    if ($script:ContainerUser) { $command += @('-u', $script:ContainerUser) }
    $command += @($script:Container, 'php', "$($script:Glpi)/front/cron.php")
    Write-Info (T 'docker_cron_manual')
    Write-Note (($command | ForEach-Object { ConvertTo-Argument $_ }) -join ' ')
    $script:CronSummary = T 'missing'
}

function Set-CronTask {
    if ($script:Container) { Set-CronTaskDocker; return }
    if (Test-CronTask) { Write-Ok (T 'cron_found'); $script:CronSummary = T 'found'; return }
    $name = 'GLPI cron'
    $cronFile = Join-Path $script:Glpi 'front\cron.php'
    Show-Box 'warn' '' @((T 'cron_missing'))
    $create = ($Cron -eq 'yes')
    if (-not $create -and $Cron -ne 'no' -and $script:Interactive) { $create = Read-Confirm (T 'cron_create' $name) $true }
    if ($create -and -not (Test-Admin)) {
        if ($script:Interactive -and (Read-Confirm (T 'elevate') $true)) { Restart-Elevated }
        $create = $false
    }
    if ($create) {
        $command = '"' + $script:PhpExe + '" "' + $cronFile + '"'
        $arguments = @('/Create', '/TN', $name, '/SC', 'MINUTE', '/MO', '1', '/RU', 'SYSTEM', '/RL', 'HIGHEST', '/F', '/TR', $command)
        if (Invoke-Step -Message (T 'cron_created' $name) -FilePath 'schtasks.exe' -Arguments $arguments -WorkingDirectory $script:Glpi) {
            $script:CronSummary = T 'created'
            return
        }
        Show-OutputBox (T 'console_error')
    }
    Write-Info (T 'cron_manual')
    Write-Note ("schtasks /Create /TN `"$name`" /SC MINUTE /MO 1 /RU SYSTEM /TR `"\`"$($script:PhpExe)\`" \`"$cronFile\`"`"")
    $script:CronSummary = T 'missing'
}

# Folder of pdftotext.exe, preferring one that also has pdftoppm.exe (scanned PDFs)
function Find-Poppler {
    $dirs = New-Object System.Collections.Generic.List[string]
    foreach ($command in @(Get-Command pdftotext.exe -All -ErrorAction SilentlyContinue)) { $dirs.Add((Split-Path -Parent $command.Source)) }
    $patterns = @("$env:ProgramFiles\poppler*\Library\bin\pdftotext.exe", "$env:ProgramFiles\poppler*\bin\pdftotext.exe",
        "$env:USERPROFILE\scoop\apps\poppler\current\bin\pdftotext.exe", "$env:USERPROFILE\scoop\apps\poppler\current\Library\bin\pdftotext.exe",
        "$env:ProgramData\chocolatey\lib\poppler\tools\*\bin\pdftotext.exe", "$env:ProgramData\chocolatey\lib\poppler\tools\*\Library\bin\pdftotext.exe")
    foreach ($drive in @(Get-FixedDrives)) {
        $patterns += (Join-Path $drive 'poppler*\Library\bin\pdftotext.exe')
        $patterns += (Join-Path $drive 'poppler*\bin\pdftotext.exe')
        $patterns += (Join-Path $drive 'tools\poppler*\Library\bin\pdftotext.exe')
    }
    foreach ($pattern in $patterns) {
        foreach ($item in @(Get-Item -Path $pattern -ErrorAction SilentlyContinue)) { $dirs.Add($item.DirectoryName) }
    }
    foreach ($dir in $dirs) { if (Test-Path -LiteralPath (Join-Path $dir 'pdftoppm.exe')) { return $dir } }
    if ($dirs.Count) { return $dirs[0] }
    return ''
}

function Test-PdfTools {
    if ($script:Container) {
        # Installed in a running container, a package is lost when the container is recreated
        $tools = Invoke-Probe @('tools', 'pdftotext', 'pdftoppm')
        if ($tools -match 'pdftotext' -and $tools -match 'pdftoppm') { Write-Ok (T 'docker_poppler_ok'); $script:PdfSummary = 'poppler-utils' }
        else { Write-Info (T 'docker_poppler_missing'); $script:PdfSummary = T 'builtin_reader' }
        return
    }
    $dir = Find-Poppler
    if (-not $dir) {
        Write-Info (T 'poppler_missing')
        $script:PdfSummary = T 'builtin_reader'
        return
    }
    $render = Test-Path -LiteralPath (Join-Path $dir 'pdftoppm.exe')
    Write-Ok (T 'poppler_ok' $dir)
    if (-not $render) { Write-Note (T 'poppler_no_render') }
    # The web server often runs as a service, without the PATH of this session: the folder is saved
    if (-not (Invoke-Console (T 'poppler_path_set') @('plugins:ticketassistant:configure', "--set=poppler_path=$dir"))) { Show-OutputBox (T 'console_error') }
    $script:PdfSummary = $(if ($render) { 'poppler' } else { 'pdftotext' })
}

function New-DemoData {
    $wanted = [bool]$Demo
    if (-not $wanted -and $script:Interactive) { $wanted = Read-Confirm (T 'demo_ask') $false }
    if (-not $wanted) { return }
    if (-not (Invoke-Console (T 'demo_running') @('plugins:ticketassistant:demo', '--yes', "--username=$($script:GlpiLogin)"))) { Show-OutputBox (T 'console_error') }
}

# -----------------------------------------------------------------------------
# Flows
# -----------------------------------------------------------------------------
function Show-Summary {
    [void](Read-PluginStatus)
    Show-Title ($script:Sym.star + ' ' + (T 'summary_title'))
    Show-KeyValue (T 's_glpi') "$($script:GlpiVersion)  $($script:GlpiWhere)" 'ok'
    $version = $PluginVersion
    if ($script:PluginStatus['version']) { $version = $script:PluginStatus['version'] }
    $modeText = T 'mode_copy_short'
    if ($script:InstallMode -eq 'Link') { $modeText = T 'mode_link_short' }
    elseif ($script:InstallMode -eq 'Mounted') { $modeText = T 'mode_mount_short' }
    Show-KeyValue (T 's_plugin') "$version  $modeText" 'ok'
    $ai = $script:AiSummary
    if (-not $ai) { $ai = T 'ai_not_configured' }
    $mark = 'warn'
    if ($script:TestResult -eq 'ok') { $ai += '  (' + (T 'test_ok') + ')'; $mark = 'ok' }
    elseif ($script:TestResult -eq 'failed') { $ai += '  (' + (T 'test_failed') + ')'; $mark = 'error' }
    Show-KeyValue (T 's_ai') $ai $mark
    if ($script:LibrarySummary) { Show-KeyValue (T 's_library') $script:LibrarySummary 'ok' }
    if ($script:CronSummary) { Show-KeyValue (T 's_cron') $script:CronSummary $(if ($script:CronSummary -eq (T 'missing')) { 'warn' } else { 'ok' }) }
    if ($script:PdfSummary) { Show-KeyValue (T 's_pdf') $script:PdfSummary 'ok' }
    Show-KeyValue (T 's_log') $script:LogFile
    WL
    WL ('  ' + (T 'next_title')) 'White'
    W '   1 ' 'Cyan'; WL (T 'next_1')
    W '   2 ' 'Cyan'; WL (T 'next_2')
    W '   3 ' 'Cyan'; WL (T 'next_3')
    WL
}

function Invoke-InstallFlow {
    Show-Step 3 (T 'step_plugin')
    if (-not (Install-PluginFiles)) { exit 1 }
    if (-not (Install-InGlpi)) { exit 1 }
    Show-Step 4 (T 'step_ai')
    Set-AiConnection
    Show-Step 5 (T 'step_library')
    New-DemoData
    Sync-Library
    Show-Step 6 (T 'step_extras')
    Set-CronTask
    Test-PdfTools
    Show-Summary
}

function Invoke-StatusFlow {
    if (-not (Confirm-PluginActive)) { exit 1 }
    if (Invoke-Console 'plugins:ticketassistant:status' @('plugins:ticketassistant:status')) {
        foreach ($line in ($script:RunOutput -split "`r?`n" | Where-Object { $_.Trim() })) { WL ('    ' + $line) }
    }
    if (Invoke-Console (T 'testing') @('plugins:ticketassistant:configure', '--test')) { Write-Note (Get-LastOutputLine) } else { Show-OutputBox (T 'test_failed') }
}

function Invoke-UninstallFlow {
    Show-Box 'warn' '' @((T 'un_warning'))
    if ($script:Interactive) {
        if ((Read-Text (T 'un_confirm' $PluginKey) '') -ne $PluginKey) { Write-Info (T 'un_cancelled'); return }
    } elseif (-not $Yes) { Write-Info (T 'un_cancelled'); return }
    [void](Invoke-Console (T 'deactivating') @('plugin:deactivate', $PluginKey))
    if (-not (Invoke-Console (T 'uninstalling') @('plugin:uninstall', $PluginKey))) { Show-OutputBox (T 'console_error'); return }
    $dest = $script:PluginDir
    if ($script:Container) {
        $source = Get-DockerMountAt $dest
        if ($source) {
            Write-Info (T 'docker_mounted_kept' $dest $source)
        } elseif ((Invoke-ProbeResult @('exists', $dest)).Ok -and ((-not $script:Interactive) -or (Read-Confirm (T 'remove_folder' $dest) $true))) {
            if ((Backup-Plugin) -and (Invoke-DockerRoot @('rm', '-rf', $dest))) {
                if ($script:BackupFile) { Write-Ok (T 'removed' $script:BackupFile) }
            }
        }
    } elseif ((Test-Path -LiteralPath $dest) -and ((-not $script:Interactive) -or (Read-Confirm (T 'remove_folder' $dest) $true))) {
        if (Backup-Plugin) {
            if (Test-Path -LiteralPath $dest) { Remove-Item -LiteralPath $dest -Recurse -Force }
            if ($script:BackupFile) { Write-Ok (T 'removed' $script:BackupFile) }
        }
    }
    Write-Ok (T 'uninstalled')
}

function Show-MainMenu([string]$Installed) {
    $first = T 'm_reinstall' $PluginVersion
    if (Test-VersionAtLeast $PluginVersion $Installed) {
        if ($PluginVersion -ne $Installed) { $first = T 'm_update' $PluginVersion }
    }
    $items = @($first, (T 'm_configure'), (T 'm_status'), (T 'm_sync'), (T 'm_uninstall'), (T 'm_exit'))
    switch (Show-Menu (T 'menu_title' $Installed) $items 0) {
        0 { Invoke-InstallFlow }
        1 { Show-Step 4 (T 'step_ai'); if (-not (Confirm-PluginActive)) { exit 1 }; Set-AiConnection; Show-Summary }
        2 { Invoke-StatusFlow }
        3 { if (-not (Confirm-PluginActive)) { exit 1 }; Sync-Library $true }
        4 { Invoke-UninstallFlow }
        default { exit 0 }
    }
}

function Select-Language { # first question of an interactive run: the language of the installer
    if (-not $script:Interactive -or $Lang) { return }
    $codes = @('en', 'pt', 'fr')
    $labels = @('English', 'Português (Brasil)', 'Français')
    $default = [Math]::Max(0, $codes.IndexOf($script:UiLang))
    $script:UiLang = $codes[(Show-Menu ('Language ' + $script:Sym.dot + ' Idioma ' + $script:Sym.dot + ' Langue') $labels $default)]
}

function Invoke-Main {
    if ($Help) { Show-Usage; return }
    Initialize-Ui
    try { Set-Content -LiteralPath $script:LogFile -Value "GLPI AI Ticket Assistant installer $PluginVersion" -Encoding UTF8 } catch { $script:LogFile = '(no log)' }
    Show-Banner
    Select-Language
    Step-Checks
    Step-Glpi
    $installed = Get-InstalledPluginVersion
    if ($Status) { Invoke-StatusFlow }
    elseif ($Configure) { if (-not (Confirm-PluginActive)) { exit 1 }; Set-AiConnection; Show-Summary }
    elseif ($Uninstall) { Invoke-UninstallFlow }
    elseif ($installed -and $script:Interactive) { Show-MainMenu $installed }
    else { Invoke-InstallFlow }
}

# Dot-sourcing the file (tests) only defines the functions
if ($MyInvocation.InvocationName -ne '.') {
    try { Invoke-Main }
    finally { try { [Console]::CursorVisible = $true } catch { } }
}
