$ErrorActionPreference = "Stop"

Write-Host "Preparing database (migrate + seed) ..."
docker compose exec app php artisan migrate --force | Out-Null
docker compose exec app php artisan db:seed --force | Out-Null

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$reportDate = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd")
$resultDir = "storage/k6/$timestamp"
New-Item -ItemType Directory -Force -Path $resultDir | Out-Null

$tests = @(
    "req1-race-before",
    "req1-race-after",
    "req2-capacity-before",
    "req2-capacity-after",
    "req3-queue-before",
    "req3-queue-after",
    "req4-batch-before",
    "req4-batch-after",
    "req5-load-before",
    "req5-load-after",
    "req6-cache-before",
    "req6-cache-after",
    "req7-lock-before",
    "req7-lock-after",
    "req8-acid-before",
    "req8-acid-after",
    "req9-stress-before",
    "req9-stress-after",
    "req10-bench-before",
    "req10-bench-after"
)

foreach ($test in $tests) {
    Write-Host "Running $test (REPORT_DATE=$reportDate) ..."
    docker compose --profile test run --rm `
        -e "REPORT_DATE=$reportDate" `
        --entrypoint sh k6 `
        -c "mkdir -p /results/$timestamp && k6 run /scripts/$test.js --summary-export /results/$timestamp/$test.json"
}

Write-Host "Results saved to $resultDir"
