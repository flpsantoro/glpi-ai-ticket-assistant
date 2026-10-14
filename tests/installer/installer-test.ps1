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
