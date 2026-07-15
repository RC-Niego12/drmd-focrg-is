param(
    [Parameter(Mandatory = $true)][string]$InputPath,
    [Parameter(Mandatory = $true)][string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$word = $null
$document = $null

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $document = $word.Documents.Open($InputPath, $false, $true)
    [void]$document.Fields.Update()
    foreach ($section in $document.Sections) {
        foreach ($header in $section.Headers) { [void]$header.Range.Fields.Update() }
        foreach ($footer in $section.Footers) { [void]$footer.Range.Fields.Update() }
    }
    [void]$document.Repaginate()
    $document.ExportAsFixedFormat($OutputPath, 17)
    $document.Close($false)
    $document = $null
} finally {
    if ($document -ne $null) {
        $document.Close($false)
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($document)
    }
    if ($word -ne $null) {
        $word.Quit()
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($word)
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
