# Run this script in an Administrator PowerShell when the PC joins a new network.
# It preserves https://desktop-lj8f734/ as the single desktop/mobile URL.
$ErrorActionPreference = 'Stop'

$ruleName = 'DROMIS LAN HTTPS'
netsh advfirewall firewall delete rule name="$ruleName" | Out-Null
netsh advfirewall firewall add rule name="$ruleName" dir=in action=allow protocol=TCP localport=80,443 profile=domain,private remoteip=localsubnet | Out-Null
ipconfig /registerdns | Out-Null
Clear-DnsClientCache

Write-Host 'DROMIS LAN access refreshed.' -ForegroundColor Green
Write-Host 'Canonical URL: https://desktop-lj8f734/'
Write-Host 'Mobile CA certificate: public/downloads/DROMIS-LAN-CA.crt'
Write-Host 'If nslookup reports NXDOMAIN, the network DNS/DHCP service has not published this workstation hostname.' -ForegroundColor Yellow
nslookup desktop-lj8f734
