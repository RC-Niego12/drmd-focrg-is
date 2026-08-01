param(
    [int]$Port = 8010
)

$ErrorActionPreference = 'Stop'
$configuration = Get-NetIPConfiguration |
    Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.IPv4Address.IPAddress -notlike '169.254.*' } |
    Select-Object -First 1

if ($null -eq $configuration) {
    throw 'No active LAN/Wi-Fi IPv4 connection with a default gateway was found.'
}

$address = $configuration.IPv4Address.IPAddress
$computerName = $env:COMPUTERNAME

Write-Host "Serving DROMIS on http://$computerName`:$Port"
Write-Host "LAN fallback URL: http://$address`:$Port"
Write-Host "Keep this window open while other users are accessing the system."
$router = Join-Path $PSScriptRoot '..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Push-Location (Join-Path $PSScriptRoot '..\public')
try {
    # Listen on every local interface. LAN users can reach this process through
    # the Windows computer name or current IPv4 address.
    & php -S "0.0.0.0:$Port" $router
} finally {
    Pop-Location
}
