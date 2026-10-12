<#
.SYNOPSIS
    Runs the OSPOS verified database backup from Windows Task Scheduler.

.DESCRIPTION
    Safely resolves the OSPOS project directory (by default the parent of this
    script's tools/ folder) and invokes:

        <php> <project>\spark db:backup --verify-restore --replicate --prune

    with all command-line arguments passed as an array (paths containing
    spaces are supported). The script exits with the Spark process exit code so
    Task Scheduler can detect failures; non-zero codes are: 1 = Spark reported
    failure, 2 = PHP could not be started, 3 = spark script not found, 4 = PHP
    executable not found.

    This runner contains no database or network credentials and no
    machine-specific paths. Credentials always come from the application's
    .env (or the operating system's access to the configured replica share).

.PARAMETER PhpPath
    Path to the PHP CLI executable, e.g. 'C:\Program Files\PHP\8.2\php.exe'.
    Defaults to 'php', i.e. look it up on PATH.

.PARAMETER ProjectDir
    Path to the OSPOS project root (the folder containing the spark script).
    Defaults to the parent of this script.

.PARAMETER SparkArgs
    Arguments forwarded to `php spark ...`, one per element. Defaults to
    @('db:backup','--verify-restore','--replicate','--prune').

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup-run.ps1

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup-run.ps1 `
        -PhpPath 'C:\Program Files\PHP\8.2\php.exe' `
        -ProjectDir 'D:\stores\north-ospos'

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup-run.ps1 `
        -SparkArgs @('db:backup','--verify-restore')
#>
[CmdletBinding()]
param(
    [string]$PhpPath = 'php',
    [string]$ProjectDir = '',
    [string[]]$SparkArgs = @('db:backup', '--verify-restore', '--replicate', '--prune')
)

function Write-ErrorExit([string]$Message, [int]$Code) {
    Write-Host "[backup-run] ERROR: $Message" -ForegroundColor Red
    exit $Code
}

# Resolve the project directory safely (may contain spaces).
if ([string]::IsNullOrWhiteSpace($ProjectDir)) {
    $ProjectDir = Split-Path -Parent $PSScriptRoot
}
$ProjectDir = [System.IO.Path]::GetFullPath($ProjectDir)

$SparkFile = Join-Path $ProjectDir 'spark'

if (-not (Test-Path -LiteralPath $SparkFile -PathType Leaf)) {
    Write-ErrorExit "OSPOS spark script not found at '$SparkFile'." 3
}

# Allow the PHP executable to be named (PATH lookup) or a full path with spaces.
if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue) -and -not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    Write-ErrorExit "PHP executable not found: '$PhpPath'. Pass -PhpPath 'C:\...\php.exe' when PHP is not on PATH." 4
}

Write-Host "[backup-run] started at $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')."
Write-Host "[backup-run] running: $PhpPath $SparkFile $($SparkArgs -join ' ')"

try {
    & $PhpPath $SparkFile @SparkArgs
} catch {
    Write-ErrorExit "Failed to start PHP: $($_.Exception.Message)" 2
}

$exitCode = $LASTEXITCODE

if ($null -eq $exitCode) {
    Write-ErrorExit 'PHP exited without reporting an exit code.' 2
}

Write-Host "[backup-run] finished at $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') with exit code $exitCode."
exit $exitCode