<#
  ftp-ls.ps1 - list a remote FTP directory. Read-only; nothing is written or deleted.

  Used to find the docroot of a newly created subdomain before deploying to it.

  Usage:
    ./scripts/ftp-ls.ps1            # list the FTP account root
    ./scripts/ftp-ls.ps1 /ai.sevensmiletourandticket.com
#>
param(
  [string]$Path = '/'
)

$ErrorActionPreference = 'Stop'

. 'D:\SevenSmile\WebSevenSmile\contactrate-web-sevensmile\deploy.secret.ps1'

$uri = "ftp://$DeployHost" + $Path
$req = [System.Net.FtpWebRequest]::Create($uri)
$req.Credentials = New-Object System.Net.NetworkCredential($DeployUser, $DeployPassword)
$req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails
$req.UsePassive = $true
$req.UseBinary = $true
$req.KeepAlive = $false
$req.Timeout = 60000

try {
  $resp = $req.GetResponse()
  $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
  Write-Host "==> $uri" -ForegroundColor Cyan
  $reader.ReadToEnd()
  $reader.Close()
  $resp.Close()
} catch {
  Write-Host "FAILED: $($_.Exception.Message)" -ForegroundColor Red
  exit 1
}
