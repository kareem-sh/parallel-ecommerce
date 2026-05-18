$ErrorActionPreference = "Stop"

$date = Get-Date -Format "yyyyMMdd-HHmmss"
$resultDir = "storage/k6/results/$date"
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
    "req5-load-after"
)

foreach ($test in $tests) {
    Write-Host "Running $test ..."
    docker compose --profile test run --rm k6 run "/scripts/$test.js" --summary-export "/results/results/$date/$test.json"
}

Write-Host "Results saved to $resultDir"
