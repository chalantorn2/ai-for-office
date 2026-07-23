<#
  db-query.ps1 - run a read-only query against the production contactrate database.

  Credentials are loaded from the main repo's gitignored deploy.secret.ps1 and passed
  to PHP through the environment, so they never appear on a command line or in history.
  db-query.php rejects anything that is not a single SELECT/SHOW/DESCRIBE/EXPLAIN.

  Usage:
    ./scripts/db-query.ps1 "SELECT id, name FROM hotels ORDER BY name LIMIT 20"
#>
param(
  [Parameter(Mandatory = $true, Position = 0)]
  [string]$Sql,

  # Widen the column cap when a query returns long text (grants, notes, JSON).
  [int]$MaxCol = 40
)

$ErrorActionPreference = 'Stop'

$secret = 'D:\SevenSmile\WebSevenSmile\contactrate-web-sevensmile\deploy.secret.ps1'
if (-not (Test-Path $secret)) { throw "credentials not found: $secret" }
. $secret

$env:DBH = $DbHost
$env:DBP = $DbPort
$env:DBN = $DbName
$env:DBU = $DbUser
$env:DBW = $DbPass
$env:DBQ_MAXCOL = $MaxCol

try {
  & 'C:\xampp\php\php.exe' "$PSScriptRoot\db-query.php" $Sql
} finally {
  Remove-Item Env:DBW -ErrorAction SilentlyContinue
}
