param(
    [Parameter(Mandatory = $true)]
    [string] $Test
)

$ErrorActionPreference = "Stop"

$scriptName = if ($Test.EndsWith(".js")) { $Test } else { "$Test.js" }
$exportName = $scriptName -replace '\.js$', ''
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$reportDate = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd")
$resultDir = "storage/k6/results/$timestamp"
New-Item -ItemType Directory -Force -Path $resultDir | Out-Null

Write-Host "Running $scriptName (REPORT_DATE=$reportDate) ..."

docker compose --profile test run --rm `
    -e "REPORT_DATE=$reportDate" `
    --entrypoint sh k6 `
    -c "mkdir -p /results/$timestamp && k6 run /scripts/$scriptName --summary-export /results/$timestamp/$exportName.json"

Write-Host "Summary saved to $resultDir/$exportName.json"
