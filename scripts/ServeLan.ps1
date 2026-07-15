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
Write-Host "Serving DRIMS on http://$($env:COMPUTERNAME):$Port (current address: $address; OAuth loopback enabled)"
$router = Join-Path $PSScriptRoot '..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Push-Location (Join-Path $PSScriptRoot '..\public')
try {
    # Listen on every local interface. Caraga Connect client 158 currently has
    # 127.0.0.1:8010 registered as its callback, while LAN users reach this
    # process through the computer name/current IPv4 address.
    & php -S "0.0.0.0:$Port" $router
} finally {
    Pop-Location
}
