param(
    [int[]]$Ports = @(443, 6001)
)

$ErrorActionPreference = 'Stop'

$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    throw 'Please run this script in PowerShell as Administrator.'
}

$ruleName = 'DROMIS Herd HTTPS LAN'
$existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
$portList = $Ports -join ','

if ($existing) {
    Set-NetFirewallRule -DisplayName $ruleName -Enabled True -Action Allow -Profile Private
    Set-NetFirewallPortFilter -AssociatedNetFirewallRule $existing -LocalPort $portList
} else {
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $portList -Profile Private | Out-Null
}

Write-Host "Firewall allows DROMIS HTTPS and Socket.IO on TCP ports $portList for Private networks."
