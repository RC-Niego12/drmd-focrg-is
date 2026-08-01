param(
    [string]$ServerIp = '172.26.152.208',
    [string]$HostName = 'drmd-focrg-is.test',
    [string]$ValetCaCertificatePath
)

$ErrorActionPreference = 'Stop'

$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    throw 'Please run this script in PowerShell as Administrator on the client PC.'
}

$hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$hostsContent = Get-Content -LiteralPath $hostsPath -Raw
$entry = "$ServerIp $HostName"

if ($hostsContent -match "(?m)^\s*\d{1,3}(\.\d{1,3}){3}\s+$([regex]::Escape($HostName))\s*$") {
    $updated = $hostsContent -replace "(?m)^\s*\d{1,3}(\.\d{1,3}){3}\s+$([regex]::Escape($HostName))\s*$", $entry
    Set-Content -LiteralPath $hostsPath -Value $updated -NoNewline
} elseif ($hostsContent -notmatch "(?m)\s$([regex]::Escape($HostName))\s*$") {
    Add-Content -LiteralPath $hostsPath -Value "`r`n$entry"
}

ipconfig /flushdns | Out-Null

if ($ValetCaCertificatePath -and (Test-Path -LiteralPath $ValetCaCertificatePath)) {
    Import-Certificate -FilePath $ValetCaCertificatePath -CertStoreLocation Cert:\LocalMachine\Root | Out-Null
    Write-Host 'DROMIS HTTPS certificate authority was installed as trusted.'
} else {
    Write-Host 'No certificate path supplied. If the browser shows a certificate warning, install the Herd/Valet CA certificate on this client.'
}

Write-Host "Client is configured. Open: https://$HostName"
