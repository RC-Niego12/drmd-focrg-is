$ErrorActionPreference = 'Stop'

$workspaceRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$hotFile = [System.IO.Path]::GetFullPath((Join-Path $workspaceRoot 'public\hot'))
$expectedHotFile = [System.IO.Path]::GetFullPath('C:\Users\RC\Herd\drmd-focrg-is\public\hot')

if ($hotFile -ne $expectedHotFile) {
    throw "Refusing to remove an unexpected Vite hot file: $hotFile"
}

if (Test-Path -LiteralPath $hotFile) {
    Remove-Item -LiteralPath $hotFile -Force
    Write-Host 'Removed stale Vite hot-file routing so LAN/mobile clients use same-origin built assets.'
}
