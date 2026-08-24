$StartDir = ($pwd).Path
$workDir = Split-Path -parent $StartDir

$indexFile = -Join($workDir, '\index.php')
$excludeFile = -Join($workDir, '\tools\pack.exclude')
$TRUE_FALSE=(Test-Path $indexFile)
if ($TRUE_FALSE -ne "True") {
	$indexFile = -Join($workDir, '\Plugin.php')
	$TRUE_FALSE=(Test-Path $indexFile)
	if ($TRUE_FALSE -ne "True") {
		Write-Host Do Nothing
		Exit
	}
}
$string = Get-Content $indexFile | Select-String -Pattern "@package" -SimpleMatch | select-object -First 1
$package = $string.line.split(" ")[3]
$string = Get-Content $indexFile | Select-String -Pattern "@version" -SimpleMatch | select-object -First 1
$version = $string.line.split(" ")[3]
$stamp = Get-Date -Format 'yyyyMMdd'
$archiveName = "$($package)-$($version)-$($stamp).zip"
$excludeList = @(Get-Content -Path $excludeFile | Where-Object { $_.Trim() -ne '' })
$packDir = Join-Path $workDir 'pack'
$archivePath = Join-Path $packDir $archiveName
$tempArchivePath = "$archivePath.tmp"

# System.IO.Compression is available in Windows PowerShell 5.1/.NET Framework 4.5+.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
New-Item -ItemType Directory -Path $packDir -Force | Out-Null
Remove-Item -ErrorAction Ignore $archivePath, $tempArchivePath

function Test-PackExcluded([string] $relativePath) {
	$path = $relativePath.Replace('/', '\').TrimStart('\')
	foreach ($rawRule in $excludeList) {
		$rule = $rawRule.Trim().Replace('/', '\').TrimStart('\')
		if ($rule -eq '') { continue }
		if ($rule.EndsWith('\*')) {
			$directory = $rule.Substring(0, $rule.Length - 2).TrimEnd('\')
			if ($path -eq $directory -or $path.StartsWith("$directory\", [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
		} elseif ($path -like $rule -or $path -like "$rule\*") {
			return $true
		}
	}
	return $false
}

$zip = [System.IO.Compression.ZipFile]::Open($tempArchivePath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
	Get-ChildItem -LiteralPath $workDir -File -Recurse | ForEach-Object {
		# Path.GetRelativePath is unavailable in Windows PowerShell 5.1.
		$relative = $_.FullName.Substring($workDir.Length).TrimStart('\').Replace('\', '/')
		if (-not (Test-PackExcluded $relative)) {
			$entryName = "$package/$relative"
			[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
		}
	}
} finally {
	$zip.Dispose()
}
Move-Item -LiteralPath $tempArchivePath -Destination $archivePath -Force
