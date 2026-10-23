$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$mainFile = Join-Path $repoRoot 'copy-post.php'
$mainSource = Get-Content -LiteralPath $mainFile -Raw

if ($mainSource -notmatch '(?m)^\s*\*\s*Version:\s*(?<version>[0-9]+(?:\.[0-9]+){1,3})\s*$') {
    throw 'Could not read a numeric plugin version from copy-post.php.'
}

$version = $Matches.version
$distDir = Join-Path $repoRoot 'dist'
$archivePath = Join-Path $distDir "copy-post-$version.zip"

$files = @(
    'copy-post.php',
    'readme.txt',
    'uninstall.php',
    'includes/class-plugin.php',
    'includes/class-settings.php',
    'includes/class-sites.php',
    'includes/class-media.php',
    'includes/class-remapper.php',
    'includes/class-copier.php',
    'includes/class-admin.php',
    'includes/class-ajax.php',
    'includes/class-cli.php',
    'includes/class-updater.php',
    'templates/copy-panel.php',
    'assets/css/admin.css',
    'assets/js/admin.js'
)

$optionalFiles = @(
    'languages/copy-post.pot'
)

foreach ($relativePath in $files) {
    if (-not (Test-Path -LiteralPath (Join-Path $repoRoot $relativePath) -PathType Leaf)) {
        throw "Required release file is missing: $relativePath"
    }
}

New-Item -ItemType Directory -Path $distDir -Force | Out-Null

if (Test-Path -LiteralPath $archivePath) {
    Remove-Item -LiteralPath $archivePath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::Open(
    $archivePath,
    [System.IO.Compression.ZipArchiveMode]::Create
)

try {
    $releaseFiles = @($files)

    foreach ($relativePath in $optionalFiles) {
        if (Test-Path -LiteralPath (Join-Path $repoRoot $relativePath) -PathType Leaf) {
            $releaseFiles += $relativePath
        }
    }

    foreach ($relativePath in $releaseFiles) {
        $sourcePath = Join-Path $repoRoot $relativePath
        $entryName = 'copy-post/' + ($relativePath -replace '\\', '/')

        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $sourcePath,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $archive.Dispose()
}

Write-Output "Created $archivePath"
