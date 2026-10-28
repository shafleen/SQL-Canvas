param(
    [string]$Version = '1.0.0'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$dist = Join-Path $projectRoot 'dist'
$stage = Join-Path $dist ("sql-canvas-v{0}" -f $Version)
$archive = "$stage.zip"

if ($Version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$') {
    throw 'Version must use semantic version syntax, for example 1.0.0 or 1.0.0-rc1.'
}

New-Item -ItemType Directory -Force -Path $dist | Out-Null
$resolvedDist = (Resolve-Path $dist).Path
if (-not $stage.StartsWith($resolvedDist + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Refusing to stage outside the project dist directory.'
}
if (Test-Path -LiteralPath $stage) {
    Remove-Item -LiteralPath $stage -Recurse -Force
}
if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}

New-Item -ItemType Directory -Path $stage | Out-Null
$directories = @('bootstrap', 'config', 'deploy', 'docs', 'public', 'src', 'templates')
foreach ($directory in $directories) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $directory) -Destination $stage -Recurse
}

$files = @('.htaccess', 'CHANGELOG.md', 'CONTRIBUTING.md', 'LICENSE', 'README.md')
foreach ($file in $files) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination $stage
}

$privateConfig = Join-Path $stage 'config\config.php'
if (Test-Path -LiteralPath $privateConfig) {
    Remove-Item -LiteralPath $privateConfig -Force
}

Compress-Archive -LiteralPath $stage -DestinationPath $archive -CompressionLevel Optimal
$hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
Set-Content -LiteralPath "$archive.sha256" -Value "$hash  $([IO.Path]::GetFileName($archive))" -Encoding ascii
Remove-Item -LiteralPath $stage -Recurse -Force

Write-Output $archive
Write-Output "SHA256 $hash"
