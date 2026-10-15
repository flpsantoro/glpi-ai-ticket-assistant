<#
    Tests of install.ps1 without GLPI: a fake console (fake-glpi\bin\console)
    records what the installer does. Windows PowerShell 5.1 and PowerShell 7.

        powershell -ExecutionPolicy Bypass -File tests\installer\installer-test.ps1 -PhpExe C:\php\php.exe
#>
param([Parameter(Mandatory = $true)][string]$PhpExe)

$ErrorActionPreference = 'Stop'
$TestRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$TestHere = $PSScriptRoot
$TestPhp = (Resolve-Path -LiteralPath $PhpExe).Path
$TestWork = Join-Path ([IO.Path]::GetTempPath()) ('ticketassistant-test-' + [guid]::NewGuid().ToString('N'))
$TestGlpi = Join-Path $TestWork 'glpi'
$TestShell = (Get-Process -Id $PID).Path
$script:Fails = 0

function Check([string]$Name, $Expected, $Actual) {
    # Ordinal: a culture-aware comparison would ignore an invisible byte order mark
    if ([string]::Equals([string]$Expected, [string]$Actual, [StringComparison]::Ordinal)) { [Console]::Out.WriteLine("PASS  $Name") }
    else { [Console]::Out.WriteLine("FAIL  ${Name}: expected [$Expected], got [$Actual]"); $script:Fails++ }
}
function New-FakeGlpi {
    if (Test-Path -LiteralPath $TestGlpi) { Remove-Item -LiteralPath $TestGlpi -Recurse -Force }
    Copy-Item -LiteralPath (Join-Path $TestHere 'fake-glpi') -Destination $TestGlpi -Recurse
    New-Item -ItemType Directory -Path (Join-Path $TestGlpi 'files'), (Join-Path $TestGlpi 'plugins') -Force | Out-Null
}
function Get-FakeState { return (Get-Content -LiteralPath (Join-Path $TestGlpi 'files\state.json') -Raw -Encoding UTF8 | ConvertFrom-Json) }

New-Item -ItemType Directory -Path $TestWork -Force | Out-Null
try {
    # --- Unattended installation ---------------------------------------------
    New-FakeGlpi
    $env:TEST_KEY = 'sk-test-123'
    $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $TestRoot 'install.ps1') -Yes -Lang en -GlpiPath $TestGlpi -Php $TestPhp `
        -Provider openai -Model gpt-5-mini -ApiKeyEnv TEST_KEY -Sync yes -Cron no 2>&1 | ForEach-Object { "$_" }
    Check 'unattended: exit code' 0 $LASTEXITCODE
    $plugin = Join-Path $TestGlpi 'plugins\ticketassistant'
    Check 'unattended: plugin copied' $true (Test-Path -LiteralPath (Join-Path $plugin 'setup.php'))
    Check 'unattended: repository files left out' $false ((Test-Path -LiteralPath (Join-Path $plugin 'tests')) -or (Test-Path -LiteralPath (Join-Path $plugin 'install.ps1')))
    $state = Get-FakeState
    Check 'unattended: key received intact (no byte order mark)' 'sk-test-123' $state.key
    Check 'unattended: key never on a command line' 0 @($state.calls | Where-Object { $_ -match 'LEAK' }).Count
    Check 'unattended: model' 'gpt-5-mini' $state.config.model
    Check 'unattended: enabled' 1 $state.config.enabled
    Check 'unattended: library synchronized' $true $state.synced
    Check 'unattended: summary' 1 @($output | Where-Object { $_ -match '\(connection OK\)' }).Count

    # --- Archive extracted in plugins\ and installer run from there, in Portuguese
    Copy-Item -LiteralPath (Join-Path $TestRoot 'install.ps1') -Destination $plugin
    $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $plugin 'install.ps1') -Yes -Lang pt -GlpiPath $TestGlpi -Php $TestPhp `
        -SkipAi -Sync no -Cron no 2>&1 | ForEach-Object { "$_" }
    Check 'in place: exit code' 0 $LASTEXITCODE
    Check 'in place: no copy onto itself' 1 @($output | Where-Object { $_ -match 'arquivos do plugin' }).Count
    Check 'in place: console messages in Portuguese' $true (@((Get-FakeState).calls | Where-Object { $_ -match 'lang=pt_BR' }).Count -gt 0)

    # --- French -------------------------------------------------------------------
    $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $plugin 'install.ps1') -Yes -Lang fr -GlpiPath $TestGlpi -Php $TestPhp `
        -SkipAi -Sync no -Cron no 2>&1 | ForEach-Object { "$_" }
    Check 'french: exit code' 0 $LASTEXITCODE
    Check 'french: console messages in French' $true (@((Get-FakeState).calls | Where-Object { $_ -match 'lang=fr_FR' }).Count -gt 0)
    # "Prochaines etapes" with its accent, which the code page of the console may turn into two characters
    Check 'french: messages of the installer' 1 @($output | Where-Object { $_ -match 'Prochaines .{1,2}tapes' }).Count
    Check 'french: no language question in unattended mode' 0 @($output | Where-Object { $_ -match 'Langue' }).Count

    # --- GLPI in a container: a fake docker (fake-docker\docker.php) plays a container
    # "glpi-app" whose files are in $TestContainer, and a "db" container without PHP
    $TestContainer = Join-Path $TestWork 'container'
    $TestDockerLog = Join-Path $TestWork 'docker.log'
    $TestBackups = Join-Path $TestWork 'backups'
    function New-FakeContainer {
        if (Test-Path -LiteralPath $TestContainer) { Remove-Item -LiteralPath $TestContainer -Recurse -Force }
        New-Item -ItemType Directory -Path (Join-Path $TestContainer 'etc'), (Join-Path $TestContainer 'var\www'), (Join-Path $TestContainer 'var\glpi\marketplace') -Force | Out-Null
        [IO.File]::WriteAllText((Join-Path $TestContainer 'etc\passwd'), "root:x:0:0:root:/root:/bin/bash`nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin`n")
        Copy-Item -LiteralPath (Join-Path $TestHere 'fake-glpi') -Destination (Join-Path $TestContainer 'var\www\glpi') -Recurse
        New-Item -ItemType Directory -Path (Join-Path $TestContainer 'var\www\glpi\files'), (Join-Path $TestContainer 'var\www\glpi\plugins') -Force | Out-Null
        [IO.File]::WriteAllText($TestDockerLog, '')
    }
    function Get-ContainerState { return (Get-Content -LiteralPath (Join-Path $TestContainer 'var\www\glpi\files\state.json') -Raw -Encoding UTF8 | ConvertFrom-Json) }
    function Get-DockerLog { return @(Get-Content -LiteralPath $TestDockerLog -Encoding UTF8) }
    function Get-Backups { return @(Get-ChildItem -LiteralPath $TestBackups -Filter 'ticketassistant-*.zip' -ErrorAction SilentlyContinue) }
    $env:TICKETASSISTANT_DOCKER = $TestPhp
    $env:TICKETASSISTANT_DOCKER_ARGS = Join-Path $TestHere 'fake-docker\docker.php'
    $env:FAKE_DOCKER_ROOT = $TestContainer
    $env:FAKE_DOCKER_LOG = $TestDockerLog
    $env:TICKETASSISTANT_BACKUP_DIR = $TestBackups
    try {
        New-FakeContainer
        $env:TEST_KEY = 'sk-test-456'
        $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $TestRoot 'install.ps1') -Yes -Lang en -Docker glpi-app -WebUser www-data `
            -Provider openai -Model gpt-5-mini -ApiKeyEnv TEST_KEY -Sync yes -Cron no 2>&1 | ForEach-Object { "$_" }
        Check 'container: exit code' 0 $LASTEXITCODE
        $plugin = Join-Path $TestContainer 'var\www\glpi\plugins\ticketassistant'
        Check 'container: plugin copied into plugins/' $true (Test-Path -LiteralPath (Join-Path $plugin 'setup.php'))
        Check 'container: no temporary folder left' $false (Test-Path -LiteralPath "$plugin.new")
        Check 'container: repository files left out' $false (Test-Path -LiteralPath (Join-Path $plugin 'tests'))
        $state = Get-ContainerState
        Check 'container: key received intact (no byte order mark)' 'sk-test-456' $state.key
        Check 'container: key never on a command line' 0 @(Get-DockerLog | Where-Object { $_ -match 'sk-test-456' }).Count
        Check 'container: console run as the web server user' $true (@(Get-DockerLog | Where-Object { $_ -match '"exec","-w","/var/www/glpi","-u","www-data"' }).Count -gt 0)
        Check 'container: enabled' 1 $state.config.enabled
        Check 'container: library synchronized' $true $state.synced
        Check 'container: warning, plugins/ not on a volume' $true (@($output | Where-Object { $_ -match 'recreating' }).Count -gt 0)
        Check 'container: cron command shown' 1 @($output | Where-Object { $_ -match 'exec -u www-data glpi-app php /var/www/glpi/front/cron\.php' }).Count
        Check 'container: summary' 1 @($output | Where-Object { $_ -match '\(connection OK\)' }).Count

        # Official image: the marketplace folder is on the volume /var/glpi
        New-FakeContainer
        $env:FAKE_DOCKER_MOUNTS = '/var/glpi=/var/lib/docker/volumes/glpi_data/_data'
        $env:FAKE_DOCKER_ENV = 'GLPI_MARKETPLACE_DIR=/var/glpi/marketplace'
        $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $TestRoot 'install.ps1') -Yes -Lang en -Docker glpi-app -WebUser www-data `
            -SkipAi -Sync no -Cron no 2>&1 | ForEach-Object { "$_" }
        Check 'volume: exit code' 0 $LASTEXITCODE
        $plugin = Join-Path $TestContainer 'var\glpi\marketplace\ticketassistant'
        Check 'volume: plugin in the marketplace folder' $true (Test-Path -LiteralPath (Join-Path $plugin 'setup.php'))
        Check 'volume: nothing in plugins/' $false (Test-Path -LiteralPath (Join-Path $TestContainer 'var\www\glpi\plugins\ticketassistant'))
        Check 'volume: GLPI finds it' 'marketplace' (Get-ContainerState).folder

        $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $TestRoot 'install.ps1') -Yes -Lang en -Docker glpi-app -WebUser www-data `
            -SkipAi -Sync no -Cron no 2>&1 | ForEach-Object { "$_" }
        Check 'update: exit code' 0 $LASTEXITCODE
        Check 'update: previous version saved' 1 (Get-Backups).Count
        $entries = @()
        if ((Get-Backups).Count) {
            Add-Type -AssemblyName System.IO.Compression.FileSystem
            $zip = [IO.Compression.ZipFile]::OpenRead((Get-Backups)[0].FullName)
            try { $entries = @($zip.Entries | ForEach-Object { $_.FullName }) } finally { $zip.Dispose() }
        }
        Check 'update: the backup holds the plugin' $true ($entries -contains 'setup.php')

        $output = & $TestShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $TestRoot 'install.ps1') -Yes -Lang en -Docker glpi-app -WebUser www-data `
            -Uninstall 2>&1 | ForEach-Object { "$_" }
        Check 'uninstall: exit code' 0 $LASTEXITCODE
        Check 'uninstall: uninstalled in GLPI' $false (Get-ContainerState).installed
        Check 'uninstall: folder removed' $false (Test-Path -LiteralPath $plugin)
        Check 'uninstall: folder saved first' 2 (Get-Backups).Count
    } finally {
        foreach ($name in @('TICKETASSISTANT_DOCKER', 'TICKETASSISTANT_DOCKER_ARGS', 'FAKE_DOCKER_ROOT', 'FAKE_DOCKER_LOG', 'FAKE_DOCKER_MOUNTS', 'FAKE_DOCKER_ENV', 'TICKETASSISTANT_BACKUP_DIR')) {
            Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
        }
    }

    # --- Terminal widgets driven by scripted keys --------------------------------
    . (Join-Path $TestRoot 'install.ps1') -Lang en
    Initialize-Ui
    $script:Interactive = $true
    $script:Width = 90
    function Get-WindowWidth { 100 }
    function Get-WindowHeight { 30 }
    function Get-CursorTop { 10 }
    function Get-CursorLeft { 0 }
    function Set-CursorPos([int]$Left, [int]$Top) { }
    function Set-CursorVisible([bool]$Visible) { }
    function Write-Host { param([Parameter(ValueFromRemainingArguments = $true)]$Rest) }

    $script:Keys = New-Object System.Collections.Generic.Queue[object]
    function Press {
        foreach ($token in $args) {
            if ($token -match '^(UpArrow|DownArrow|LeftArrow|RightArrow|Enter|Escape|Backspace|Tab|Home|End|PageUp|PageDown)$') {
                $char = [char]0
                switch ($token) { 'Enter' { $char = [char]13 } 'Escape' { $char = [char]27 } 'Backspace' { $char = [char]8 } 'Tab' { $char = [char]9 } }
                $script:Keys.Enqueue([pscustomobject]@{ Key = $token; KeyChar = $char; Modifiers = 0 })
            } else {
                foreach ($c in ([string]$token).ToCharArray()) { $script:Keys.Enqueue([pscustomobject]@{ Key = 'Char'; KeyChar = $c; Modifiers = 0 }) }
            }
        }
    }
    function Read-UiKey {
        if ($script:Keys.Count -eq 0) { throw 'no more scripted keys' }
        return $script:Keys.Dequeue()
    }

    Press DownArrow DownArrow Enter; Check 'menu: arrows' 2 (Show-Menu 'menu' @('a', 'b', 'c', 'd') 0)
    Press UpArrow Enter; Check 'menu: wraps upwards' 3 (Show-Menu 'menu' @('a', 'b', 'c', 'd') 0)
    Press '3' Enter; Check 'menu: digit' 2 (Show-Menu 'menu' @('a', 'b', 'c', 'd') 0)
    Press j j k Enter; Check 'menu: j/k keys' 2 (Show-Menu 'menu' @("a`tfirst", "b`tsecond", 'c', 'd') 1)
    Press '4.1' Enter; Check 'filter: typed text' 'gpt-4.1-mini' (Show-Filter 'model' @('gpt-5-mini', 'gpt-5.4', 'gpt-4.1-mini', 'text-embedding-3-small'))
    Press gpt Backspace Backspace Backspace DownArrow DownArrow DownArrow Enter
    Check 'filter: erased, arrows' 'text-embedding-3-small' (Show-Filter 'model' @('gpt-5-mini', 'gpt-5.4', 'gpt-4.1-mini', 'text-embedding-3-small'))
    Press zzz Enter Escape; Check 'filter: no match, Esc' '' (Show-Filter 'model' @('gpt-5-mini', 'gpt-5.4'))
    Press RightArrow Enter; Check 'confirm: arrow toggles' $false (Read-Confirm 'sure?' $true)
    Press n; Check 'confirm: n' $false (Read-Confirm 'sure?' $true)
    Press y; Check 'confirm: y' $true (Read-Confirm 'sure?' $false)
    Press Enter; Check 'confirm: Enter keeps the default' $false (Read-Confirm 'sure?' $false)
    Press 'sk-' Backspace '12' Enter; Check 'secret: backspace' 'sk12' (Read-Secret 'key')
    Press Enter; Check 'text: default kept' 'glpi' (Read-Text 'login' 'glpi')
    Press Backspace Backspace Backspace Backspace 'admin' Enter; Check 'text: default replaced' 'admin' (Read-Text 'login' 'glpi')
    $Lang = ''; $script:UiLang = 'en'; Press DownArrow DownArrow Enter; Select-Language; Check 'language: third choice is French' 'fr' $script:UiLang
    $Lang = ''; $script:UiLang = 'pt'; Press Enter; Select-Language; Check 'language: the detected one is the default' 'pt' $script:UiLang
    $Lang = 'en'; $script:UiLang = 'en'; Select-Language; Check 'language: -Lang skips the question' 'en' $script:UiLang

    # Interactive AI configuration: configure now? (Enter = yes) - Anthropic (2 downs) - key typed
    # - model filter "5.4" - personalize? y - organization - English (2 downs) - costs? Enter = no
    New-FakeGlpi
    $script:Glpi = $TestGlpi
    $script:PhpExe = $TestPhp
    New-Item -ItemType Directory -Path (Join-Path $TestGlpi 'plugins\ticketassistant') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $TestRoot 'setup.php') -Destination (Join-Path $TestGlpi 'plugins\ticketassistant')
    [void](Invoke-Capture $TestPhp @('bin\console', 'plugin:install', '--username=glpi', 'ticketassistant') $TestGlpi)
    [void](Invoke-Capture $TestPhp @('bin\console', 'plugin:activate', 'ticketassistant') $TestGlpi)
    Press Enter DownArrow DownArrow Enter 'sk-ant-xyz' Enter '5.4' Enter y 'ACME desk' Enter DownArrow DownArrow Enter Enter
    Set-AiConnection
    $state = Get-FakeState
    Check 'configure: provider' 'anthropic' $state.config.provider
    Check 'configure: key received intact' 'sk-ant-xyz' $state.key
    Check 'configure: model' 'gpt-5.4' $state.config.model
    Check 'configure: enabled' 1 $state.config.enabled
    Check 'configure: organization' 'ACME desk' $state.config.organization_name
    Check 'configure: language' 'en_GB' $state.config.response_language
    Check 'configure: test passed' 'ok' $script:TestResult
    Check 'configure: every key consumed' 0 $script:Keys.Count
} finally {
    Remove-Item -LiteralPath $TestWork -Recurse -Force -ErrorAction SilentlyContinue
}

[Console]::Out.WriteLine("$($script:Fails) failure(s)")
exit [int]($script:Fails -gt 0)
