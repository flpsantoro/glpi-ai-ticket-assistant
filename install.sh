#!/usr/bin/env bash
# =============================================================================
#  GLPI AI Ticket Assistant — installer for Linux
#
#  From the cloned repository:   sudo ./install.sh
#  Help and unattended mode:     ./install.sh --help
#
#  Finds the GLPI installations of the server, copies (or links) the plugin,
#  installs and enables it with the GLPI console, connects the AI provider,
#  builds the library of solved tickets and checks the cron and the PDF tools.
#  Nothing is changed without asking (or --yes).
#
#  SPDX-License-Identifier: GPL-2.0-or-later
# =============================================================================

if [ -z "${BASH_VERSION:-}" ] || [ "${BASH_VERSINFO[0]:-0}" -lt 4 ]; then
    echo "This installer needs bash 4 or later: sudo bash ./install.sh" >&2
    exit 1
fi
set -o pipefail
umask 022

readonly PLUGIN_KEY=ticketassistant
REPO_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
readonly REPO_DIR

php_define() { sed -n "s/.*define('$1', *'\([^']*\)').*/\1/p" "$2" 2>/dev/null | head -n1; }
PLUGIN_VERSION=$(php_define PLUGIN_TICKETASSISTANT_VERSION "$REPO_DIR/setup.php")
MIN_GLPI=$(php_define PLUGIN_TICKETASSISTANT_MIN_GLPI "$REPO_DIR/setup.php")
MAX_GLPI=$(php_define PLUGIN_TICKETASSISTANT_MAX_GLPI "$REPO_DIR/setup.php")
LOG_FILE="${TMPDIR:-/tmp}/ticketassistant-install-$(date +%Y%m%d-%H%M%S).log"

# Files of the repository that are not part of the plugin
COPY_EXCLUDES=(.git .github docs tests tools vendor node_modules install.sh install.ps1 composer.json
    composer.lock phpunit.xml.dist .phpunit.result.cache .phpunit.cache .gitattributes .gitignore
    .editorconfig CONTRIBUTING.md)

# Providers: id, default address, key page, model suggestions
PROVIDERS=(openai azure anthropic gemini mistral openrouter ollama compatible)
declare -A PROVIDER_URL=(
    [openai]=https://api.openai.com/v1 [azure]="" [anthropic]=https://api.anthropic.com
    [gemini]=https://generativelanguage.googleapis.com/v1beta/openai [mistral]=https://api.mistral.ai/v1
    [openrouter]=https://openrouter.ai/api/v1 [ollama]=http://localhost:11434/v1 [compatible]=http://localhost:1234/v1
)
declare -A PROVIDER_KEYS_PAGE=(
    [openai]=https://platform.openai.com/api-keys [azure]="Azure portal > Keys and Endpoint"
    [anthropic]=https://console.anthropic.com/settings/keys [gemini]=https://aistudio.google.com/apikey
    [mistral]=https://console.mistral.ai/api-keys [openrouter]=https://openrouter.ai/keys
)
declare -A PROVIDER_MODELS=(
    [openai]="gpt-5-mini gpt-5.4 gpt-4.1-mini" [anthropic]="claude-sonnet-5-5 claude-haiku-5-5"
    [gemini]="gemini-2.5-flash gemini-2.5-pro" [mistral]="mistral-medium-latest pixtral-large-latest"
    [openrouter]="openai/gpt-5-mini anthropic/claude-sonnet-5-5" [ollama]="qwen2.5vl gemma3 llama3.2-vision"
)

# -----------------------------------------------------------------------------
# Options
# -----------------------------------------------------------------------------
OPT_GLPI="" OPT_PHP="" OPT_WEB_USER="" OPT_LOGIN="" OPT_MODE="" OPT_LANG="" OPT_ACTION=""
OPT_PROVIDER="" OPT_BASE_URL="" OPT_MODEL="" OPT_API_VERSION="" OPT_KEY_ENV=""
OPT_SKIP_AI=0 OPT_SYNC="" OPT_CRON="" OPT_POPPLER="" OPT_DEMO=0 OPT_YES=0 OPT_NO_COLOR=0 OPT_HELP=0

normalized=()
for arg in "$@"; do
    if [[ $arg == --*=* ]]; then normalized+=("${arg%%=*}" "${arg#*=}"); else normalized+=("$arg"); fi
done
set -- "${normalized[@]}"
while (($#)); do
    case $1 in
        --glpi) OPT_GLPI=${2:-}; shift ;;
        --php) OPT_PHP=${2:-}; shift ;;
        --web-user) OPT_WEB_USER=${2:-}; shift ;;
        --glpi-user) OPT_LOGIN=${2:-}; shift ;;
        --mode) OPT_MODE=${2:-}; shift ;;
        --provider) OPT_PROVIDER=${2:-}; shift ;;
        --base-url) OPT_BASE_URL=${2:-}; shift ;;
        --model) OPT_MODEL=${2:-}; shift ;;
        --api-version) OPT_API_VERSION=${2:-}; shift ;;
        --api-key-env) OPT_KEY_ENV=${2:-}; shift ;;
        --skip-ai) OPT_SKIP_AI=1 ;;
        --sync) OPT_SYNC=${2:-}; shift ;;
        --cron) OPT_CRON=${2:-}; shift ;;
        --poppler) OPT_POPPLER=${2:-}; shift ;;
        --demo) OPT_DEMO=1 ;;
        --status) OPT_ACTION=status ;;
        --configure) OPT_ACTION=configure ;;
        --uninstall) OPT_ACTION=uninstall ;;
        --lang) OPT_LANG=${2:-}; shift ;;
        -y | --yes) OPT_YES=1 ;;
        --no-color) OPT_NO_COLOR=1 ;;
        -h | --help) OPT_HELP=1 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
    esac
    shift
done

case ${OPT_LANG:-${LC_ALL:-${LC_MESSAGES:-${LANG:-en}}}} in
    pt* | PT*) UI_LANG=pt ;;
    *) UI_LANG=en ;;
esac

# The terminal is assumed to show UTF-8 when the locale says so. In any case
# bash must count characters, not bytes, to align the boxes (accented messages)
TERMINAL_LOCALE="${LC_ALL:-}${LC_CTYPE:-}${LANG:-}"
case $TERMINAL_LOCALE in
    *UTF-8* | *utf-8* | *UTF8* | *utf8*) ;;
    *)
        for candidate in C.UTF-8 C.utf8 en_US.UTF-8 en_US.utf8; do
            if locale -a 2>/dev/null | grep -qix "$candidate"; then
                if [[ -n ${LC_ALL:-} ]]; then export LC_ALL=$candidate; else export LC_CTYPE=$candidate; fi
                break
            fi
        done
        ;;
esac

# -----------------------------------------------------------------------------
# Messages (English, Brazilian Portuguese)
# -----------------------------------------------------------------------------
declare -A MSG_EN=(
    [subtitle]="Installer for Linux"
    [step_checks]="Checking the system"
    [step_glpi]="Locating GLPI"
    [step_plugin]="Installing the plugin"
    [step_ai]="Connecting the AI"
    [step_library]="Learning from solved tickets"
    [step_extras]="Automatic actions and PDF tools"
    [not_repo]="Run this script from the cloned repository (setup.php of the plugin not found next to it)."
    [need_root]="Not running as root: copying into the web directory and running the GLPI console as the web server user usually need sudo ./install.sh"
    [continue_anyway]="Continue anyway?"
    [php_found]="PHP %s  %s"
    [php_missing]="PHP CLI not found. Install it (apt install php-cli, dnf install php-cli) or use --php PATH."
    [ext_ok]="PHP extensions: %s"
    [ext_missing]="Missing PHP extensions on the command line: %s"
    [ext_hint]="Install them for the PHP of the web server too (e.g. apt install php-curl php-mbstring php-gd php-zip) and restart it."
    [copy_tool]="Copy tool: %s"
    [plugin_repo]="Plugin %s from %s"
    [searching]="Searching for GLPI installations"
    [found_n]="%d GLPI installation(s) found"
    [none_found]="No GLPI installation found automatically."
    [choose_glpi]="Which GLPI should receive the plugin?"
    [other_path]="Another folder…\ttype the path of GLPI"
    [type_path]="Folder of GLPI (the one that contains bin/console)"
    [not_glpi]="%s is not a GLPI folder (bin/console and src/Ticket.php not found)."
    [glpi_too_old]="GLPI %s is not supported: the plugin needs GLPI %s or later."
    [glpi_untested]="GLPI %s is newer than the versions tested (up to %s): it may work, report any problem."
    [glpi_selected]="GLPI %s  %s"
    [plugin_none]="plugin not installed"
    [plugin_files]="plugin %s"
    [plugin_link]="plugin linked to %s"
    [web_user]="Web server user: %s"
    [web_user_ask]="Which user runs the web server (owner of the GLPI files)?"
    [php_too_old]="GLPI %s needs PHP %s or later, but %s is PHP %s."
    [choose_php]="Which PHP should run the GLPI console?"
    [menu_title]="The plugin %s is already in this GLPI. What do you want to do?"
    [m_update]="Update to version %s\tcopies the files and runs the migrations"
    [m_reinstall]="Reinstall version %s\tcopies the files again, keeps the data"
    [m_configure]="Configure the AI provider\tprovider, model, key, connection test"
    [m_status]="Status and connection test\tconnection, library, automatic actions"
    [m_sync]="Synchronize the library of solved tickets\tno AI call, no cost"
    [m_uninstall]="Uninstall\tremoves the plugin, its data and its configuration"
    [m_exit]="Exit"
    [mode_ask]="How should the plugin be placed in GLPI?"
    [mode_copy]="Copy the files\trecommended for production"
    [mode_link]="Link to this repository\tdevelopment: a git pull updates GLPI"
    [in_place]="The plugin files are already in %s"
    [backing_up]="Saving the previous version"
    [backup_done]="Previous version saved in %s"
    [copying]="Copying the plugin to %s"
    [linking]="Linking %s to the repository"
    [link_unreadable]="The user %s cannot read the repository %s: use the copy mode or move the repository."
    [perms]="Owner %s and permissions"
    [selinux]="SELinux context restored"
    [login_ask]="Login of a GLPI super-admin (used during the installation)"
    [installing]="Installing in GLPI (tables, rights, automatic actions)"
    [activating]="Enabling the plugin"
    [verifying]="Checking that GLPI loaded the plugin"
    [console_error]="The GLPI console reported an error"
    [retry_login]="Try again with another login?"
    [plugin_inactive]="GLPI has not installed or enabled the plugin yet."
    [install_now]="Install and enable it now?"
    [ai_intro]="The assistant needs an AI provider: a cloud service (with a key) or a local server such as Ollama. Nothing is sent before a technician clicks a button."
    [ai_now]="Configure the AI provider now?"
    [ai_current]="Current connection: %s, model \"%s\", %s."
    [ai_change]="Change the AI connection?"
    [state_ready]="ready"
    [state_not_ready]="not ready"
    [choose_provider]="Which AI provider?"
    [p_openai]="OpenAI\tGPT models: vision, PDF files, embeddings"
    [p_azure]="Azure OpenAI\tdeployments of your Azure resource"
    [p_anthropic]="Anthropic (Claude)\tvision and PDF files; embeddings need a second provider"
    [p_gemini]="Google Gemini\tvision and embeddings"
    [p_mistral]="Mistral AI\tPixtral reads images; embeddings"
    [p_openrouter]="OpenRouter\tone key, hundreds of models"
    [p_ollama]="Ollama\tlocal models: no key, no cost, data stays in house"
    [p_compatible]="Other OpenAI-compatible API\tLM Studio, vLLM, LiteLLM, Groq, DeepSeek…"
    [azure_url]="Address of the Azure resource (https://<resource>.openai.azure.com)"
    [azure_version]="API version (\"v1\" for the new v1 API)"
    [base_url_ask]="Address of the API (as seen from the GLPI server)"
    [key_where]="Create a key at %s"
    [key_ask]="API key (hidden; paste it with Ctrl+Shift+V or the right button)"
    [key_optional]="API key (optional for this provider: Enter to skip)"
    [key_keep]="Keep the API key already saved?"
    [key_empty]="This provider needs a key."
    [saving]="Saving the connection"
    [listing]="Asking the provider for its models"
    [choose_model]="Which model?"
    [model_manual]="Type another name…"
    [model_ask]="Model name"
    [deployment_ask]="Deployment name (Azure)"
    [model_hint]="Suggestions: %s"
    [models_none]="The provider did not return a list of models: type the name."
    [vision_tip]="Prefer a model that reads images: tickets often carry screenshots."
    [testing]="Testing the connection"
    [test_menu]="The connection test failed. What now?"
    [tf_retry]="Test again"
    [tf_change]="Change the settings"
    [tf_skip]="Continue, fix it later in Setup > AI Ticket Assistant"
    [enabling_ai]="Enabling the assistant"
    [personalize]="Personalize the answers now (organization, language)?"
    [org_ask]="Name of the organization or service desk (used in the answers)"
    [lang_ask]="Language of the answers"
    [lang_auto]="Same language as the ticket"
    [costs_ask]="Set the token prices and the daily budget now? (needed for the cost limits)"
    [price_in]="Price per million input tokens (US$, from the price list of the model)"
    [price_out]="Price per million output tokens (US$)"
    [budget]="Daily budget of the technicians (US$, 0 = none)"
    [saving_prefs]="Saving the preferences"
    [sync_ask]="Build the library of solved tickets now? (no AI call, no cost)"
    [syncing]="Reading the solved and closed tickets"
    [cron_found]="GLPI cron found: the automatic actions run in the background."
    [cron_missing]="No cron runs the automatic actions of GLPI: the library sync (aisync), learning (ailearn), triage (aitriage) and log purge (aipurge) run in CLI mode."
    [cron_create]="Create %s (every minute, as %s)?"
    [cron_created]="Cron created: %s"
    [cron_manual]="Add this line to the crontab of %s:"
    [poppler_ok]="poppler-utils found: complex and scanned PDFs are read."
    [poppler_missing]="poppler-utils is not installed: the built-in reader extracts the text of digital PDFs, but scanned PDFs are not read."
    [poppler_install]="Install poppler-utils now (%s)?"
    [poppler_installing]="Installing poppler-utils"
    [demo_ask]="Create demonstration data (an entity, categories and 43 tickets)? Only on a TEST instance."
    [demo_running]="Creating the demonstration data"
    [summary_title]="Done"
    [s_glpi]="GLPI"
    [s_plugin]="Plugin"
    [s_ai]="AI"
    [s_library]="Library"
    [s_cron]="Cron"
    [s_pdf]="PDF"
    [s_log]="Log"
    [mode_copy_short]="copied"
    [mode_link_short]="linked to the repository"
    [ai_not_configured]="not configured"
    [test_ok]="connection OK"
    [test_failed]="connection test failed"
    [cases]="%s solved tickets"
    [skipped]="skipped"
    [found]="found"
    [missing]="missing"
    [created]="created"
    [builtin_reader]="built-in reader (digital PDFs)"
    [next_title]="Next steps"
    [next_1]="Review every setting in GLPI > Setup > AI Ticket Assistant."
    [next_2]="Technician profiles received the right \"AI assistant\": adjust it in Administration > Profiles."
    [next_3]="Open a ticket and click \"AI assistant\" at the bottom of the timeline."
    [un_warning]="Uninstalling deletes the data of the plugin: analysis log, library of solved tickets, suggestions, AI guidelines of the categories and its configuration, API keys included."
    [un_confirm]="Type %s to confirm"
    [un_cancelled]="Uninstallation cancelled."
    [deactivating]="Disabling the plugin"
    [uninstalling]="Uninstalling the plugin"
    [remove_folder]="Also delete the folder %s? (a backup is kept)"
    [removed]="Folder removed; backup in %s"
    [uninstalled]="The plugin was uninstalled."
    [cancelled]="Cancelled."
    [log_at]="Full log: %s"
    [unattended_glpi]="Unattended mode: use --glpi PATH (%d installations found)."
    [menu_hint]="↑↓ move · Enter choose"
    [filter_hint]="↑↓ move · type to filter · Enter choose · Esc type a name"
    [filter_label]="Filter"
    [n_of_m]="%d of %d"
    [yes]="Yes"
    [no]="No"
    [press_enter]="Press Enter to continue"
)
declare -A MSG_PT=(
    [subtitle]="Instalador para Linux"
    [step_checks]="Verificando o sistema"
    [step_glpi]="Localizando o GLPI"
    [step_plugin]="Instalando o plugin"
    [step_ai]="Conectando a IA"
    [step_library]="Aprendendo com os chamados resolvidos"
    [step_extras]="Ações automáticas e ferramentas de PDF"
    [not_repo]="Execute este script a partir do repositório clonado (o setup.php do plugin não está ao lado dele)."
    [need_root]="Sem root: copiar para o diretório web e rodar o console do GLPI como o usuário do servidor web geralmente exigem sudo ./install.sh"
    [continue_anyway]="Continuar mesmo assim?"
    [php_missing]="PHP CLI não encontrado. Instale (apt install php-cli, dnf install php-cli) ou use --php CAMINHO."
    [ext_ok]="Extensões do PHP: %s"
    [ext_missing]="Extensões do PHP ausentes na linha de comando: %s"
    [ext_hint]="Instale-as também para o PHP do servidor web (ex.: apt install php-curl php-mbstring php-gd php-zip) e reinicie-o."
    [copy_tool]="Ferramenta de cópia: %s"
    [plugin_repo]="Plugin %s de %s"
    [searching]="Procurando instalações do GLPI"
    [found_n]="%d instalação(ões) do GLPI encontrada(s)"
    [none_found]="Nenhuma instalação do GLPI encontrada automaticamente."
    [choose_glpi]="Em qual GLPI instalar o plugin?"
    [other_path]="Outra pasta…\tdigitar o caminho do GLPI"
    [type_path]="Pasta do GLPI (a que contém bin/console)"
    [not_glpi]="%s não é uma pasta do GLPI (bin/console e src/Ticket.php não encontrados)."
    [glpi_too_old]="O GLPI %s não é suportado: o plugin precisa do GLPI %s ou mais recente."
    [glpi_untested]="O GLPI %s é mais novo que as versões testadas (até %s): deve funcionar, relate qualquer problema."
    [plugin_none]="plugin não instalado"
    [plugin_link]="plugin vinculado a %s"
    [web_user]="Usuário do servidor web: %s"
    [web_user_ask]="Qual usuário roda o servidor web (dono dos arquivos do GLPI)?"
    [php_too_old]="O GLPI %s precisa do PHP %s ou mais recente, mas %s é o PHP %s."
    [choose_php]="Qual PHP deve executar o console do GLPI?"
    [menu_title]="O plugin %s já está neste GLPI. O que deseja fazer?"
    [m_update]="Atualizar para a versão %s\tcopia os arquivos e roda as migrações"
    [m_reinstall]="Reinstalar a versão %s\tcopia os arquivos de novo, mantém os dados"
    [m_configure]="Configurar o provedor de IA\tprovedor, modelo, chave, teste de conexão"
    [m_status]="Status e teste de conexão\tconexão, biblioteca, ações automáticas"
    [m_sync]="Sincronizar a biblioteca de chamados resolvidos\tsem chamar a IA, sem custo"
    [m_uninstall]="Desinstalar\tremove o plugin, seus dados e sua configuração"
    [m_exit]="Sair"
    [mode_ask]="Como o plugin deve ser colocado no GLPI?"
    [mode_copy]="Copiar os arquivos\trecomendado para produção"
    [mode_link]="Vincular a este repositório\tdesenvolvimento: um git pull atualiza o GLPI"
    [in_place]="Os arquivos do plugin já estão em %s"
    [backing_up]="Guardando a versão anterior"
    [backup_done]="Versão anterior guardada em %s"
    [copying]="Copiando o plugin para %s"
    [linking]="Vinculando %s ao repositório"
    [link_unreadable]="O usuário %s não consegue ler o repositório %s: use o modo cópia ou mova o repositório."
    [perms]="Dono %s e permissões"
    [selinux]="Contexto SELinux restaurado"
    [login_ask]="Login de um super-admin do GLPI (usado durante a instalação)"
    [installing]="Instalando no GLPI (tabelas, direitos, ações automáticas)"
    [activating]="Habilitando o plugin"
    [verifying]="Verificando se o GLPI carregou o plugin"
    [console_error]="O console do GLPI informou um erro"
    [retry_login]="Tentar de novo com outro login?"
    [plugin_inactive]="O GLPI ainda não instalou ou não habilitou o plugin."
    [install_now]="Instalar e habilitar agora?"
    [ai_intro]="O assistente precisa de um provedor de IA: um serviço em nuvem (com chave) ou um servidor local como o Ollama. Nada é enviado antes de um técnico clicar em um botão."
    [ai_now]="Configurar o provedor de IA agora?"
    [ai_current]="Conexão atual: %s, modelo \"%s\", %s."
    [ai_change]="Alterar a conexão com a IA?"
    [state_ready]="pronta"
    [state_not_ready]="incompleta"
    [choose_provider]="Qual provedor de IA?"
    [p_openai]="OpenAI\tmodelos GPT: visão, arquivos PDF, embeddings"
    [p_azure]="Azure OpenAI\timplantações do seu recurso Azure"
    [p_anthropic]="Anthropic (Claude)\tvisão e arquivos PDF; embeddings exigem um segundo provedor"
    [p_gemini]="Google Gemini\tvisão e embeddings"
    [p_mistral]="Mistral AI\to Pixtral lê imagens; embeddings"
    [p_openrouter]="OpenRouter\tuma chave, centenas de modelos"
    [p_ollama]="Ollama\tmodelos locais: sem chave, sem custo, os dados ficam na casa"
    [p_compatible]="Outra API compatível com OpenAI\tLM Studio, vLLM, LiteLLM, Groq, DeepSeek…"
    [azure_url]="Endereço do recurso Azure (https://<recurso>.openai.azure.com)"
    [azure_version]="Versão da API (\"v1\" para a nova API v1)"
    [base_url_ask]="Endereço da API (como o servidor do GLPI o enxerga)"
    [key_where]="Crie uma chave em %s"
    [key_ask]="Chave da API (oculta; cole com Ctrl+Shift+V ou o botão direito)"
    [key_optional]="Chave da API (opcional para este provedor: Enter para pular)"
    [key_keep]="Manter a chave da API já salva?"
    [key_empty]="Este provedor exige uma chave."
    [saving]="Salvando a conexão"
    [listing]="Consultando os modelos do provedor"
    [choose_model]="Qual modelo?"
    [model_manual]="Digitar outro nome…"
    [model_ask]="Nome do modelo"
    [deployment_ask]="Nome da implantação (Azure)"
    [model_hint]="Sugestões: %s"
    [models_none]="O provedor não retornou a lista de modelos: digite o nome."
    [vision_tip]="Prefira um modelo que leia imagens: chamados costumam trazer prints."
    [testing]="Testando a conexão"
    [test_menu]="O teste de conexão falhou. E agora?"
    [tf_retry]="Testar de novo"
    [tf_change]="Alterar as configurações"
    [tf_skip]="Continuar e corrigir depois em Configurar > AI Ticket Assistant"
    [enabling_ai]="Ativando o assistente"
    [personalize]="Personalizar as respostas agora (organização, idioma)?"
    [org_ask]="Nome da organização ou da central de serviços (usado nas respostas)"
    [lang_ask]="Idioma das respostas"
    [lang_auto]="O mesmo idioma do chamado"
    [costs_ask]="Definir agora os preços por token e o orçamento diário? (necessários para os limites de custo)"
    [price_in]="Preço por milhão de tokens de entrada (US$, da tabela de preços do modelo)"
    [price_out]="Preço por milhão de tokens de saída (US$)"
    [budget]="Orçamento diário dos técnicos (US$, 0 = sem limite)"
    [saving_prefs]="Salvando as preferências"
    [sync_ask]="Montar agora a biblioteca de chamados resolvidos? (sem chamar a IA, sem custo)"
    [syncing]="Lendo os chamados solucionados e fechados"
    [cron_found]="Cron do GLPI encontrado: as ações automáticas rodam em segundo plano."
    [cron_missing]="Nenhum cron executa as ações automáticas do GLPI: a sincronização da biblioteca (aisync), o aprendizado (ailearn), a triagem (aitriage) e a limpeza do log (aipurge) rodam em modo CLI."
    [cron_create]="Criar %s (a cada minuto, como %s)?"
    [cron_created]="Cron criado: %s"
    [cron_manual]="Adicione esta linha ao crontab de %s:"
    [poppler_ok]="poppler-utils encontrado: PDFs complexos e digitalizados são lidos."
    [poppler_missing]="O poppler-utils não está instalado: o leitor embutido extrai o texto de PDFs digitais, mas PDFs digitalizados não são lidos."
    [poppler_install]="Instalar o poppler-utils agora (%s)?"
    [poppler_installing]="Instalando o poppler-utils"
    [demo_ask]="Criar dados de demonstração (uma entidade, categorias e 43 chamados)? Somente em instância de TESTE."
    [demo_running]="Criando os dados de demonstração"
    [summary_title]="Pronto"
    [s_ai]="IA"
    [s_library]="Biblioteca"
    [mode_copy_short]="copiado"
    [mode_link_short]="vinculado ao repositório"
    [ai_not_configured]="não configurada"
    [test_ok]="conexão OK"
    [test_failed]="o teste de conexão falhou"
    [cases]="%s chamados resolvidos"
    [skipped]="pulado"
    [found]="encontrado"
    [missing]="ausente"
    [created]="criado"
    [builtin_reader]="leitor embutido (PDFs digitais)"
    [next_title]="Próximos passos"
    [next_1]="Revise todas as opções em GLPI > Configurar > AI Ticket Assistant."
    [next_2]="Os perfis de técnico receberam o direito \"AI assistant\": ajuste em Administração > Perfis."
    [next_3]="Abra um chamado e clique em \"AI assistant\" no fim da linha do tempo."
    [un_warning]="Desinstalar apaga os dados do plugin: log de análises, biblioteca de chamados resolvidos, sugestões, orientações de IA das categorias e a configuração, inclusive as chaves de API."
    [un_confirm]="Digite %s para confirmar"
    [un_cancelled]="Desinstalação cancelada."
    [deactivating]="Desabilitando o plugin"
    [uninstalling]="Desinstalando o plugin"
    [remove_folder]="Apagar também a pasta %s? (um backup é mantido)"
    [removed]="Pasta removida; backup em %s"
    [uninstalled]="O plugin foi desinstalado."
    [cancelled]="Cancelado."
    [log_at]="Log completo: %s"
    [unattended_glpi]="Modo não interativo: use --glpi CAMINHO (%d instalações encontradas)."
    [menu_hint]="↑↓ mover · Enter escolher"
    [filter_hint]="↑↓ mover · digite para filtrar · Enter escolher · Esc digitar um nome"
    [filter_label]="Filtro"
    [n_of_m]="%d de %d"
    [yes]="Sim"
    [no]="Não"
    [press_enter]="Pressione Enter para continuar"
)

t() {
    local key=$1 fmt
    shift
    if [[ $UI_LANG == pt && -n ${MSG_PT[$key]+set} ]]; then fmt=${MSG_PT[$key]}; else fmt=${MSG_EN[$key]:-$key}; fi
    # shellcheck disable=SC2059
    printf -- "$fmt" "$@"
}

usage() {
    cat <<EOF
GLPI AI Ticket Assistant ${PLUGIN_VERSION} — installer for Linux

Usage: sudo ./install.sh [options]

Without options the installer asks every question in a terminal menu.

  --glpi PATH          GLPI folder (default: detected)
  --php PATH           PHP binary that runs the GLPI console (default: detected)
  --web-user USER      owner of the GLPI files (default: detected)
  --glpi-user LOGIN    GLPI super-admin login used by the installation (default: glpi)
  --mode copy|link     copy the plugin (default) or link it to the repository
  --provider ID        AI provider: ${PROVIDERS[*]}
  --base-url URL       API address (Azure resource, local server…)
  --model NAME         model (Azure: deployment name)
  --api-version VER    Azure API version
  --api-key-env VAR    read the API key from the environment variable VAR
  --skip-ai            do not configure the AI provider
  --sync yes|no        build the library of solved tickets (default: yes)
  --cron yes|no        create /etc/cron.d/glpi-cron when no GLPI cron exists
  --poppler yes|no     install poppler-utils when missing
  --demo               create demonstration data (test instances only)
  --status             show the state of the assistant and test the connection
  --configure          only configure the AI provider
  --uninstall          uninstall the plugin
  --lang en|pt         language of the installer
  -y, --yes            unattended: accept the defaults, ask nothing
  --no-color           plain output
  -h, --help           this help

Unattended example:
  OPENAI_API_KEY=sk-... sudo -E ./install.sh --yes --glpi /var/www/glpi \\
      --provider openai --model gpt-5-mini --api-key-env OPENAI_API_KEY --cron yes
EOF
}

# -----------------------------------------------------------------------------
# Terminal interface
# -----------------------------------------------------------------------------
COLOR=0 INTERACTIVE=0 IS_TTY=0 UNICODE=0 TERM_WIDTH=80 TERM_LINES=24
C_RESET="" C_BOLD="" C_DIM="" C_ITALIC="" C_REV="" C_RED="" C_GREEN="" C_YELLOW="" C_BLUE="" C_CYAN="" C_MAGENTA="" C_ACCENT=""
CUR_HIDE="" CUR_SHOW=""
GRADIENT=()
TMP_FILES=()
RUN_OUT=/dev/null
REPLY_INDEX=0 REPLY_TEXT="" KEY="" KEY_CHAR=""

ui_init() {
    [[ -t 1 ]] && IS_TTY=1
    [[ -t 0 && -t 1 && $OPT_YES -eq 0 ]] && INTERACTIVE=1
    if ((IS_TTY)) && [[ -z ${NO_COLOR:-} && ${TERM:-dumb} != dumb && $OPT_NO_COLOR -eq 0 ]]; then COLOR=1; fi
    case $TERMINAL_LOCALE in *UTF-8* | *utf-8* | *UTF8* | *utf8*) UNICODE=1 ;; esac
    if ((IS_TTY)); then
        local size
        size=$(stty size 2>/dev/null) && { TERM_LINES=${size% *}; TERM_WIDTH=${size#* }; }
    fi
    ((TERM_WIDTH < 40)) && TERM_WIDTH=40
    ((TERM_WIDTH > 100)) && TERM_WIDTH=100
    if ((COLOR)); then
        C_RESET=$'\e[0m' C_BOLD=$'\e[1m' C_DIM=$'\e[2m' C_ITALIC=$'\e[3m' C_REV=$'\e[7m'
        C_RED=$'\e[31m' C_GREEN=$'\e[32m' C_YELLOW=$'\e[33m' C_BLUE=$'\e[34m' C_MAGENTA=$'\e[35m' C_CYAN=$'\e[36m'
        C_ACCENT=$'\e[36m'
        local colors
        colors=$(tput colors 2>/dev/null || echo 8)
        if [[ ${COLORTERM:-} == *truecolor* || ${COLORTERM:-} == *24bit* || $colors -ge 256 ]]; then
            C_ACCENT=$'\e[38;5;45m'
            local code
            for code in 51 45 39 33 63 99 135 171; do GRADIENT+=($'\e[38;5;'"$code"'m'); done
        fi
    fi
    if ((IS_TTY)); then CUR_HIDE=$'\e[?25l' CUR_SHOW=$'\e[?25h'; fi
    if ((UNICODE)); then
        SYM_OK="✔" SYM_ERR="✖" SYM_WARN="▲" SYM_INFO="●" SYM_ASK="?" SYM_POINTER="❯" SYM_BULLET="•" SYM_STAR="✦" SYM_DOT="·"
        BOX=(╭ ─ ╮ │ ╰ ╯) RULE="━" SPINNER=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏) CARET="▏"
    else
        SYM_OK="[ok]" SYM_ERR="[x]" SYM_WARN="[!]" SYM_INFO="[i]" SYM_ASK="?" SYM_POINTER=">" SYM_BULLET="*" SYM_STAR="*" SYM_DOT="-"
        BOX=(+ - + "|" + +) RULE="=" SPINNER=("|" "/" "-" "\\") CARET="_"
        MSG_EN[menu_hint]="up/down move - Enter choose" MSG_PT[menu_hint]="cima/baixo mover - Enter escolher"
        MSG_EN[filter_hint]="up/down move - type to filter - Enter choose - Esc type a name"
        MSG_PT[filter_hint]="cima/baixo mover - digite para filtrar - Enter escolher - Esc digitar um nome"
    fi
}

ui_cleanup() {
    printf '%s' "$CUR_SHOW"
    ((IS_TTY)) && stty echo 2>/dev/null
    local file
    for file in "${TMP_FILES[@]}"; do [[ -f $file ]] && rm -f "$file"; done
}
trap ui_cleanup EXIT
trap 'printf "\n"; ui_warn "$(t cancelled)"; exit 130' INT TERM

ui_repeat() { local out="" i; for ((i = 0; i < $2; i++)); do out+=$1; done; printf '%s' "$out"; }

ui_gradient() { # colors each character of $1 along the gradient
    local text=$1 len=${#1} i out=""
    if ((${#GRADIENT[@]} == 0)); then printf '%s%s%s' "$C_ACCENT$C_BOLD" "$text" "$C_RESET"; return; fi
    for ((i = 0; i < len; i++)); do out+="${GRADIENT[i * ${#GRADIENT[@]} / (len > 0 ? len : 1)]}${text:i:1}"; done
    printf '%s%s%s' "$C_BOLD" "$out" "$C_RESET"
}

ui_banner() {
    printf '\n'
    if ((UNICODE)); then
        printf '   %s\n' "$(ui_gradient "▀█▀ █ █▀▀ █▄▀ █▀▀ ▀█▀   ▄▀█ █▀ █▀ █ █▀ ▀█▀ ▄▀█ █▄ █ ▀█▀")"
        printf '   %s\n' "$(ui_gradient " █  █ █▄▄ █ █ ██▄  █    █▀█ ▄█ ▄█ █ ▄█  █  █▀█ █ ▀█  █ ")"
    else
        printf '   %s\n' "$(ui_gradient "== TICKET ASSISTANT ==")"
    fi
    printf '   %s%s GLPI AI Ticket Assistant %s %s %s v%s%s\n' "$C_DIM" "$SYM_STAR" "$SYM_DOT" "$(t subtitle)" "$SYM_DOT" "$PLUGIN_VERSION" "$C_RESET"
}

STEP_TOTAL=6
ui_step() { # ui_step NUMBER TITLE
    local label=" $1/$STEP_TOTAL  $2 "
    local fill=$((TERM_WIDTH - ${#label} - 6))
    ((fill < 3)) && fill=3
    printf '\n %s%s%s%s%s%s%s\n\n' "$C_ACCENT" "$(ui_repeat "$RULE" 2)" "$C_RESET$C_BOLD" "$label" "$C_RESET$C_ACCENT" "$(ui_repeat "$RULE" "$fill")" "$C_RESET"
}

ui_title() { # closing title, in green
    local label=" $1 "
    local fill=$((TERM_WIDTH - ${#label} - 6))
    ((fill < 3)) && fill=3
    printf '\n %s%s%s%s%s%s%s\n\n' "$C_GREEN" "$(ui_repeat "$RULE" 2)" "$C_RESET$C_BOLD$C_GREEN" "$label" "$C_RESET$C_GREEN" "$(ui_repeat "$RULE" "$fill")" "$C_RESET"
}

ui_ok() { printf '  %s%s%s %s\n' "$C_GREEN" "$SYM_OK" "$C_RESET" "$1"; }
ui_error() { printf '  %s%s%s %s\n' "$C_RED" "$SYM_ERR" "$C_RESET" "$1"; }
ui_warn() { printf '  %s%s%s %s\n' "$C_YELLOW" "$SYM_WARN" "$C_RESET" "$1"; }
ui_info() { printf '  %s%s%s %s\n' "$C_BLUE" "$SYM_INFO" "$C_RESET" "$1"; }
ui_note() { printf '    %s%s%s\n' "$C_DIM" "$1" "$C_RESET"; }

ui_wrap() { # ui_wrap WIDTH TEXT → wrapped lines on stdout
    local width=$1 line="" word
    local -a words
    read -r -a words <<<"$2"
    for word in "${words[@]}"; do
        if [[ -z $line ]]; then
            line=$word
        elif ((${#line} + 1 + ${#word} > width)); then
            printf '%s\n' "$line"
            line=$word
        else
            line+=" $word"
        fi
    done
    [[ -n $line ]] && printf '%s\n' "$line"
}

ui_box() { # ui_box info|ok|warn|error TITLE PARAGRAPH...
    local kind=$1 title=$2 color=$C_BLUE paragraph line
    shift 2
    case $kind in ok) color=$C_GREEN ;; warn) color=$C_YELLOW ;; error) color=$C_RED ;; esac
    local inner=$((TERM_WIDTH - 8))
    local head=""
    [[ -n $title ]] && head=" $title "
    local top_fill=$((inner + 2 - ${#head} - 1))
    ((top_fill < 1)) && top_fill=1
    printf '  %s%s%s%s%s%s%s\n' "$color" "${BOX[0]}${BOX[1]}" "$C_BOLD" "$head" "$C_RESET$color" "$(ui_repeat "${BOX[1]}" "$top_fill")${BOX[2]}" "$C_RESET"
    for paragraph in "$@"; do
        while IFS= read -r line; do
            printf '  %s%s%s %s%*s %s%s%s\n' "$color" "${BOX[3]}" "$C_RESET" "$line" $((inner - ${#line})) "" "$color" "${BOX[3]}" "$C_RESET"
        done < <(ui_wrap "$inner" "$paragraph")
    done
    printf '  %s%s%s%s%s\n' "$color" "${BOX[4]}" "$(ui_repeat "${BOX[1]}" $((inner + 2)))" "${BOX[5]}" "$C_RESET"
}

ui_kv() { # ui_kv LABEL VALUE [ok|warn|error]
    local label=$1 value=$2 mark=""
    case ${3:-} in ok) mark="$C_GREEN$SYM_OK$C_RESET " ;; warn) mark="$C_YELLOW$SYM_WARN$C_RESET " ;; error) mark="$C_RED$SYM_ERR$C_RESET " ;; esac
    local dots=$((14 - ${#label}))
    ((dots < 1)) && dots=1
    printf '   %s%s %s%s%s %s%s\n' "$C_BOLD" "$label" "$C_DIM" "$(ui_repeat "." "$dots")" "$C_RESET" "$mark" "$value"
}

ui_readkey() { # sets KEY (UP DOWN LEFT RIGHT PGUP PGDN HOME END ENTER ESC BACKSPACE TAB SPACE CHAR EOF) and KEY_CHAR
    local k c1 c2 c3
    KEY_CHAR=""
    if ! IFS= read -rsn1 k; then KEY=EOF; return; fi
    case $k in
        '') KEY=ENTER ;;
        $'\e')
            if ! IFS= read -rsn1 -t 0.03 c1; then KEY=ESC; return; fi
            IFS= read -rsn1 -t 0.03 c2 || c2=""
            case "$c1$c2" in
                '[A' | 'OA') KEY=UP ;;
                '[B' | 'OB') KEY=DOWN ;;
                '[C' | 'OC') KEY=RIGHT ;;
                '[D' | 'OD') KEY=LEFT ;;
                '[H' | 'OH') KEY=HOME ;;
                '[F' | 'OF') KEY=END ;;
                '[5' | '[6' | '[1' | '[4' | '[3')
                    IFS= read -rsn1 -t 0.03 c3 || c3=""
                    case $c2 in 5) KEY=PGUP ;; 6) KEY=PGDN ;; 1) KEY=HOME ;; 4) KEY=END ;; *) KEY=OTHER ;; esac
                    ;;
                *) KEY=ESC ;;
            esac
            ;;
        $'\x7f' | $'\b') KEY=BACKSPACE ;;
        $'\t') KEY=TAB ;;
        ' ') KEY=SPACE KEY_CHAR=" " ;;
        $'\x15') KEY=CTRLU ;;
        *) KEY=CHAR KEY_CHAR=$k ;;
    esac
}

ui_truncate() { # ui_truncate TEXT WIDTH
    local text=$1 width=$2
    ((width < 4)) && { printf ''; return; }
    if ((${#text} > width)); then printf '%s…' "${text:0:width-1}"; else printf '%s' "$text"; fi
}

ui_question() { printf '\n  %s%s%s %s%s%s\n' "$C_ACCENT$C_BOLD" "$SYM_ASK" "$C_RESET" "$C_BOLD" "$1" "$C_RESET"; }

ui_answer() { # replaces the lines of a finished question by the answer
    local lines=$1 answer=$2 i
    if ((IS_TTY)); then
        printf '\e[%dA' "$lines"
        for ((i = 0; i < lines; i++)); do printf '\r\e[K\n'; done
        printf '\e[%dA' "$lines"
    fi
    printf '    %s%s%s %s\n' "$C_GREEN" "$SYM_POINTER" "$C_RESET" "$answer"
}

ui_menu() { # ui_menu QUESTION DEFAULT_INDEX "label<TAB>description"... → REPLY_INDEX
    local question=$1 selected=$2
    shift 2
    local -a items=("$@")
    local count=${#items[@]} i label desc room
    ui_question "$question"
    if ((!INTERACTIVE)); then
        REPLY_INDEX=$selected
        printf '    %s%s%s %s\n' "$C_GREEN" "$SYM_POINTER" "$C_RESET" "${items[selected]%%$'\t'*}"
        return 0
    fi
    printf '%s' "$CUR_HIDE"
    local drawn=0
    while :; do
        ((drawn)) && printf '\e[%dA' $((count + 1))
        for ((i = 0; i < count; i++)); do
            label=${items[i]%%$'\t'*}
            desc=""
            [[ ${items[i]} == *$'\t'* ]] && desc=${items[i]#*$'\t'}
            room=$((TERM_WIDTH - ${#label} - 12))
            printf '\r\e[K'
            if ((i == selected)); then
                printf '   %s%s %s%s%s' "$C_ACCENT" "$SYM_POINTER" "$C_BOLD" "$label" "$C_RESET"
            else
                printf '     %s' "$label"
            fi
            [[ -n $desc && $room -gt 8 ]] && printf '  %s%s%s' "$C_DIM" "$(ui_truncate "$desc" "$room")" "$C_RESET"
            printf '\n'
        done
        printf '\r\e[K     %s%s%s\n' "$C_DIM$C_ITALIC" "$(t menu_hint)" "$C_RESET"
        drawn=1
        ui_readkey
        case $KEY in
            UP) selected=$(((selected - 1 + count) % count)) ;;
            DOWN | TAB) selected=$(((selected + 1) % count)) ;;
            HOME | PGUP) selected=0 ;;
            END | PGDN) selected=$((count - 1)) ;;
            ENTER | EOF) break ;;
            CHAR)
                case $KEY_CHAR in
                    k) selected=$(((selected - 1 + count) % count)) ;;
                    j) selected=$(((selected + 1) % count)) ;;
                    [1-9]) ((KEY_CHAR <= count)) && selected=$((KEY_CHAR - 1)) ;;
                esac
                ;;
        esac
    done
    printf '%s' "$CUR_SHOW"
    ui_answer $((count + 1)) "${items[selected]%%$'\t'*}"
    REPLY_INDEX=$selected
}

ui_filter() { # ui_filter QUESTION ITEM... → REPLY_TEXT (empty: type a name instead)
    local question=$1
    shift
    local -a all=("$@") view
    local filter="" selected=0 top=0 rows=10 i n item
    ((TERM_LINES - 10 < rows)) && rows=$((TERM_LINES - 10))
    ((rows < 4)) && rows=4
    ui_question "$question"
    if ((!INTERACTIVE)); then
        REPLY_TEXT=""
        return 0
    fi
    printf '%s' "$CUR_HIDE"
    local drawn=0 height=$((rows + 2))
    while :; do
        view=()
        for item in "${all[@]}"; do
            [[ -z $filter || ${item,,} == *"${filter,,}"* ]] && view+=("$item")
        done
        n=${#view[@]}
        ((selected >= n)) && selected=$((n > 0 ? n - 1 : 0))
        ((selected < top)) && top=$selected
        ((selected >= top + rows)) && top=$((selected - rows + 1))
        ((drawn)) && printf '\e[%dA' "$height"
        printf '\r\e[K    %s%s:%s %s%s%s  %s%s%s\n' "$C_DIM" "$(t filter_label)" "$C_RESET" "$C_BOLD" "$filter$CARET" "$C_RESET" "$C_DIM" "$(t n_of_m "$n" "${#all[@]}")" "$C_RESET"
        for ((i = top; i < top + rows; i++)); do
            printf '\r\e[K'
            if ((i < n)); then
                if ((i == selected)); then
                    printf '   %s%s %s%s%s\n' "$C_ACCENT" "$SYM_POINTER" "$C_BOLD" "$(ui_truncate "${view[i]}" $((TERM_WIDTH - 8)))" "$C_RESET"
                else
                    printf '     %s\n' "$(ui_truncate "${view[i]}" $((TERM_WIDTH - 8)))"
                fi
            else
                printf '\n'
            fi
        done
        printf '\r\e[K     %s%s%s\n' "$C_DIM$C_ITALIC" "$(t filter_hint)" "$C_RESET"
        drawn=1
        ui_readkey
        case $KEY in
            UP) ((selected > 0)) && selected=$((selected - 1)) ;;
            DOWN | TAB) ((selected < n - 1)) && selected=$((selected + 1)) ;;
            PGUP) selected=$((selected - rows)); ((selected < 0)) && selected=0 ;;
            PGDN) selected=$((selected + rows)); ((selected > n - 1)) && selected=$((n > 0 ? n - 1 : 0)) ;;
            HOME) selected=0 ;;
            END) selected=$((n > 0 ? n - 1 : 0)) ;;
            BACKSPACE) filter=${filter%?}; selected=0; top=0 ;;
            CTRLU) filter=""; selected=0; top=0 ;;
            CHAR | SPACE) filter+=$KEY_CHAR; selected=0; top=0 ;;
            ENTER) ((n > 0)) && break ;;
            ESC | EOF) n=0; break ;;
        esac
    done
    printf '%s' "$CUR_SHOW"
    if ((n > 0)); then
        REPLY_TEXT=${view[selected]}
        ui_answer $((height)) "$REPLY_TEXT"
    else
        REPLY_TEXT=""
        ui_answer $((height)) "…"
    fi
}

ui_confirm() { # ui_confirm QUESTION [y|n] → status 0 = yes
    local question=$1 yes=1
    [[ ${2:-y} == n ]] && yes=0
    if ((!INTERACTIVE)); then
        printf '\n  %s%s%s %s %s%s%s\n' "$C_ACCENT$C_BOLD" "$SYM_ASK" "$C_RESET" "$question" "$C_GREEN" "$( ((yes)) && t yes || t no)" "$C_RESET"
        return $((!yes))
    fi
    printf '\n%s' "$CUR_HIDE"
    while :; do
        printf '\r\e[K  %s%s%s %s  ' "$C_ACCENT$C_BOLD" "$SYM_ASK" "$C_RESET" "$question"
        if ((yes)); then
            printf '%s %s %s %s %s %s' "$C_REV$C_GREEN$C_BOLD" "$(t yes)" "$C_RESET" "$C_DIM" "$(t no)" "$C_RESET"
        else
            printf '%s %s %s %s %s %s' "$C_DIM" "$(t yes)" "$C_RESET" "$C_REV$C_RED$C_BOLD" "$(t no)" "$C_RESET"
        fi
        ui_readkey
        case $KEY in
            LEFT | RIGHT | TAB | SPACE) yes=$((!yes)) ;;
            ENTER | EOF) break ;;
            CHAR)
                case $KEY_CHAR in
                    y | Y | s | S) yes=1; break ;;
                    n | N) yes=0; break ;;
                    h | l) yes=$((!yes)) ;;
                esac
                ;;
        esac
    done
    printf '%s\r\e[K  %s%s%s %s %s%s%s\n' "$CUR_SHOW" "$C_ACCENT$C_BOLD" "$SYM_ASK" "$C_RESET" "$question" "$C_GREEN" "$( ((yes)) && t yes || t no)" "$C_RESET"
    return $((!yes))
}

ui_input() { # ui_input QUESTION [DEFAULT] → REPLY_TEXT
    local question=$1 default=${2:-}
    ui_question "$question"
    if ((!INTERACTIVE)); then
        REPLY_TEXT=$default
        printf '    %s%s%s %s\n' "$C_GREEN" "$SYM_POINTER" "$C_RESET" "${default:-—}"
        return 0
    fi
    local value=""
    IFS= read -e -r -p "    $SYM_POINTER " -i "$default" value
    REPLY_TEXT=$value
}

ui_secret() { # ui_secret QUESTION → REPLY_TEXT, typed characters shown as bullets
    local question=$1 value="" char
    ui_question "$question"
    if ((!INTERACTIVE)); then
        REPLY_TEXT=""
        return 0
    fi
    printf '    %s%s%s ' "$C_ACCENT" "$SYM_POINTER" "$C_RESET"
    while IFS= read -rsn1 char; do
        case $char in
            '') break ;;
            $'\x7f' | $'\b')
                if [[ -n $value ]]; then
                    value=${value%?}
                    printf '\b \b'
                fi
                ;;
            $'\e') read -rsn2 -t 0.03 _ || true ;;
            $'\x15') printf '%s' "$(ui_repeat $'\b \b' ${#value})"; value="" ;;
            *)
                value+=$char
                ((${#value} <= TERM_WIDTH - 10)) && printf '%s' "$SYM_BULLET"
                ;;
        esac
    done
    printf '\n'
    REPLY_TEXT=$value
}

ui_run() { # ui_run MESSAGE COMMAND... → status of the command; output in $RUN_OUT and in the log
    local message=$1
    shift
    local out
    out=$(mktemp "${TMPDIR:-/tmp}/ticketassistant.XXXXXX") || out=/dev/null
    TMP_FILES+=("$out")
    printf '\n### %s\n### %s\n' "$message" "$*" >>"$LOG_FILE"
    "$@" >"$out" 2>&1 &
    local pid=$! frame=0
    if ((IS_TTY)); then
        printf '%s' "$CUR_HIDE"
        while kill -0 "$pid" 2>/dev/null; do
            printf '\r  %s%s%s %s' "$C_ACCENT" "${SPINNER[frame % ${#SPINNER[@]}]}" "$C_RESET" "$message"
            frame=$((frame + 1))
            sleep 0.08
        done
        printf '\r\e[K%s' "$CUR_SHOW"
    fi
    wait "$pid"
    local status=$?
    cat "$out" >>"$LOG_FILE" 2>/dev/null
    RUN_OUT=$out
    if ((status == 0)); then ui_ok "$message"; else ui_error "$message"; fi
    return "$status"
}

ui_output_box() { # last lines of the output of the last command, in a red box
    local -a lines=()
    local line
    while IFS= read -r line; do
        line=${line//$'\r'/}
        line=$(printf '%s' "$line" | sed 's/\x1b\[[0-9;]*m//g')
        [[ -n ${line// /} ]] && lines+=("$line")
    done < <(tail -n 12 "$RUN_OUT" 2>/dev/null)
    ((${#lines[@]})) || lines=("(no output)")
    ui_box error "$1" "${lines[@]}"
    ui_note "$(t log_at "$LOG_FILE")"
}

# -----------------------------------------------------------------------------
# System helpers
# -----------------------------------------------------------------------------
last_output_line() { sed 's/\x1b\[[0-9;]*m//g' "$RUN_OUT" 2>/dev/null | grep -v '^[[:space:]]*$' | tail -n1; }

version_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]; }
version_gt() { [[ $1 != "$2" ]] && version_ge "$1" "$2"; }

GLPI="" GLPI_VERSION="" PHP="" PHP_VERSION="" WEB_USER="" CONSOLE_EXTRA=()

run_as_web() { # runs a command in the GLPI folder as the web server user
    if [[ $EUID -eq 0 && -n $WEB_USER && $WEB_USER != root ]]; then
        if command -v runuser >/dev/null 2>&1; then
            (cd "$GLPI" && runuser -u "$WEB_USER" -- "$@")
        elif command -v sudo >/dev/null 2>&1; then
            (cd "$GLPI" && sudo -u "$WEB_USER" -- "$@")
        else
            (cd "$GLPI" && su -s /bin/sh "$WEB_USER" -c "$(printf '%q ' "$@")")
        fi
    else
        (cd "$GLPI" && "$@")
    fi
}

glpi_console() {
    # Messages of GLPI and of the plugin in the language of the installer
    local lang=()
    [[ $UI_LANG == pt ]] && lang=(--lang=pt_BR)
    run_as_web "$PHP" bin/console --no-interaction --no-ansi "${CONSOLE_EXTRA[@]}" "${lang[@]}" "$@"
}

SECRET_INPUT=""
glpi_console_secret() { printf '%s\n' "$SECRET_INPUT" | glpi_console "$@"; }

php_version_of() { "$1" -r 'echo PHP_VERSION;' 2>/dev/null; }

glpi_version_of() {
    local dir=$1 version=""
    [[ -f $dir/inc/define.php ]] && version=$(sed -n "s/.*define *( *['\"]GLPI_VERSION['\"] *, *['\"]\([0-9][^'\"]*\)['\"].*/\1/p" "$dir/inc/define.php" | head -n1)
    if [[ -z $version && -d $dir/version ]]; then
        version=$(ls -1 "$dir/version" 2>/dev/null | grep -E '^[0-9]' | sort -V | tail -n1)
    fi
    printf '%s' "$version"
}

# Markers present in GLPI 10, 11 and 12 (front/central.php and inc/define.php are gone in 11)
is_glpi() { [[ -f $1/bin/console && -f $1/src/Ticket.php ]]; }

installed_plugin_version() { # version of the plugin files in the selected GLPI ('' = none)
    local dest=$GLPI/plugins/$PLUGIN_KEY
    [[ -f $dest/setup.php ]] && php_define PLUGIN_TICKETASSISTANT_VERSION "$dest/setup.php"
}

# Status of the plugin as GLPI sees it: fills STATUS[...] from status --porcelain
declare -A STATUS=()
read_status() {
    STATUS=()
    local line
    local output
    output=$(glpi_console plugins:ticketassistant:status --porcelain 2>/dev/null) || return 1
    while IFS= read -r line; do
        [[ $line == *=* ]] && STATUS[${line%%=*}]=${line#*=}
    done <<<"$output"
    [[ -n ${STATUS[version]:-} ]]
}

# -----------------------------------------------------------------------------
# Step 1: system
# -----------------------------------------------------------------------------
step_checks() {
    ui_step 1 "$(t step_checks)"
    if [[ ! -f $REPO_DIR/setup.php || -z $PLUGIN_VERSION ]]; then
        ui_error "$(t not_repo)"
        exit 1
    fi
    ui_ok "$(t plugin_repo "$PLUGIN_VERSION" "$REPO_DIR")"

    if [[ $EUID -ne 0 ]]; then
        ui_box warn "" "$(t need_root)"
        if ((INTERACTIVE)); then ui_confirm "$(t continue_anyway)" n || exit 1; fi
    fi

    find_php || exit 1

    if command -v rsync >/dev/null 2>&1; then ui_ok "$(t copy_tool rsync)"; else ui_ok "$(t copy_tool tar)"; fi
}

find_php() {
    local -a candidates=() versions=()
    local bin real seen version name
    for bin in "$OPT_PHP" "$(command -v php 2>/dev/null)" /usr/bin/php* /usr/local/bin/php* /opt/remi/php*/root/usr/bin/php /usr/local/php*/bin/php /opt/php*/bin/php; do
        [[ -n $bin && -x $bin && ! -d $bin ]] || continue
        name=${bin##*/}
        # php, php8.3… but not php-cgi, php-fpm, phpize (a path given with --php is trusted)
        [[ $bin == "$OPT_PHP" || $name =~ ^php[0-9.]*$ ]] || continue
        real=$(readlink -f "$bin" 2>/dev/null || printf '%s' "$bin")
        for seen in "${candidates[@]}"; do [[ $(readlink -f "$seen" 2>/dev/null) == "$real" ]] && continue 2; done
        version=$(php_version_of "$bin") || continue
        [[ -n $version ]] || continue
        candidates+=("$bin")
        versions+=("$version")
    done
    if ((${#candidates[@]} == 0)); then
        ui_error "$(t php_missing)"
        return 1
    fi
    PHP_CANDIDATES=("${candidates[@]}")
    PHP_VERSIONS=("${versions[@]}")
    PHP=${candidates[0]}
    PHP_VERSION=${versions[0]}
    ui_ok "$(t php_found "$PHP_VERSION" "$C_DIM$PHP$C_RESET")"

    local -a modules missing=()
    mapfile -t modules < <("$PHP" -m 2>/dev/null | tr '[:upper:]' '[:lower:]')
    local ext
    for ext in curl mbstring json gd zip zlib; do
        printf '%s\n' "${modules[@]}" | grep -qx "$ext" || missing+=("$ext")
    done
    if ((${#missing[@]})); then
        ui_warn "$(t ext_missing "${missing[*]}")"
        ui_note "$(t ext_hint)"
    else
        ui_ok "$(t ext_ok "curl mbstring json gd zip zlib")"
    fi
    return 0
}

# PHP able to run the selected GLPI (GLPI 11 needs 8.2, GLPI 12 needs 8.3)
choose_php_for_glpi() {
    local required=7.4 i
    version_ge "$GLPI_VERSION" 11 && required=8.2
    version_ge "$GLPI_VERSION" 12 && required=8.3
    version_ge "$PHP_VERSION" "$required" && return 0

    local -a items=() bins=()
    for i in "${!PHP_CANDIDATES[@]}"; do
        if version_ge "${PHP_VERSIONS[i]}" "$required"; then
            items+=("PHP ${PHP_VERSIONS[i]}"$'\t'"${PHP_CANDIDATES[i]}")
            bins+=("$i")
        fi
    done
    ui_warn "$(t php_too_old "$GLPI_VERSION" "$required" "$PHP" "$PHP_VERSION")"
    ((${#bins[@]})) || return 0
    ui_menu "$(t choose_php)" 0 "${items[@]}"
    PHP=${PHP_CANDIDATES[${bins[REPLY_INDEX]}]}
    PHP_VERSION=${PHP_VERSIONS[${bins[REPLY_INDEX]}]}
}

# -----------------------------------------------------------------------------
# Step 2: GLPI
# -----------------------------------------------------------------------------
web_roots() { # document roots declared in Apache / nginx configurations
    grep -rhoE '^[[:space:]]*(DocumentRoot|root)[[:space:]]+"?[^";[:space:]]+' \
        /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/httpd/conf /etc/httpd/conf.d \
        /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/nginx/nginx.conf 2>/dev/null |
        awk '{print $2}' | tr -d '"' | sort -u
}

detect_glpi() {
    local -a dirs=() found=()
    local dir real seen
    dirs+=(/var/www/glpi /var/www/html/glpi /var/www/html /usr/share/glpi /srv/www/htdocs/glpi /srv/www/glpi
        /srv/http/glpi /opt/glpi /var/www/*/glpi /var/www/*/htdocs/glpi /var/www/*/public_html/glpi
        /var/www/vhosts/*/httpdocs/glpi /home/*/public_html/glpi /home/*/www/glpi)
    while IFS= read -r dir; do dirs+=("$dir" "${dir%/public}" "${dir%/*}"); done < <(web_roots)
    local finder=(find)
    command -v timeout >/dev/null 2>&1 && finder=(timeout 20 find)
    while IFS= read -r dir; do dirs+=("${dir%/bin/console}"); done < <("${finder[@]}" /var/www /srv /opt /usr/share /home -maxdepth 4 -type f -path '*/bin/console' 2>/dev/null)
    for dir in "${dirs[@]}"; do
        [[ -d $dir ]] || continue
        real=$(readlink -f "$dir" 2>/dev/null) || continue
        is_glpi "$real" || continue
        for seen in "${found[@]}"; do [[ $seen == "$real" ]] && continue 2; done
        found+=("$real")
    done
    GLPI_CANDIDATES=("${found[@]}")
}

describe_glpi() { # one line: plugin state in this GLPI
    local dir=$1 dest=$1/plugins/$PLUGIN_KEY
    if [[ -L $dest ]]; then
        t plugin_link "$(readlink -f "$dest")"
    elif [[ -f $dest/setup.php ]]; then
        t plugin_files "$(php_define PLUGIN_TICKETASSISTANT_VERSION "$dest/setup.php")"
    else
        t plugin_none
    fi
}

step_glpi() {
    ui_step 2 "$(t step_glpi)"
    local dir
    if [[ -n $OPT_GLPI ]]; then
        dir=$(readlink -f "$OPT_GLPI" 2>/dev/null || printf '%s' "$OPT_GLPI")
    else
        # The search runs under the spinner (a subshell): candidates come back through its output
        ui_run "$(t searching)" detect_glpi_print
        mapfile -t GLPI_CANDIDATES < <(grep -E '^/' "$RUN_OUT")
        if ((${#GLPI_CANDIDATES[@]} == 0)); then
            ui_warn "$(t none_found)"
            if ((!INTERACTIVE)); then ui_error "$(t unattended_glpi 0)"; exit 1; fi
            dir=""
        elif ((!INTERACTIVE)); then
            if ((${#GLPI_CANDIDATES[@]} > 1)); then ui_error "$(t unattended_glpi "${#GLPI_CANDIDATES[@]}")"; exit 1; fi
            dir=${GLPI_CANDIDATES[0]}
        else
            ui_ok "$(t found_n "${#GLPI_CANDIDATES[@]}")"
            local -a items=()
            for dir in "${GLPI_CANDIDATES[@]}"; do
                items+=("GLPI $(glpi_version_of "$dir")  $dir"$'\t'"$(describe_glpi "$dir")")
            done
            items+=("$(t other_path)")
            ui_menu "$(t choose_glpi)" 0 "${items[@]}"
            if ((REPLY_INDEX < ${#GLPI_CANDIDATES[@]})); then dir=${GLPI_CANDIDATES[REPLY_INDEX]}; else dir=""; fi
        fi
    fi
    while :; do
        if [[ -z $dir ]]; then
            ui_input "$(t type_path)" "/var/www/glpi"
            dir=$(readlink -f "$REPLY_TEXT" 2>/dev/null || printf '%s' "$REPLY_TEXT")
        fi
        if is_glpi "$dir"; then break; fi
        ui_error "$(t not_glpi "$dir")"
        ((INTERACTIVE)) || exit 1
        dir=""
    done
    GLPI=$dir
    GLPI_VERSION=$(glpi_version_of "$GLPI")
    [[ -z $GLPI_VERSION ]] && GLPI_VERSION=$("$PHP" "$GLPI/bin/console" --version --no-ansi 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1)
    ui_ok "$(t glpi_selected "${GLPI_VERSION:-?}" "$C_DIM$GLPI$C_RESET")"

    if [[ -n $GLPI_VERSION ]]; then
        if ! version_ge "$GLPI_VERSION" "$MIN_GLPI"; then
            ui_error "$(t glpi_too_old "$GLPI_VERSION" "$MIN_GLPI")"
            exit 1
        fi
        version_ge "$GLPI_VERSION" "$MAX_GLPI" && ui_warn "$(t glpi_untested "$GLPI_VERSION" "${MAX_GLPI%.99}")"
        choose_php_for_glpi
    fi

    detect_web_user
    # The console refuses root on recent GLPI versions unless told otherwise
    if [[ $EUID -eq 0 && (-z $WEB_USER || $WEB_USER == root) ]] && "$PHP" "$GLPI/bin/console" --help 2>&1 | grep -q -- '--allow-superuser'; then
        CONSOLE_EXTRA=(--allow-superuser)
    fi
}

detect_glpi_print() {
    detect_glpi 2>/dev/null
    ((${#GLPI_CANDIDATES[@]})) && printf '%s\n' "${GLPI_CANDIDATES[@]}"
    return 0
}

detect_web_user() {
    local candidate="" dir var_dir
    if [[ -n $OPT_WEB_USER ]]; then
        WEB_USER=$OPT_WEB_USER
    else
        # GLPI_VAR_DIR can be moved by the packaging (e.g. /var/lib/glpi)
        var_dir=$(sed -n "s/.*define *( *['\"]GLPI_VAR_DIR['\"] *, *['\"]\([^'\"]*\)['\"].*/\1/p" "$GLPI/inc/downstream.php" "$GLPI/config/local_define.php" 2>/dev/null | head -n1)
        for dir in "$var_dir" "$GLPI/files" "$GLPI/config" "$GLPI/marketplace" "$GLPI/plugins"; do
            [[ -n $dir && -e $dir ]] || continue
            candidate=$(stat -c %U "$dir" 2>/dev/null)
            [[ -n $candidate && $candidate != root && $candidate != UNKNOWN ]] && break
            candidate=""
        done
        if [[ -z $candidate ]]; then
            candidate=$(ps -eo user=,comm= 2>/dev/null | awk '$1 != "root" && $2 ~ /^(apache2|httpd|nginx|php-fpm.*|lighttpd|caddy|frankenphp)$/ {print $1; exit}')
        fi
        if [[ -z $candidate ]]; then
            local user
            for user in www-data apache nginx http wwwrun; do id "$user" >/dev/null 2>&1 && { candidate=$user; break; }; done
        fi
        WEB_USER=$candidate
        if ((INTERACTIVE)) && [[ $EUID -eq 0 ]]; then
            ui_input "$(t web_user_ask)" "${WEB_USER:-www-data}"
            WEB_USER=$REPLY_TEXT
        fi
    fi
    if [[ -n $WEB_USER ]] && ! id "$WEB_USER" >/dev/null 2>&1; then
        ui_warn "$WEB_USER?"
        WEB_USER=""
    fi
    [[ -n $WEB_USER ]] && ui_ok "$(t web_user "$WEB_USER")"
}

# -----------------------------------------------------------------------------
# Step 3: plugin files and installation in GLPI
# -----------------------------------------------------------------------------
INSTALL_MODE=copy BACKUP_FILE="" GLPI_LOGIN=${OPT_LOGIN:-glpi}

# Saves the plugin folder before it is replaced: sets BACKUP_FILE ('' when
# there is nothing to save; a link to the repository is simply removed)
backup_plugin() {
    local dest=$GLPI/plugins/$PLUGIN_KEY previous base
    BACKUP_FILE=""
    if [[ -L $dest ]]; then
        rm -f "$dest"
        return
    fi
    [[ -d $dest ]] || return 0
    previous=$(php_define PLUGIN_TICKETASSISTANT_VERSION "$dest/setup.php")
    base=/var/backups/glpi-ticketassistant
    { mkdir -p "$base" 2>/dev/null && [[ -w $base ]]; } || base="$HOME/.glpi-ticketassistant-backups"
    mkdir -p "$base" || return 1
    BACKUP_FILE="$base/$PLUGIN_KEY-${previous:-unknown}-$(date +%Y%m%d-%H%M%S).tar.gz"
    ui_run "$(t backing_up)" tar -C "$GLPI/plugins" -czf "$BACKUP_FILE" "$PLUGIN_KEY" || return 1
    ui_note "$(t backup_done "$BACKUP_FILE")"
}

copy_plugin() {
    local dest=$GLPI/plugins/$PLUGIN_KEY pattern
    mkdir -p "$GLPI/plugins" || return 1
    if command -v rsync >/dev/null 2>&1; then
        local -a excludes=()
        for pattern in "${COPY_EXCLUDES[@]}"; do excludes+=("--exclude=/$pattern"); done
        rsync -a --delete "${excludes[@]}" --exclude='*.log' "$REPO_DIR/" "$dest/" || return 1
    else
        local -a excludes=()
        for pattern in "${COPY_EXCLUDES[@]}"; do excludes+=("--exclude=./$pattern"); done
        rm -rf "$dest.new" && mkdir -p "$dest.new" || return 1
        tar -C "$REPO_DIR" "${excludes[@]}" --exclude='*.log' -cf - . | tar -C "$dest.new" -xf - || return 1
        rm -rf "$dest" && mv "$dest.new" "$dest" || return 1
    fi
}

fix_permissions() {
    local dest=$GLPI/plugins/$PLUGIN_KEY
    find "$dest" -type d -exec chmod 755 {} + && find "$dest" -type f -exec chmod 644 {} + || return 1
    chmod 755 "$dest" 2>/dev/null
    if [[ $EUID -eq 0 && -n $WEB_USER ]]; then chown -R "$WEB_USER": "$dest" || return 1; fi
}

place_plugin() {
    local dest=$GLPI/plugins/$PLUGIN_KEY
    # Archive extracted directly in plugins/ and installer run from there
    if [[ -d $dest && ! -L $dest && $(readlink -f "$dest") == "$REPO_DIR" ]]; then
        INSTALL_MODE=copy
        ui_ok "$(t in_place "$dest")"
        ui_run "$(t perms "${WEB_USER:-$(id -un)}")" fix_permissions || true
        return 0
    fi
    if [[ -n $OPT_MODE ]]; then
        INSTALL_MODE=$OPT_MODE
    elif ((INTERACTIVE)); then
        local default=0
        [[ -L $dest ]] && default=1
        ui_menu "$(t mode_ask)" "$default" "$(t mode_copy)" "$(t mode_link)"
        ((REPLY_INDEX == 1)) && INSTALL_MODE=link || INSTALL_MODE=copy
    fi

    if [[ $INSTALL_MODE == link ]] && [[ $EUID -eq 0 && -n $WEB_USER ]] && ! run_as_web test -r "$REPO_DIR/setup.php"; then
        ui_box warn "" "$(t link_unreadable "$WEB_USER" "$REPO_DIR")"
        INSTALL_MODE=copy
    fi

    if [[ -e $dest || -L $dest ]]; then
        if [[ ! (-L $dest && $INSTALL_MODE == link && $(readlink -f "$dest") == "$REPO_DIR") ]]; then
            backup_plugin || return 1
        fi
    fi

    if [[ $INSTALL_MODE == link ]]; then
        ui_run "$(t linking "$dest")" ln -sfn "$REPO_DIR" "$dest" || return 1
    else
        ui_run "$(t copying "$dest")" copy_plugin || { ui_output_box "$(t copying "$dest")"; return 1; }
        ui_run "$(t perms "${WEB_USER:-$(id -un)}")" fix_permissions || true
    fi
    if command -v getenforce >/dev/null 2>&1 && [[ $(getenforce 2>/dev/null) == Enforcing ]] && command -v restorecon >/dev/null 2>&1; then
        ui_run "$(t selinux)" restorecon -R "$dest" || true
    fi
}

console_install() {
    while :; do
        if ((INTERACTIVE)) && [[ -z $OPT_LOGIN ]]; then
            ui_input "$(t login_ask)" "$GLPI_LOGIN"
            GLPI_LOGIN=${REPLY_TEXT:-glpi}
        fi
        if ui_run "$(t installing)" glpi_console plugin:install --username="$GLPI_LOGIN" --force "$PLUGIN_KEY"; then
            break
        fi
        ui_output_box "$(t console_error)"
        if ((INTERACTIVE)) && ui_confirm "$(t retry_login)" y; then
            OPT_LOGIN=""
            continue
        fi
        return 1
    done
    ui_run "$(t activating)" glpi_console plugin:activate "$PLUGIN_KEY" || { ui_output_box "$(t console_error)"; return 1; }
    if ! ui_run "$(t verifying)" read_status_quiet; then
        ui_output_box "$(t console_error)"
        return 1
    fi
    read_status
    return 0
}

read_status_quiet() { glpi_console plugins:ticketassistant:status --porcelain; }

ensure_plugin_active() {
    if read_status; then return 0; fi
    ui_warn "$(t plugin_inactive)"
    if ((INTERACTIVE)) && ! ui_confirm "$(t install_now)" y; then return 1; fi
    console_install
}

# -----------------------------------------------------------------------------
# Step 4: AI provider
# -----------------------------------------------------------------------------
AI_SUMMARY="" TEST_RESULT=""

provider_label() {
    local key
    case $1 in openai | azure | anthropic | gemini | mistral | openrouter | ollama | compatible) key=p_$1 ;; *) printf '%s' "$1"; return ;; esac
    local text
    text=$(t "$key")
    printf '%s' "${text%%$'\t'*}"
}

configure_ai() {
    read_status || return 1
    local provider=${STATUS[provider]:-openai} model=${STATUS[model]:-}

    if [[ -n $OPT_PROVIDER || -n $OPT_MODEL ]]; then
        configure_unattended
        return
    fi
    if ((!INTERACTIVE)) || ((OPT_SKIP_AI)); then
        summarize_ai
        return 0
    fi

    ui_box info "" "$(t ai_intro)"
    if [[ ${STATUS[ready]:-0} == 1 ]]; then
        ui_info "$(t ai_current "$(provider_label "$provider")" "$model" "$(t state_ready)")"
        ui_confirm "$(t ai_change)" n || { summarize_ai; return 0; }
    elif ! ui_confirm "$(t ai_now)" y; then
        summarize_ai
        return 0
    fi

    while :; do
        # Provider
        local -a items=()
        local default=0 i
        for i in "${!PROVIDERS[@]}"; do
            items+=("$(t "p_${PROVIDERS[i]}")")
            [[ ${PROVIDERS[i]} == "$provider" ]] && default=$i
        done
        ui_menu "$(t choose_provider)" "$default" "${items[@]}"
        local new_provider=${PROVIDERS[REPLY_INDEX]}

        # Address and API version
        local -a args=(--provider="$new_provider")
        case $new_provider in
            azure)
                ui_input "$(t azure_url)" "$([[ $provider == azure ]] && printf '%s' "${STATUS[base_url]:-}")"
                args+=(--base-url="$REPLY_TEXT")
                ui_input "$(t azure_version)" "2024-10-21"
                args+=(--api-version="$REPLY_TEXT")
                ;;
            ollama | compatible)
                local current=${PROVIDER_URL[$new_provider]}
                [[ $provider == "$new_provider" && -n ${STATUS[base_url]:-} ]] && current=${STATUS[base_url]}
                ui_input "$(t base_url_ask)" "$current"
                args+=(--base-url="$REPLY_TEXT")
                ;;
            *)
                args+=(--base-url=)
                ;;
        esac

        # Key
        SECRET_INPUT=""
        local keep=0
        if [[ ${STATUS[api_key]:-0} == 1 && $new_provider == "$provider" ]] && ui_confirm "$(t key_keep)" y; then
            keep=1
        fi
        if ((!keep)); then
            if [[ $new_provider == ollama || $new_provider == compatible ]]; then
                ui_secret "$(t key_optional)"
                SECRET_INPUT=$REPLY_TEXT
            else
                [[ -n ${PROVIDER_KEYS_PAGE[$new_provider]:-} ]] && ui_note "$(t key_where "${PROVIDER_KEYS_PAGE[$new_provider]}")"
                while :; do
                    ui_secret "$(t key_ask)"
                    SECRET_INPUT=$REPLY_TEXT
                    [[ -n $SECRET_INPUT ]] && break
                    ui_warn "$(t key_empty)"
                done
            fi
        fi
        if [[ -n $SECRET_INPUT ]]; then
            ui_run "$(t saving)" glpi_console_secret plugins:ticketassistant:configure "${args[@]}" --api-key-stdin || { SECRET_INPUT=""; ui_output_box "$(t console_error)"; continue; }
        elif ((!keep)) && [[ $new_provider == ollama || $new_provider == compatible ]]; then
            ui_run "$(t saving)" glpi_console plugins:ticketassistant:configure "${args[@]}" --clear-api-key || { ui_output_box "$(t console_error)"; continue; }
        else
            ui_run "$(t saving)" glpi_console plugins:ticketassistant:configure "${args[@]}" || { ui_output_box "$(t console_error)"; continue; }
        fi
        SECRET_INPUT=""
        provider=$new_provider

        # Model
        choose_model "$provider" "$model"
        model=$REPLY_TEXT
        ui_run "$(t enabling_ai)" glpi_console plugins:ticketassistant:configure --model="$model" --enable || { ui_output_box "$(t console_error)"; continue; }

        # Test
        while :; do
            if ui_run "$(t testing)" glpi_console plugins:ticketassistant:configure --test; then
                TEST_RESULT=ok
                ui_note "$(last_output_line)"
                break
            fi
            TEST_RESULT=failed
            ui_output_box "$(t test_failed)"
            ui_menu "$(t test_menu)" 0 "$(t tf_retry)" "$(t tf_change)" "$(t tf_skip)"
            case $REPLY_INDEX in
                0) continue ;;
                1) continue 2 ;;
                *) break ;;
            esac
        done
        break
    done

    personalize
    summarize_ai
}

choose_model() { # choose_model PROVIDER CURRENT → REPLY_TEXT
    local provider=$1 current=$2
    local question
    if [[ $provider == azure ]]; then
        ui_input "$(t deployment_ask)" "$current"
        return
    fi
    local -a models=()
    if ui_run "$(t listing)" glpi_console plugins:ticketassistant:configure --list-models; then
        mapfile -t models < <(grep -E '^[A-Za-z0-9][A-Za-z0-9._:/@+-]*$' "$RUN_OUT" | sort -u)
    fi
    ui_note "$(t vision_tip)"
    if ((${#models[@]})); then
        ui_filter "$(t choose_model)" "${models[@]}"
        [[ -n $REPLY_TEXT ]] && return
    else
        ui_note "$(t models_none)"
    fi
    [[ -n ${PROVIDER_MODELS[$provider]:-} ]] && ui_note "$(t model_hint "${PROVIDER_MODELS[$provider]// /, }")"
    local default=$current
    [[ -z $default && -n ${PROVIDER_MODELS[$provider]:-} ]] && default=${PROVIDER_MODELS[$provider]%% *}
    while :; do
        ui_input "$(t model_ask)" "$default"
        [[ -n $REPLY_TEXT ]] && return
    done
}

personalize() {
    ((INTERACTIVE)) || return 0
    if ui_confirm "$(t personalize)" n; then
        local -a sets=()
        ui_input "$(t org_ask)" ""
        [[ -n $REPLY_TEXT ]] && sets+=(--set="organization_name=$REPLY_TEXT")
        local -a codes=(auto pt_BR en_GB es_ES fr_FR pt_PT de_DE it_IT nl_NL pl_PL)
        local -a labels=("$(t lang_auto)" "Português (Brasil)" "English" "Español" "Français" "Português (Portugal)" "Deutsch" "Italiano" "Nederlands" "Polski")
        local default=0
        [[ $UI_LANG == pt ]] && default=1
        ui_menu "$(t lang_ask)" "$default" "${labels[@]}"
        sets+=(--set="response_language=${codes[REPLY_INDEX]}")
        ui_run "$(t saving_prefs)" glpi_console plugins:ticketassistant:configure "${sets[@]}" || ui_output_box "$(t console_error)"
    fi
    if ui_confirm "$(t costs_ask)" n; then
        local -a sets=()
        ui_input "$(t price_in)" "0.00"
        sets+=(--set="price_input=$REPLY_TEXT")
        ui_input "$(t price_out)" "0.00"
        sets+=(--set="price_output=$REPLY_TEXT")
        ui_input "$(t budget)" "5.00"
        sets+=(--set="daily_budget_usd=$REPLY_TEXT")
        ui_run "$(t saving_prefs)" glpi_console plugins:ticketassistant:configure "${sets[@]}" || ui_output_box "$(t console_error)"
    fi
}

configure_unattended() {
    local -a args=()
    [[ -n $OPT_PROVIDER ]] && args+=(--provider="$OPT_PROVIDER")
    [[ -n $OPT_BASE_URL ]] && args+=(--base-url="$OPT_BASE_URL")
    [[ -n $OPT_API_VERSION ]] && args+=(--api-version="$OPT_API_VERSION")
    [[ -n $OPT_MODEL ]] && args+=(--model="$OPT_MODEL")
    args+=(--enable)
    if [[ -n $OPT_KEY_ENV ]]; then
        SECRET_INPUT=${!OPT_KEY_ENV:-}
        if [[ -z $SECRET_INPUT ]]; then
            ui_error "\$$OPT_KEY_ENV?"
            return 1
        fi
        ui_run "$(t saving)" glpi_console_secret plugins:ticketassistant:configure "${args[@]}" --api-key-stdin || { SECRET_INPUT=""; ui_output_box "$(t console_error)"; return 1; }
        SECRET_INPUT=""
    else
        ui_run "$(t saving)" glpi_console plugins:ticketassistant:configure "${args[@]}" || { ui_output_box "$(t console_error)"; return 1; }
    fi
    if ui_run "$(t testing)" glpi_console plugins:ticketassistant:configure --test; then
        TEST_RESULT=ok
        ui_note "$(last_output_line)"
    else
        TEST_RESULT=failed
        ui_output_box "$(t test_failed)"
    fi
    summarize_ai
}

summarize_ai() {
    read_status >/dev/null 2>&1
    if [[ -n ${STATUS[model]:-} ]]; then
        AI_SUMMARY="$(provider_label "${STATUS[provider]:-}") · ${STATUS[model]}"
    else
        AI_SUMMARY=$(t ai_not_configured)
    fi
}

# -----------------------------------------------------------------------------
# Step 5: library of solved tickets
# -----------------------------------------------------------------------------
LIBRARY_SUMMARY=""

sync_library() {
    local force=${1:-0}
    if ((!force)); then
        if [[ $OPT_SYNC == no ]]; then LIBRARY_SUMMARY=$(t skipped); return 0; fi
        if ((INTERACTIVE)) && ! ui_confirm "$(t sync_ask)" y; then LIBRARY_SUMMARY=$(t skipped); return 0; fi
    fi
    if ui_run "$(t syncing)" glpi_console plugins:ticketassistant:sync; then
        ui_note "$(last_output_line)"
    else
        ui_output_box "$(t console_error)"
    fi
    read_status >/dev/null 2>&1
    LIBRARY_SUMMARY=$(t cases "${STATUS[cases]:-0}")
}

# -----------------------------------------------------------------------------
# Step 6: cron, PDF tools, demonstration data
# -----------------------------------------------------------------------------
CRON_SUMMARY="" PDF_SUMMARY=""

cron_exists() {
    grep -rqsE "front/cron\.php|glpi:cron|bin/console[^|]*cron" /etc/crontab /etc/cron.d 2>/dev/null && return 0
    [[ -n $WEB_USER ]] && crontab -l -u "$WEB_USER" 2>/dev/null | grep -qE 'cron\.php|glpi:cron' && return 0
    command -v systemctl >/dev/null 2>&1 && systemctl list-timers --all --no-legend 2>/dev/null | grep -qi glpi && return 0
    return 1
}

check_cron() {
    if cron_exists; then
        ui_ok "$(t cron_found)"
        CRON_SUMMARY=$(t found)
        return 0
    fi
    local user=${WEB_USER:-www-data} file=/etc/cron.d/glpi-cron
    local line="* * * * * $user $PHP $GLPI/front/cron.php >/dev/null 2>&1"
    ui_box warn "" "$(t cron_missing)"
    local create=0
    if [[ $OPT_CRON == yes ]]; then
        create=1
    elif [[ $OPT_CRON != no ]] && ((INTERACTIVE)) && [[ $EUID -eq 0 && -d /etc/cron.d ]]; then
        ui_confirm "$(t cron_create "$file" "$user")" y && create=1
    fi
    if ((create)) && [[ $EUID -eq 0 && -d /etc/cron.d && ! -e $file ]]; then
        if printf '# GLPI automatic actions (created by the GLPI AI Ticket Assistant installer)\n%s\n' "$line" >"$file" && chmod 644 "$file"; then
            ui_ok "$(t cron_created "$file")"
            CRON_SUMMARY=$(t created)
            return 0
        fi
    fi
    ui_info "$(t cron_manual "$user")"
    ui_note "* * * * * $PHP $GLPI/front/cron.php"
    CRON_SUMMARY=$(t missing)
}

check_poppler() {
    if command -v pdftotext >/dev/null 2>&1 && command -v pdftoppm >/dev/null 2>&1; then
        ui_ok "$(t poppler_ok)"
        PDF_SUMMARY="poppler-utils"
        return 0
    fi
    PDF_SUMMARY=$(t builtin_reader)
    ui_info "$(t poppler_missing)"
    [[ $EUID -eq 0 ]] || return 0
    local -a command=()
    if command -v apt-get >/dev/null 2>&1; then command=(apt_install poppler-utils)
    elif command -v dnf >/dev/null 2>&1; then command=(dnf install -y poppler-utils)
    elif command -v yum >/dev/null 2>&1; then command=(yum install -y poppler-utils)
    elif command -v zypper >/dev/null 2>&1; then command=(zypper --non-interactive install poppler-tools)
    elif command -v apk >/dev/null 2>&1; then command=(apk add poppler-utils)
    elif command -v pacman >/dev/null 2>&1; then command=(pacman -S --noconfirm poppler)
    else return 0; fi
    local install=0 shown=${command[*]}
    shown=${shown/apt_install/apt-get install -y}
    if [[ $OPT_POPPLER == yes ]]; then install=1
    elif [[ $OPT_POPPLER != no ]] && ((INTERACTIVE)); then ui_confirm "$(t poppler_install "$shown")" y && install=1; fi
    if ((install)); then
        if ui_run "$(t poppler_installing)" "${command[@]}"; then
            PDF_SUMMARY="poppler-utils"
        else
            ui_output_box "$(t poppler_installing)"
        fi
    fi
}

apt_install() { # refreshes the package lists only when the package is unknown
    DEBIAN_FRONTEND=noninteractive apt-get install -y "$@" ||
        { DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y "$@"; }
}

create_demo() {
    local wanted=$OPT_DEMO
    if ((!wanted)) && ((INTERACTIVE)); then ui_confirm "$(t demo_ask)" n && wanted=1; fi
    ((wanted)) || return 0
    ui_run "$(t demo_running)" glpi_console plugins:ticketassistant:demo --yes --username="$GLPI_LOGIN" || ui_output_box "$(t console_error)"
}

# -----------------------------------------------------------------------------
# Flows
# -----------------------------------------------------------------------------
summary() {
    read_status >/dev/null 2>&1
    local test_mark=""
    [[ $TEST_RESULT == ok ]] && test_mark="ok"
    [[ $TEST_RESULT == failed ]] && test_mark="error"
    ui_title "$SYM_STAR $(t summary_title)"
    ui_kv "$(t s_glpi)" "${GLPI_VERSION:-?}  $C_DIM$GLPI$C_RESET" ok
    ui_kv "$(t s_plugin)" "${STATUS[version]:-$PLUGIN_VERSION}  $C_DIM$([[ $INSTALL_MODE == link ]] && t mode_link_short || t mode_copy_short)$C_RESET" ok
    ui_kv "$(t s_ai)" "${AI_SUMMARY:-$(t ai_not_configured)}$([[ -n $TEST_RESULT ]] && printf '  %s(%s)%s' "$C_DIM" "$([[ $TEST_RESULT == ok ]] && t test_ok || t test_failed)" "$C_RESET")" "${test_mark:-warn}"
    [[ -n $LIBRARY_SUMMARY ]] && ui_kv "$(t s_library)" "$LIBRARY_SUMMARY" ok
    [[ -n $CRON_SUMMARY ]] && ui_kv "$(t s_cron)" "$CRON_SUMMARY" "$([[ $CRON_SUMMARY == "$(t missing)" ]] && echo warn || echo ok)"
    [[ -n $PDF_SUMMARY ]] && ui_kv "$(t s_pdf)" "$PDF_SUMMARY" ok
    ui_kv "$(t s_log)" "$LOG_FILE"
    printf '\n  %s%s%s\n' "$C_BOLD" "$(t next_title)" "$C_RESET"
    printf '   %s1%s %s\n' "$C_ACCENT" "$C_RESET" "$(t next_1)"
    printf '   %s2%s %s\n' "$C_ACCENT" "$C_RESET" "$(t next_2)"
    printf '   %s3%s %s\n\n' "$C_ACCENT" "$C_RESET" "$(t next_3)"
}

install_flow() {
    ui_step 3 "$(t step_plugin)"
    place_plugin || exit 1
    console_install || exit 1
    ui_step 4 "$(t step_ai)"
    configure_ai
    ui_step 5 "$(t step_library)"
    create_demo
    sync_library
    ui_step 6 "$(t step_extras)"
    check_cron
    check_poppler
    summary
}

status_flow() {
    ensure_plugin_active || exit 1
    ui_run "plugins:ticketassistant:status" glpi_console plugins:ticketassistant:status
    sed 's/\x1b\[[0-9;]*m//g' "$RUN_OUT" | sed 's/^/    /'
    if ui_run "$(t testing)" glpi_console plugins:ticketassistant:configure --test; then
        ui_note "$(last_output_line)"
    else
        ui_output_box "$(t test_failed)"
    fi
}

uninstall_flow() {
    ui_box warn "" "$(t un_warning)"
    if ((INTERACTIVE)); then
        ui_input "$(t un_confirm "$PLUGIN_KEY")" ""
        [[ $REPLY_TEXT == "$PLUGIN_KEY" ]] || { ui_info "$(t un_cancelled)"; return 0; }
    elif ((!OPT_YES)); then
        ui_info "$(t un_cancelled)"
        return 0
    fi
    ui_run "$(t deactivating)" glpi_console plugin:deactivate "$PLUGIN_KEY" || true
    ui_run "$(t uninstalling)" glpi_console plugin:uninstall "$PLUGIN_KEY" || { ui_output_box "$(t console_error)"; return 1; }
    local dest=$GLPI/plugins/$PLUGIN_KEY
    if [[ -e $dest || -L $dest ]] && { ((!INTERACTIVE)) || ui_confirm "$(t remove_folder "$dest")" y; }; then
        if backup_plugin; then
            rm -rf "$dest"
            [[ -n $BACKUP_FILE ]] && ui_ok "$(t removed "$BACKUP_FILE")"
        fi
    fi
    ui_ok "$(t uninstalled)"
}

main_menu() {
    local installed=$1
    local -a items=() actions=()
    if version_gt "$PLUGIN_VERSION" "$installed"; then items+=("$(t m_update "$PLUGIN_VERSION")"); else items+=("$(t m_reinstall "$PLUGIN_VERSION")"); fi
    actions+=(install)
    items+=("$(t m_configure)" "$(t m_status)" "$(t m_sync)" "$(t m_uninstall)" "$(t m_exit)")
    actions+=(configure status sync uninstall exit)
    ui_menu "$(t menu_title "$installed")" 0 "${items[@]}"
    case ${actions[REPLY_INDEX]} in
        install) install_flow ;;
        configure)
            ui_step 4 "$(t step_ai)"
            ensure_plugin_active || exit 1
            configure_ai
            summary
            ;;
        status) status_flow ;;
        sync)
            ensure_plugin_active || exit 1
            sync_library 1
            ;;
        uninstall) uninstall_flow ;;
        *) exit 0 ;;
    esac
}

main() {
    if ((OPT_HELP)); then
        usage
        exit 0
    fi
    ui_init
    : >"$LOG_FILE" 2>/dev/null && chmod 600 "$LOG_FILE" 2>/dev/null || LOG_FILE=/dev/null
    ui_banner
    step_checks
    step_glpi
    local installed
    installed=$(installed_plugin_version)
    case $OPT_ACTION in
        status) status_flow ;;
        configure)
            ensure_plugin_active || exit 1
            configure_ai
            summary
            ;;
        uninstall) uninstall_flow ;;
        *)
            if [[ -n $installed ]] && ((INTERACTIVE)); then main_menu "$installed"; else install_flow; fi
            ;;
    esac
}

# Sourcing the file (tests) only defines the functions
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    main
fi
