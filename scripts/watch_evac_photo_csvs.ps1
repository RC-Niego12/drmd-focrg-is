# Watch Downloads for geotag sheet CSV exports and copy into storage/app/geo/ec_photos/
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
$destDir = Join-Path $root 'storage\app\geo\ec_photos'
$downloads = Join-Path $env:USERPROFILE 'Downloads'
New-Item -ItemType Directory -Force -Path $destDir | Out-Null

function Detect-Lgu([string]$path) {
  $sample = ''
  try { $sample = (Get-Content $path -TotalCount 40 -ErrorAction Stop | Out-String) } catch { return $null }
  if ($sample -notmatch '(?i)GDrive\s*Links?|Google\s*Drive|drive\.google') { return $null }
  if ($sample -match '(?i)\bMainit\b') { return 'mainit' }
  if ($sample -match '(?i)\bAlegria\b') { return 'alegria' }
  if ($sample -match '(?i)\bSison\b') { return 'sison' }
  # Fall back on filename
  $name = [IO.Path]::GetFileName($path)
  if ($name -match '(?i)mainit|Hubq318') { return 'mainit' }
  if ($name -match '(?i)alegria|HLJHWK5') { return 'alegria' }
  if ($name -match '(?i)sison|PasvKlz') { return 'sison' }
  return $null
}

Write-Host "Watching $downloads for CSV exports (Mainit / Alegria / Sison)..."
Write-Host "In each Google Sheet: File -> Download -> Comma Separated Values (.csv)"
Write-Host "Destination: $destDir"
Write-Host ""

$deadline = (Get-Date).AddMinutes(20)
$got = @{}
$seenFiles = @{}

while ((Get-Date) -lt $deadline -and $got.Count -lt 3) {
  $files = Get-ChildItem $downloads -File -Filter *.csv -ErrorAction SilentlyContinue |
    Where-Object { $_.LastWriteTime -gt (Get-Date).AddMinutes(-30) } |
    Sort-Object LastWriteTime -Descending

  foreach ($file in $files) {
    if ($seenFiles.ContainsKey($file.FullName)) { continue }
    $seenFiles[$file.FullName] = $true
    $lgu = Detect-Lgu $file.FullName
    if (-not $lgu) { continue }
    if ($got.ContainsKey($lgu)) { continue }

    $dest = Join-Path $destDir "$lgu.csv"
    Copy-Item $file.FullName $dest -Force
    $got[$lgu] = $file.Name
    Write-Host "Captured $lgu <- $($file.Name)"
  }

  if ($got.Count -ge 3) { break }
  Start-Sleep -Seconds 2
}

# Also accept manually placed files already in dest
foreach ($lgu in @('mainit','alegria','sison')) {
  $dest = Join-Path $destDir "$lgu.csv"
  if ((Test-Path $dest) -and -not $got.ContainsKey($lgu)) {
    $got[$lgu] = "$lgu.csv (pre-placed)"
    Write-Host "Found pre-placed $lgu.csv"
  }
}

if ($got.Count -eq 0) {
  Write-Host "No CSVs captured. Save exports as:"
  Write-Host "  $destDir\mainit.csv"
  Write-Host "  $destDir\alegria.csv"
  Write-Host "  $destDir\sison.csv"
  exit 1
}

Write-Host "Merging photos into snapshot..."
Push-Location $root
php scripts/merge_evac_center_photos.php
Pop-Location
