<#
.SYNOPSIS
    GLPI AI Ticket Assistant — installer for Windows.

.DESCRIPTION
    From the cloned repository, in PowerShell:

        powershell -ExecutionPolicy Bypass -File .\install.ps1

    Finds the GLPI installations of the machine (XAMPP, WAMP, Laragon, IIS,
    Apache…), copies (or links) the plugin, installs and enables it with the
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
#>
[CmdletBinding()]
param(
    [string]$GlpiPath = '',
    [string]$Php = '',
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
    [ValidateSet('', 'en', 'pt')][string]$Lang = '',
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
    '.gitattributes', '.gitignore', '.editorconfig', 'CONTRIBUTING.md', '*.log')

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
# Messages (English, Brazilian Portuguese)
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
    unattended_glpi  = 'Unattended mode: use -GlpiPath (found: {0}).'
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
    unattended_glpi  = 'Modo não interativo: use -GlpiPath (encontradas: {0}).'
    menu_hint        = '↑↓ mover · Enter escolher'
    filter_hint      = '↑↓ mover · digite para filtrar · Enter escolher · Esc digitar um nome'
    filter_label     = 'Filtro'
    n_of_m           = '{0} de {1}'
    yes              = 'Sim'
    no               = 'Não'
}

$UiLang = 'en'
if ($Lang) { $UiLang = $Lang } elseif ((Get-UICulture).Name -like 'pt*' -or (Get-Culture).Name -like 'pt*') { $UiLang = 'pt' }

function T([string]$Key) {
    $format = $null
    if ($script:UiLang -eq 'pt' -and $script:MsgPt.ContainsKey($Key)) { $format = $script:MsgPt[$Key] }
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

  -GlpiPath PATH       GLPI folder (default: detected)
  -Php PATH            php.exe that runs the GLPI console (default: detected)
  -Mode Copy|Link      copy the plugin (default) or link it to the repository (junction)
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
  -Lang en|pt          language of the installer
  -Yes                 unattended: accept the defaults, ask nothing
  -NoColor             plain output
  -Help                this help
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
            if ($c -eq 'y' -or $c -eq 's') { $answer = $true; break }
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

function Get-FixedDrives {
    try { return @([IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady } | ForEach-Object { $_.RootDirectory.FullName }) }
    catch { return @('C:\') }
}

# Runs a program and returns its exit code and outputs (no PowerShell error records:
# with ErrorActionPreference = Stop, Windows PowerShell turns stderr lines into exceptions)
function Invoke-Capture([string]$FilePath, [string[]]$Arguments = @(), [string]$WorkingDirectory = '') {
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
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; StdOut = $stdout.Result; StdErr = $stderr.Result }
    } catch {
        return [pscustomobject]@{ ExitCode = -1; StdOut = ''; StdErr = $_.Exception.Message }
    }
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

function Invoke-Console([string]$Message, [string[]]$Arguments, [string]$StdinText = $null) {
    # Messages of GLPI and of the plugin in the language of the installer
    $lang = @()
    if ($script:UiLang -eq 'pt') { $lang = @('--lang=pt_BR') }
    $all = @('bin\console', '--no-interaction', '--no-ansi') + $lang + $Arguments
    return Invoke-Step -Message $Message -FilePath $script:PhpExe -Arguments $all -WorkingDirectory $script:Glpi -StdinText $StdinText
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
    # Markers present in GLPI 10, 11 and 12 (front\central.php and inc\define.php are gone in 11)
    return (Test-Path -LiteralPath (Join-Path $Dir 'bin\console')) -and (Test-Path -LiteralPath (Join-Path $Dir 'src\Ticket.php'))
}

function Get-GlpiVersionOf([string]$Dir) {
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

function Get-InstalledPluginVersion {
    return Get-PhpDefine 'PLUGIN_TICKETASSISTANT_VERSION' (Join-Path $script:Glpi "plugins\$PluginKey\setup.php")
}

function Read-PluginStatus {
    $script:PluginStatus = @{}
    $result = Invoke-Capture $script:PhpExe @('bin\console', '--no-interaction', '--no-ansi', 'plugins:ticketassistant:status', '--porcelain') $script:Glpi
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
    if (-not (Test-Admin)) {
        Show-Box 'warn' '' @((T 'not_admin'))
        if ($script:Interactive -and (Read-Confirm (T 'elevate') $false)) { Restart-Elevated }
    }
    $script:PhpCandidates = Find-PhpCandidates
    if (-not $script:PhpCandidates.Count) { Write-Err (T 'php_missing'); exit 1 }
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

function Get-GlpiDescription([string]$Dir) {
    $dest = Join-Path $Dir "plugins\$PluginKey"
    $item = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
    if ($item -and $item.LinkType) { return (T 'plugin_link' (@($item.Target)[0])) }
    $version = Get-PhpDefine 'PLUGIN_TICKETASSISTANT_VERSION' (Join-Path $dest 'setup.php')
    if ($version) { return (T 'plugin_files' $version) }
    return (T 'plugin_none')
}

function Step-Glpi {
    Show-Step 2 (T 'step_glpi')
    $dir = ''
    if ($GlpiPath) {
        $dir = $GlpiPath
    } else {
        W ('  ' + $script:Sym.info + ' ') 'Cyan'; W ((T 'searching') + '…')
        $candidates = Find-Glpi
        W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r")
        if (-not $candidates.Count) {
            Write-Warn (T 'none_found')
            if (-not $script:Interactive) { Write-Err (T 'unattended_glpi' 0); exit 1 }
        } elseif (-not $script:Interactive) {
            if ($candidates.Count -gt 1) { Write-Err (T 'unattended_glpi' ($candidates -join ', ')); exit 1 }
            $dir = $candidates[0]
        } else {
            Write-Ok (T 'found_n' $candidates.Count)
            $items = @($candidates | ForEach-Object { "GLPI $(Get-GlpiVersionOf $_)  $_`t$(Get-GlpiDescription $_)" }) + @((T 'other_path'))
            $choice = Show-Menu (T 'choose_glpi') $items 0
            if ($choice -lt $candidates.Count) { $dir = $candidates[$choice] }
        }
    }
    while ($true) {
        if (-not $dir) { $dir = Read-Text (T 'type_path') 'C:\xampp\htdocs\glpi' }
        $dir = $dir.Trim().TrimEnd('\').Trim('"')
        if (Test-GlpiFolder $dir) { break }
        Write-Err (T 'not_glpi' $dir)
        if (-not $script:Interactive) { exit 1 }
        $dir = ''
    }
    $script:Glpi = (Resolve-Path -LiteralPath $dir).Path
    $script:GlpiVersion = Get-GlpiVersionOf $script:Glpi
    W ('  ' + $script:Sym.ok + ' ') 'Green'; W (T 'glpi_selected' $script:GlpiVersion ''); WL $script:Glpi 'DarkGray'
    if ($script:GlpiVersion) {
        if (-not (Test-VersionAtLeast $script:GlpiVersion $MinGlpi)) { Write-Err (T 'glpi_too_old' $script:GlpiVersion $MinGlpi); exit 1 }
        if (Test-VersionAtLeast $script:GlpiVersion $MaxGlpi) { Write-Warn (T 'glpi_untested' $script:GlpiVersion ($MaxGlpi -replace '\.99$', '')) }
        Select-PhpForGlpi
    }
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
    $dest = Join-Path $script:Glpi "plugins\$PluginKey"
    $item = Get-Item -LiteralPath $dest -ErrorAction SilentlyContinue
    if (-not $item) { return $true }
    if ($item.LinkType) { $item.Delete(); return $true }   # removes the junction, not the repository
    $previous = Get-PhpDefine 'PLUGIN_TICKETASSISTANT_VERSION' (Join-Path $dest 'setup.php')
    if (-not $previous) { $previous = 'unknown' }
    $base = Join-Path $env:ProgramData 'glpi-ticketassistant\backups'
    if (-not (Test-Writable $base)) { $base = Join-Path $env:LOCALAPPDATA 'glpi-ticketassistant\backups' }
    New-Item -ItemType Directory -Path $base -Force | Out-Null
    $script:BackupFile = Join-Path $base ("$PluginKey-$previous-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
    # Pending line while the archive is written, replaced by the result
    if ($script:Interactive) { W ('  ' + $script:Sym.info + ' ') 'Cyan'; W ((T 'backing_up') + '…') }
    $clear = { if ($script:Interactive) { W ("`r" + (' ' * ((Get-WindowWidth) - 1)) + "`r") } }
    try {
        Compress-Archive -Path (Join-Path $dest '*') -DestinationPath $script:BackupFile -Force
        & $clear; Write-Ok (T 'backing_up')
        Write-Note (T 'backup_done' $script:BackupFile)
        return $true
    } catch {
        & $clear; Write-Err (T 'backing_up'); Write-Note $_.Exception.Message
        return $false
    }
}

function Install-PluginFiles {
    $dest = Join-Path $script:Glpi "plugins\$PluginKey"
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
    if (-not (Test-Writable (Join-Path $script:Glpi 'plugins'))) {
        Write-Err (T 'plugins_not_writable' (Join-Path $script:Glpi 'plugins'))
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
    $arguments = @($RepoDir, $dest, '/MIR', '/R:1', '/W:1', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/XD') +
    ($ExcludeDirs | ForEach-Object { Join-Path $RepoDir $_ }) + @('/XF') + $ExcludeFiles
    if (-not (Invoke-Step -Message (T 'copying' $dest) -FilePath 'robocopy.exe' -Arguments $arguments -WorkingDirectory $RepoDir -SuccessCodes @(0, 1, 2, 3, 4, 5, 6, 7))) {
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
        if ($script:UiLang -eq 'pt') { $default = 1 }
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

function Set-CronTask {
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
    Show-KeyValue (T 's_glpi') "$($script:GlpiVersion)  $($script:Glpi)" 'ok'
    $version = $PluginVersion
    if ($script:PluginStatus['version']) { $version = $script:PluginStatus['version'] }
    $modeText = $(if ($script:InstallMode -eq 'Link') { T 'mode_link_short' } else { T 'mode_copy_short' })
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
    $dest = Join-Path $script:Glpi "plugins\$PluginKey"
    if ((Test-Path -LiteralPath $dest) -and ((-not $script:Interactive) -or (Read-Confirm (T 'remove_folder' $dest) $true))) {
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

function Invoke-Main {
    if ($Help) { Show-Usage; return }
    Initialize-Ui
    try { Set-Content -LiteralPath $script:LogFile -Value "GLPI AI Ticket Assistant installer $PluginVersion" -Encoding UTF8 } catch { $script:LogFile = '(no log)' }
    Show-Banner
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
