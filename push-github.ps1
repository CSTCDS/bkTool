param(
    [string]$RemoteUrl = 'https://github.com/CSTCDS/bkTool.git'
)

Write-Host "Push helper: remote = $RemoteUrl"

# Check git availability
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Error "git not found in PATH. Install Git and ensure it's available in this shell."
    exit 2
}

if (-not (Test-Path .git)) {
    & git init
    if ($LASTEXITCODE -ne 0) { Write-Error "git init failed"; exit 1 }
}

# Add files
& git add .
if ($LASTEXITCODE -ne 0) { Write-Host "git add returned non-zero (may be no changes). Continuing..." }

# Create initial commit if none
$hasCommit = $false
try { & git rev-parse --verify HEAD > $null 2>&1; if ($LASTEXITCODE -eq 0) { $hasCommit = $true } } catch {}
if (-not $hasCommit) {
    & git commit -m "Initial bkTool project scaffold" 2>$null
    if ($LASTEXITCODE -ne 0) { Write-Host "No changes to commit or commit failed; continuing..." }
}

# Ensure branch main
& git branch -M main 2>$null

# Set or update remote
$remotes = & git remote
if ($remotes -notmatch 'origin') { & git remote add origin $RemoteUrl } else { & git remote set-url origin $RemoteUrl }

# Push
Write-Host "Pushing to origin main..."
& git push -u origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "Push failed. Trying push HEAD:main..."
    & git push origin HEAD:main -u
    if ($LASTEXITCODE -ne 0) { Write-Error "Push failed again."; exit 1 }
}

Write-Host "Push completed."
