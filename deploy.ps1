<#
  deploy.ps1 - build Nova and deploy it to ai.sevensmiletourandticket.com over FTP.

  Unlike the main ContactRate repo's deploy script, this one deploys the backend too —
  Nova owns its whole docroot, so there is no live PHP here that a bad upload could break.

  What it touches on the server (docroot = /ai.sevensmiletourandticket.com):
    * index.html and every other top-level file vite emits into dist/  -> overwritten
    * assets/                                                          -> mirrored
        (stale hashed files inside assets/ are deleted; nothing else is ever deleted)
    * api/*.php                                                        -> overwritten
        EXCEPT api/config.local.php, which holds the database password, JWT signing
        key, and Anthropic API key. That file is created once on the server by
        scripts/make-server-config.ps1 and is never overwritten by a deploy.

  Credentials come from the main repo's gitignored deploy.secret.ps1.

  Usage:
    ./deploy.ps1 -DryRun      # print every action, write nothing (do this first)
    ./deploy.ps1              # build + deploy
    ./deploy.ps1 -SkipBuild   # deploy the existing dist/ without rebuilding
    ./deploy.ps1 -ApiOnly     # push api/ only, skip the frontend entirely
#>
[CmdletBinding()]
param(
  [switch]$DryRun,
  [switch]$SkipBuild,
  [switch]$ApiOnly
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

# Credentials live with the main repo; only the docroot differs for this project.
$secret = 'D:\SevenSmile\WebSevenSmile\contactrate-web-sevensmile\deploy.secret.ps1'
if (-not (Test-Path $secret)) { throw "credentials not found: $secret" }
. $secret
if (-not $DeployPassword) { throw "deploy.secret.ps1: `$DeployPassword is empty." }

$FtpHost = $DeployHost
$FtpUser = $DeployUser
$FtpPass = $DeployPassword
$Docroot = '/ai.sevensmiletourandticket.com'

# api/config.local.php carries every secret this app has. A deploy must never
# overwrite the server's copy with a developer's local one — the local file points
# at the database over the public internet, the server's points at localhost.
$NeverUpload = @('config.local.php')

# ---------------------------------------------------------------- FTP plumbing

function New-FtpRequest([string]$remotePath, [string]$method) {
  $req = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost" + $remotePath)
  $req.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
  $req.Method = $method
  $req.UsePassive = $true; $req.UseBinary = $true; $req.KeepAlive = $false; $req.Timeout = 60000
  return $req
}

# Deletes are only ever allowed inside an assets/ folder. A bug in the stale-file
# logic therefore cannot remove the backend or anything else.
function Assert-Deletable([string]$remotePath) {
  $p = $remotePath.ToLower()
  if ($p -match '/api(/|$)') { throw "SAFETY GUARD: refusing to delete under api/: $remotePath" }
  if ($p -notmatch '/assets/') { throw "SAFETY GUARD: deletes are only allowed inside /assets/. Blocked: $remotePath" }
}

function Get-FtpChildNames([string]$remoteDir) {
  try {
    $resp = (New-FtpRequest $remoteDir ([System.Net.WebRequestMethods+Ftp]::ListDirectory)).GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $names = $reader.ReadToEnd() -split "`r?`n" | Where-Object { $_ -and $_ -notmatch '^\.{1,2}$' }
    $reader.Close(); $resp.Close()
    return $names | ForEach-Object { Split-Path $_ -Leaf }
  } catch { return @() }
}

function Test-FtpDir([string]$remoteDir) {
  try {
    $resp = (New-FtpRequest $remoteDir ([System.Net.WebRequestMethods+Ftp]::ListDirectory)).GetResponse()
    $resp.Close(); return $true
  } catch { return $false }
}

function New-FtpDir([string]$remoteDir, [switch]$DryRun) {
  if (Test-FtpDir $remoteDir) { return }
  Write-Host "  mkdir  $remoteDir" -ForegroundColor DarkGray
  if ($DryRun) { return }
  try {
    $resp = (New-FtpRequest $remoteDir ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)).GetResponse()
    $resp.Close()
  } catch {
    # 550 here means it already exists (a race with Test-FtpDir); anything else is real.
    if ($_.Exception.Message -notmatch '550') { throw }
  }
}

function Send-FtpFile([string]$localFile, [string]$remotePath, [switch]$DryRun) {
  $size = (Get-Item $localFile).Length
  Write-Host ("  put    {0}  ({1:N0} B)" -f $remotePath, $size) -ForegroundColor DarkGray
  if ($DryRun) { return }
  $req = New-FtpRequest $remotePath ([System.Net.WebRequestMethods+Ftp]::UploadFile)
  $bytes = [System.IO.File]::ReadAllBytes($localFile)
  $req.ContentLength = $bytes.Length
  $stream = $req.GetRequestStream()
  $stream.Write($bytes, 0, $bytes.Length)
  $stream.Close()
  $resp = $req.GetResponse(); $resp.Close()
}

function Remove-FtpFile([string]$remotePath, [switch]$DryRun) {
  Assert-Deletable $remotePath
  Write-Host "  delete $remotePath" -ForegroundColor DarkYellow
  if ($DryRun) { return }
  $resp = (New-FtpRequest $remotePath ([System.Net.WebRequestMethods+Ftp]::DeleteFile)).GetResponse()
  $resp.Close()
}

function Send-FtpTree([string]$localDir, [string]$remoteDir, [switch]$DryRun, [string[]]$Exclude = @()) {
  New-FtpDir $remoteDir -DryRun:$DryRun
  foreach ($item in Get-ChildItem $localDir) {
    if ($Exclude -contains $item.Name) {
      Write-Host "  skip   $remoteDir/$($item.Name)  (excluded)" -ForegroundColor DarkCyan
      continue
    }
    if ($item.PSIsContainer) {
      Send-FtpTree $item.FullName "$remoteDir/$($item.Name)" -DryRun:$DryRun -Exclude $Exclude
    } else {
      Send-FtpFile $item.FullName "$remoteDir/$($item.Name)" -DryRun:$DryRun
    }
  }
}

# --------------------------------------------------------------------- deploy

Write-Host ("==> Target: ftp://{0}{1}  {2}" -f $FtpHost, $Docroot, $(if ($DryRun) { '[DRY RUN]' } else { '' })) -ForegroundColor Cyan

if (-not $ApiOnly) {
  if (-not $SkipBuild) {
    Write-Host "==> bun run build" -ForegroundColor Cyan
    Push-Location $root
    try {
      # vite writes its banner to stderr. Under $ErrorActionPreference = 'Stop',
      # PowerShell 5.1 turns any native stderr output into a terminating
      # NativeCommandError even on a successful build - so judge it by exit code.
      $prev = $ErrorActionPreference
      $ErrorActionPreference = 'Continue'
      try {
        & bun run build 2>&1 | ForEach-Object { Write-Host "    $_" }
      } finally {
        $ErrorActionPreference = $prev
      }
      if ($LASTEXITCODE -ne 0) { throw "build failed ($LASTEXITCODE)" }
    } finally { Pop-Location }
  }

  $distDir = Join-Path $root 'dist'
  if (-not (Test-Path $distDir)) { throw "dist/ not found - run a build first." }

  # Remove stale hashed assets so the folder doesn't grow forever.
  $localAssets = Join-Path $distDir 'assets'
  $localNames = if (Test-Path $localAssets) { (Get-ChildItem $localAssets).Name } else { @() }
  $remoteNames = Get-FtpChildNames "$Docroot/assets"
  $stale = $remoteNames | Where-Object { $_ -and ($localNames -notcontains $_) }

  Write-Host ("==> assets/: {0} local, {1} remote, {2} stale" -f $localNames.Count, $remoteNames.Count, @($stale).Count) -ForegroundColor Cyan
  foreach ($name in $stale) { Remove-FtpFile "$Docroot/assets/$name" -DryRun:$DryRun }

  Write-Host "==> Uploading dist/" -ForegroundColor Cyan
  Send-FtpTree $distDir $Docroot -DryRun:$DryRun
}

$apiDir = Join-Path $root 'api'
if (Test-Path $apiDir) {
  Write-Host "==> Uploading api/" -ForegroundColor Cyan
  Send-FtpTree $apiDir "$Docroot/api" -DryRun:$DryRun -Exclude $NeverUpload
} else {
  Write-Host "==> no api/ directory - skipping backend" -ForegroundColor Yellow
}

Write-Host ("==> Done{0}" -f $(if ($DryRun) { ' [DRY RUN - nothing was written]' } else { '' })) -ForegroundColor Green
Write-Host "    https://ai.sevensmiletourandticket.com/" -ForegroundColor Green
