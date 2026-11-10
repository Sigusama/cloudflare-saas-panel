# SPDX-License-Identifier: AGPL-3.0-only
# Produce a clean source archive without local settings, credentials or runtimes.
[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$version = (Get-Content -LiteralPath (Join-Path $projectRoot 'VERSION') -Raw).Trim()
if ($version -notmatch '^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)?$') { throw 'Invalid VERSION' }
$rootFiles = @('.dockerignore','.editorconfig','.env.example','.gitattributes','.gitignore','README.md','README.en.md','CHANGELOG.md','CONTRIBUTING.md','LICENSE','NOTICE','SECURITY.md','VERSION','Dockerfile','compose.yaml','compose.build.yaml','storage/.gitignore')
$directories = @('.github','app','bin','database','docker','docs','public','resources','scripts','tests')
$files = @($rootFiles | ForEach-Object { Get-Item -LiteralPath (Join-Path $projectRoot $_) })
foreach ($directory in $directories) {
    $directoryPath = Join-Path $projectRoot $directory
    $links = @(Get-ChildItem -LiteralPath $directoryPath -Force -Recurse | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
    if ($links.Count) { throw "Refusing linked paths in $directory" }
    $files += @(Get-ChildItem -LiteralPath $directoryPath -Force -Recurse -File)
}
$files = @($files | Sort-Object FullName -Unique)
$releaseDir = Join-Path $projectRoot 'release'
New-Item -ItemType Directory -Path $releaseDir -Force | Out-Null
$packageName = "cloudflare-saas-panel-$version"
$zipPath = Join-Path $releaseDir "$packageName.zip"
Add-Type -AssemblyName System.IO.Compression
$stream = [IO.File]::Open($zipPath,[IO.FileMode]::Create)
$archive = [IO.Compression.ZipArchive]::new($stream,[IO.Compression.ZipArchiveMode]::Create)
$utf8 = [Text.UTF8Encoding]::new($false)
$manifest = @()
try {
    foreach ($file in $files) {
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Refusing linked file $($file.Name)" }
        $relative = $file.FullName.Substring($projectRoot.Length + 1).Replace('\','/')
        if ($relative -match '(?i)(^|/)(work|release|vendor|node_modules|\.git)(/|$)|\.(sql\.gz|bak|log|exe|dll|zip)$|(^|/)\.env($|\.(?!example$))|^storage/(?!\.gitignore$)') { throw "Unexpected private/build artifact: $relative" }
        $bytes = [IO.File]::ReadAllBytes($file.FullName)
        if ($file.Extension -in @('.php','.js','.css','.md','.sql','.sh','.yml','.yaml','.conf','.ini','.ps1','.json') -or $relative -in $rootFiles -or $file.Name -eq '.htaccess') {
            $bytes = $utf8.GetBytes($utf8.GetString($bytes).TrimStart([char]0xFEFF).Replace("`r`n","`n"))
        }
        $entry = $archive.CreateEntry("$packageName/$relative",[IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::new(2026,11,9,0,0,0,[TimeSpan]::Zero)
        $entryStream = $entry.Open()
        try { $entryStream.Write($bytes,0,$bytes.Length) } finally { $entryStream.Dispose() }
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $digest = ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
        $manifest += "$digest  $relative"
    }
} finally { $archive.Dispose(); $stream.Dispose() }
[IO.File]::WriteAllLines((Join-Path $releaseDir "$packageName.files.sha256"),$manifest,$utf8)
$zipHash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText((Join-Path $releaseDir "$packageName.zip.sha256"),"$zipHash  $packageName.zip`n",$utf8)
[pscustomobject]@{Archive=$zipPath;Files=$files.Count;Bytes=(Get-Item -LiteralPath $zipPath).Length;SHA256=$zipHash}
