#!/usr/bin/env bash
# Tests of install.sh without GLPI: a fake console (fake-glpi/bin/console)
# records what the installer does.
#
#   bash tests/installer/installer-test.sh [/path/to/php]

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
ROOT=$(cd "$HERE/../.." && pwd -P)
PHPBIN=${1:-$(command -v php)}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/ticketassistant-test.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

FAILS=0
check() { # check NAME EXPECTED ACTUAL
    if [[ $2 == "$3" ]]; then
        printf 'PASS  %s\n' "$1"
    else
        printf 'FAIL  %s: expected [%s], got [%s]\n' "$1" "$2" "$3"
        FAILS=$((FAILS + 1))
    fi
}

new_glpi() { # fresh fake GLPI in $WORK/glpi
    rm -rf "$WORK/glpi"
    cp -R "$HERE/fake-glpi" "$WORK/glpi"
    mkdir -p "$WORK/glpi/files" "$WORK/glpi/plugins"
}
state() { grep -o "\"$1\": [^,}]*" "$WORK/glpi/files/state.json" | head -n1; }

# --- The probe of the containers is the same code in both installers ----------
probe_sh=$(sed -n "/^read -r -d '' PHP_PROBE <<'PHP'/,/^PHP\$/p" "$ROOT/install.sh" | sed '1d;$d')
probe_ps=$(sed -n "/^\$PhpProbe = @'\$/,/^'@\$/p" "$ROOT/install.ps1" | sed '1d;$d' | tr -d '\r')
check "probe: same code in install.sh and install.ps1" yes "$([[ -n $probe_sh && $probe_sh == "$probe_ps" ]] && echo yes)"

# --- Unattended installation -------------------------------------------------
new_glpi
TEST_KEY="sk-test-123" bash "$ROOT/install.sh" --yes --lang en --glpi "$WORK/glpi" --php "$PHPBIN" \
    --provider openai --model gpt-5-mini --api-key-env TEST_KEY --sync yes --cron no --poppler no >"$WORK/out.txt" 2>&1
check "unattended: exit code" 0 "$?"
check "unattended: plugin copied" yes "$([[ -f $WORK/glpi/plugins/ticketassistant/setup.php ]] && echo yes)"
check "unattended: repository files left out" no "$([[ -e $WORK/glpi/plugins/ticketassistant/tests || -e $WORK/glpi/plugins/ticketassistant/install.sh ]] && echo yes || echo no)"
check "unattended: key received intact" '"key": "sk-test-123"' "$(state key)"
check "unattended: key never on a command line" 0 "$(grep -c 'LEAK' "$WORK/glpi/files/state.json")"
check "unattended: model" '"model": "gpt-5-mini"' "$(state model)"
check "unattended: enabled" '"enabled": 1' "$(state enabled)"
check "unattended: library synchronized" '"synced": true' "$(state synced)"
check "unattended: summary" 1 "$(grep -c 'connection OK' "$WORK/out.txt")"

# --- Archive extracted in plugins/ and installer run from there, in Portuguese --
cp "$ROOT/install.sh" "$WORK/glpi/plugins/ticketassistant/"
bash "$WORK/glpi/plugins/ticketassistant/install.sh" --yes --lang pt --glpi "$WORK/glpi" --php "$PHPBIN" \
    --skip-ai --sync no --cron no --poppler no >"$WORK/out.txt" 2>&1
check "in place: exit code" 0 "$?"
check "in place: no copy onto itself" 1 "$(grep -c 'arquivos do plugin' "$WORK/out.txt")"
check "in place: console messages in Portuguese" yes "$(grep -q 'lang=pt_BR' "$WORK/glpi/files/state.json" && echo yes)"

# --- GLPI in a container: a fake docker (fake-docker/docker.php) plays a container
# "glpi-app" whose files are in $WORK/container, and a "db" container without PHP
CONTAINER=$WORK/container
new_container() { # GLPI in /var/www/glpi, a folder /var/glpi for the volume of the official image
    rm -rf "$CONTAINER"
    mkdir -p "$CONTAINER/etc" "$CONTAINER/var/www" "$CONTAINER/var/glpi/marketplace"
    printf 'root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n' >"$CONTAINER/etc/passwd"
    cp -R "$HERE/fake-glpi" "$CONTAINER/var/www/glpi"
    mkdir -p "$CONTAINER/var/www/glpi/files" "$CONTAINER/var/www/glpi/plugins"
    : >"$WORK/docker.log"
}
in_container() {
    TICKETASSISTANT_DOCKER="$HERE/fake-docker/docker" FAKE_DOCKER_PHP=$PHPBIN FAKE_DOCKER_ROOT=$CONTAINER \
        FAKE_DOCKER_LOG=$WORK/docker.log TICKETASSISTANT_BACKUP_DIR=$WORK/backups "$@"
}
cstate() { grep -o "\"$1\": [^,}]*" "$CONTAINER/var/www/glpi/files/state.json" | head -n1; }

new_container
# shellcheck disable=SC2016
check "container: detected" "docker:glpi-app:/var/www/glpi" "$(in_container bash -c 'source "$1" --lang en; detect_docker_glpi' _ "$ROOT/install.sh")"

TEST_KEY="sk-test-456" in_container bash "$ROOT/install.sh" --yes --lang en --docker glpi-app --web-user www-data \
    --provider openai --model gpt-5-mini --api-key-env TEST_KEY --sync yes --cron no --poppler no >"$WORK/out.txt" 2>&1
check "container: exit code" 0 "$?"
check "container: plugin copied into plugins/" yes "$([[ -f $CONTAINER/var/www/glpi/plugins/ticketassistant/setup.php ]] && echo yes)"
check "container: no temporary folder left" no "$([[ -e $CONTAINER/var/www/glpi/plugins/ticketassistant.new ]] && echo yes || echo no)"
check "container: repository files left out" no "$([[ -e $CONTAINER/var/www/glpi/plugins/ticketassistant/tests ]] && echo yes || echo no)"
check "container: console run as the web server user" yes "$(grep -q '"exec","-w","/var/www/glpi","-u","www-data"' "$WORK/docker.log" && echo yes)"
check "container: key received intact" '"key": "sk-test-456"' "$(cstate key)"
check "container: key never on a command line" 0 "$(grep -c 'sk-test-456' "$WORK/docker.log")"
check "container: enabled" '"enabled": 1' "$(cstate enabled)"
check "container: library synchronized" '"synced": true' "$(cstate synced)"
check "container: warning, plugins/ not on a volume" 1 "$(grep -c 'is not on a volume' "$WORK/out.txt")"
check "container: cron line for this server" 1 "$(grep -c 'exec -u www-data glpi-app php /var/www/glpi/front/cron.php' "$WORK/out.txt")"
check "container: summary" 1 "$(grep -c 'connection OK' "$WORK/out.txt")"

# Official image: the marketplace folder is on the volume /var/glpi, a supervisor job runs the cron
new_container
mkdir -p "$CONTAINER/etc/supervisor/conf.d"
printf '[program:glpi-cron]\ncommand=php /var/www/glpi/front/cron.php\n' >"$CONTAINER/etc/supervisor/conf.d/glpi.conf"
export FAKE_DOCKER_MOUNTS="/var/glpi=/var/lib/docker/volumes/glpi_data/_data" FAKE_DOCKER_ENV="GLPI_MARKETPLACE_DIR=/var/glpi/marketplace"
in_container bash "$ROOT/install.sh" --yes --lang en --docker glpi-app --web-user www-data --skip-ai --sync no --cron no --poppler no >"$WORK/out.txt" 2>&1
check "volume: exit code" 0 "$?"
check "volume: plugin in the marketplace folder" yes "$([[ -f $CONTAINER/var/glpi/marketplace/ticketassistant/setup.php ]] && echo yes)"
check "volume: nothing in plugins/" no "$([[ -e $CONTAINER/var/www/glpi/plugins/ticketassistant ]] && echo yes || echo no)"
check "volume: GLPI finds it" '"folder": "marketplace"' "$(cstate folder)"
check "volume: cron of the container found" 1 "$(grep -c 'Cron found in the container (/etc/supervisor/conf.d/glpi.conf)' "$WORK/out.txt")"

in_container bash "$ROOT/install.sh" --yes --lang en --docker glpi-app --web-user www-data --skip-ai --sync no --cron no --poppler no >"$WORK/out.txt" 2>&1
check "update: exit code" 0 "$?"
check "update: previous version saved" 1 "$(find "$WORK/backups" -name 'ticketassistant-*.tar.gz' | wc -l | tr -d ' ')"
check "update: the backup holds the plugin" yes "$(tar -tzf "$(find "$WORK/backups" -name 'ticketassistant-*.tar.gz' | head -n1)" | grep -q '^ticketassistant/setup.php$' && echo yes)"

in_container bash "$ROOT/install.sh" --yes --lang en --docker glpi-app --web-user www-data --uninstall >"$WORK/out.txt" 2>&1
check "uninstall: exit code" 0 "$?"
check "uninstall: uninstalled in GLPI" '"installed": false' "$(cstate installed)"
check "uninstall: folder removed" no "$([[ -e $CONTAINER/var/glpi/marketplace/ticketassistant ]] && echo yes || echo no)"
check "uninstall: folder saved first" 2 "$(find "$WORK/backups" -name 'ticketassistant-*.tar.gz' | wc -l | tr -d ' ')"
unset FAKE_DOCKER_MOUNTS FAKE_DOCKER_ENV

# Plugin folder mounted from the server (development): used as it is
new_container
mkdir -p "$CONTAINER/var/www/glpi/plugins/ticketassistant"
cp "$ROOT/setup.php" "$CONTAINER/var/www/glpi/plugins/ticketassistant/"
FAKE_DOCKER_MOUNTS="/var/www/glpi/plugins/ticketassistant=/home/dev/glpi-ai-ticket-assistant" in_container bash "$ROOT/install.sh" \
    --yes --lang en --docker glpi-app --web-user www-data --provider ollama --base-url http://localhost:11434/v1 --model gemma3 \
    --sync no --cron no --poppler no >"$WORK/out.txt" 2>&1
check "mounted: exit code" 0 "$?"
check "mounted: files left alone" 1 "$(find "$CONTAINER/var/www/glpi/plugins/ticketassistant" -type f | wc -l | tr -d ' ')"
check "mounted: nothing copied" 0 "$(grep -c '^\["cp"' "$WORK/docker.log")"
check "mounted: localhost is the container" 1 "$(grep -c '(172.18.0.1)' "$WORK/out.txt")"

# --- Terminal widgets driven by scripted keys -----------------------------------
(
    # shellcheck source=/dev/null
    source "$ROOT/install.sh" --lang en
    ui_init
    INTERACTIVE=1 IS_TTY=1 TERM_WIDTH=90 TERM_LINES=30
    KEYS=()
    press() { # UP DOWN LEFT RIGHT ENTER ESC BS, LINE:text for a whole line, anything else typed
        local token i
        for token in "$@"; do
            case $token in
                UP) KEYS+=($'\e' '[' 'A') ;;
                DOWN) KEYS+=($'\e' '[' 'B') ;;
                LEFT) KEYS+=($'\e' '[' 'D') ;;
                RIGHT) KEYS+=($'\e' '[' 'C') ;;
                ENTER) KEYS+=('<ENTER>') ;;
                ESC) KEYS+=($'\e' '<TIMEOUT>') ;;
                BS) KEYS+=($'\x7f') ;;
                LINE:*) KEYS+=("$token") ;;
                *) for ((i = 0; i < ${#token}; i++)); do KEYS+=("${token:i:1}"); done ;;
            esac
        done
    }
    # Terminal reads (-s or -e) come from the queue, every other read is the real one
    read() {
        local arg terminal=0 var="" default="" i
        local -a rest=("$@")
        for ((i = 0; i < ${#rest[@]}; i++)); do
            arg=${rest[i]}
            case $arg in
                -e) terminal=1 ;;
                -i) default=${rest[i + 1]}; i=$((i + 1)) ;;
                -p | -t) i=$((i + 1)) ;;
                -*s*) terminal=1 ;;
                -*) ;;
                *) var=$arg ;;
            esac
        done
        if ((!terminal)); then builtin read "$@"; return; fi
        ((${#KEYS[@]})) || return 1
        local key=${KEYS[0]}
        KEYS=("${KEYS[@]:1}")
        case $key in
            '<TIMEOUT>') return 1 ;;
            '<ENTER>') key="" ;;
            LINE:DEFAULT) key=$default ;;
            LINE:*) key=${key#LINE:} ;;
        esac
        [[ -n $var ]] && printf -v "$var" '%s' "$key"
        return 0
    }
    exec 3>&1 1>/dev/null # the widgets draw on stdout; results go to fd 3
    check() {
        if [[ $2 == "$3" ]]; then printf 'PASS  %s\n' "$1" >&3; else printf 'FAIL  %s: expected [%s], got [%s]\n' "$1" "$2" "$3" >&3; FAILS=$((FAILS + 1)); fi
    }
    FAILS=0

    press DOWN DOWN ENTER; ui_menu "menu" 0 a b c d; check "menu: arrows" 2 "$REPLY_INDEX"
    press UP ENTER; ui_menu "menu" 0 a b c d; check "menu: wraps upwards" 3 "$REPLY_INDEX"
    press 3 ENTER; ui_menu "menu" 0 a b c d; check "menu: digit" 2 "$REPLY_INDEX"
    press j j k ENTER; ui_menu "menu" 1 a$'\t'first b$'\t'second c d; check "menu: j/k keys" 2 "$REPLY_INDEX"
    press 4.1 ENTER; ui_filter "model" gpt-5-mini gpt-5.4 gpt-4.1-mini text-embedding-3-small
    check "filter: typed text" gpt-4.1-mini "$REPLY_TEXT"
    press gpt BS BS BS DOWN DOWN DOWN ENTER; ui_filter "model" gpt-5-mini gpt-5.4 gpt-4.1-mini text-embedding-3-small
    check "filter: erased, arrows" text-embedding-3-small "$REPLY_TEXT"
    press zzz ENTER ESC; ui_filter "model" gpt-5-mini gpt-5.4; check "filter: no match, Esc" "" "$REPLY_TEXT"
    press RIGHT ENTER; ui_confirm "sure?" y; check "confirm: arrow toggles" 1 "$?"
    press n; ui_confirm "sure?" y; check "confirm: n" 1 "$?"
    press y; ui_confirm "sure?" n; check "confirm: y" 0 "$?"
    press ENTER; ui_confirm "sure?" n; check "confirm: Enter keeps the default" 1 "$?"
    press sk- BS 12 ENTER; ui_secret "key"; check "secret: backspace" sk12 "$REPLY_TEXT"
    press LINE:DEFAULT; ui_input "name" glpi; check "input: default" glpi "$REPLY_TEXT"

    # Interactive AI configuration: configure now? (Enter = yes) · Ollama (6 downs) · default address
    # · no key · model filter "5.4" · personalize? Enter = no · costs? Enter = no
    rm -rf "$WORK/glpi/files/state.json"
    GLPI=$WORK/glpi PHP=$PHPBIN WEB_USER="" GLPI_VERSION=11.0.11
    glpi_console plugin:install --username=glpi ticketassistant >/dev/null 2>&1
    glpi_console plugin:activate ticketassistant >/dev/null 2>&1
    press ENTER DOWN DOWN DOWN DOWN DOWN DOWN ENTER LINE:DEFAULT ENTER 5.4 ENTER ENTER ENTER
    configure_ai
    check "configure: provider" '"provider": "ollama"' "$(state provider)"
    check "configure: model" '"model": "gpt-5.4"' "$(state model)"
    check "configure: enabled" '"enabled": 1' "$(state enabled)"
    check "configure: test passed" ok "$TEST_RESULT"
    check "configure: every key consumed" 0 "${#KEYS[@]}"
    exit "$FAILS"
)
FAILS=$((FAILS + $?))

printf '%d failure(s)\n' "$FAILS"
exit $((FAILS > 0))
