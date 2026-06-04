param(
    [Parameter(Mandatory = $true)]
    [string] $Test,

    [string] $InfluxUrl = "http://influxdb:8086/k6"
)

$ErrorActionPreference = "Stop"

if (-not $Test.EndsWith(".js")) {
    $Test = "$Test.js"
}

Write-Host "Running $Test with live Grafana export -> $InfluxUrl"
Write-Host "Open Grafana: http://localhost:3000 (admin / admin)"

docker compose --profile test --profile observability run --rm k6 run "/scripts/$Test" --out "influxdb=$InfluxUrl"
