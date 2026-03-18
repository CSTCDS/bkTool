<#
PowerShell deploy script using WinSCP .NET assembly.
Place WinSCP (https://winscp.net) on the machine. This script will try common install paths.
It uploads the contents of C:\Perso\LWS\bkTool\mon-site\public to remote path '/'.

Note: this script contains the password provided — keep it safe and delete after use.
#>

$localPath = 'C:\Perso\LWS\bkTool\mon-site\public\'
$remotePath = '/'
$sftpHost = 'ftp.amapgeste.fr'
$sftpUser = '2707990xENpNp'
$sftpPass = 'mS9_sktjmFzmb8v'
$port = 22

$dllPaths = @(
    "C:\\Program Files (x86)\\WinSCP\\WinSCPnet.dll",
    "C:\\Program Files\\WinSCP\\WinSCPnet.dll"
)

$dll = $dllPaths | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $dll) {
    Write-Host "WinSCP .NET assembly not found. Please install WinSCP (https://winscp.net) and retry." -ForegroundColor Yellow
    Write-Host "Files created: deploy-winscp.txt (use with winscp.com) and this deploy.ps1."
    exit 2
}

[Reflection.Assembly]::LoadFile($dll) | Out-Null

$sessionOptions = New-Object WinSCP.SessionOptions -Property @{ 
    Protocol = [WinSCP.Protocol]::Sftp; 
    HostName = $sftpHost; 
    PortNumber = $port; 
    UserName = $sftpUser; 
    Password = $sftpPass; 
    # For convenience accept any host key (NOT recommended for production)
    GiveUpSecurityAndAcceptAnySshHostKey = $true
}

$session = New-Object WinSCP.Session
try {
    $session.Open($sessionOptions)

    $transferOptions = New-Object WinSCP.TransferOptions
    $transferOptions.TransferMode = [WinSCP.TransferMode]::Binary

    Write-Host "Uploading $localPath -> ${host}:$remotePath ..."
    $transferResult = $session.PutFiles(($localPath + "*"), $remotePath, $false, $transferOptions)
    $transferResult.Check()

    foreach ($transfer in $transferResult.Transfers) {
        Write-Host "Uploaded: $($transfer.FileName)"
    }
    Write-Host "Upload complete." -ForegroundColor Green
}
catch {
    Write-Host "Error during upload: $_" -ForegroundColor Red
    exit 1
}
finally {
    $session.Dispose()
}
