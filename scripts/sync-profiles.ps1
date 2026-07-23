<#
  sync-profiles.ps1 - push database/profiles.json into ai_user_profiles.

  Credentials are loaded from the main repo's gitignored deploy.secret.ps1 and passed
  to PHP through the environment, so they never appear on a command line or in history.
  sync-profiles.php writes one column of one ai_ table and nothing else.

  Changes take effect on the next question Nova is asked. No deploy, no restart.

  Usage:
    ./scripts/sync-profiles.ps1 -DryRun
    ./scripts/sync-profiles.ps1
#>
param(
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

try {
  if ($DryRun) {
    & 'C:\xampp\php\php.exe' "$PSScriptRoot\sync-profiles.php" '--dry-run'
  } else {
    & 'C:\xampp\php\php.exe' "$PSScriptRoot\sync-profiles.php"
  }
} finally {
  Remove-Item Env:DBW -ErrorAction SilentlyContinue
}
