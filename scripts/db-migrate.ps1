<#
  db-migrate.ps1 - apply an additive migration to the production contactrate database.

  db-migrate.php refuses anything that is not a CREATE TABLE IF NOT EXISTS / CREATE INDEX /
  ALTER TABLE ... ADD against a table prefixed `ai_`. The existing ContactRate tables
  cannot be modified through this path.

  Usage:
    ./scripts/db-migrate.ps1 database/001_ai_tables.sql -DryRun   # validate + print, write nothing
    ./scripts/db-migrate.ps1 database/001_ai_tables.sql           # apply
#>
param(
  [Parameter(Mandatory = $true, Position = 0)]
  [string]$File,

  [switch]$DryRun
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

$resolved = (Resolve-Path $File).Path

try {
  if ($DryRun) {
    & 'C:\xampp\php\php.exe' "$PSScriptRoot\db-migrate.php" $resolved '--dry-run'
  } else {
    & 'C:\xampp\php\php.exe' "$PSScriptRoot\db-migrate.php" $resolved
  }
} finally {
  Remove-Item Env:DBW -ErrorAction SilentlyContinue
}
