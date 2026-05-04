[CmdletBinding()]
param(
    [switch]$Reimport,
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$sqlPath = Join-Path $projectRoot 'u830768701_porto.sql'
$migrationDir = Join-Path $projectRoot 'migrations'
$phpExe = 'C:\xampp\php\php.exe'
$phpIni = 'C:\xampp\php\php.ini'
$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'

$dbName = 'u830768701_porto'
$dbUser = 'u830768701_radhit'
$dbPass = 'Sofwanr27_'
$serverHost = '127.0.0.1'
$serverUrl = "http://$serverHost`:$Port/"

function Test-RequiredPath {
    param(
        [string]$Path,
        [string]$Label
    )

    if (-not (Test-Path $Path)) {
        throw "$Label tidak ditemukan: $Path"
    }
}

function Get-MySqlArgs {
    $args = @('-u', 'root')

    if ($env:BISMILLAH_MYSQL_ROOT_PASSWORD) {
        $args += "-p$($env:BISMILLAH_MYSQL_ROOT_PASSWORD)"
    }

    return $args
}

function Invoke-MySqlQuery {
    param(
        [string]$Query,
        [string]$Database = ''
    )

    $args = Get-MySqlArgs

    if ($Database) {
        $args += $Database
    }

    $args += @('-N', '-B', '-e', $Query)
    $output = & $mysqlExe @args 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw "MySQL query gagal.`n$output"
    }

    return ($output | Out-String).Trim()
}

function Import-MySqlDump {
    param(
        [string]$Database,
        [string]$FilePath
    )

    $args = Get-MySqlArgs
    $args += $Database

    Get-Content -Raw $FilePath | & $mysqlExe @args

    if ($LASTEXITCODE -ne 0) {
        throw "Import SQL gagal dari: $FilePath"
    }
}

Test-RequiredPath -Path $sqlPath -Label 'SQL dump'
Test-RequiredPath -Path $phpExe -Label 'PHP XAMPP'
Test-RequiredPath -Path $phpIni -Label 'php.ini XAMPP'
Test-RequiredPath -Path $mysqlExe -Label 'MySQL XAMPP'

Set-Location $projectRoot

Write-Host ''
Write-Host '== Bismillah ==' -ForegroundColor Cyan
Write-Host "Project : $projectRoot"
Write-Host "SQL     : $sqlPath"
Write-Host "Local   : $serverUrl"

Write-Host ''
Write-Host 'Menyiapkan database lokal...' -ForegroundColor Yellow

$bootstrapSql = @"
CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass';
GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'localhost';
FLUSH PRIVILEGES;
"@

Invoke-MySqlQuery -Query $bootstrapSql | Out-Null

$tableCount = [int](Invoke-MySqlQuery -Query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$dbName';")

if ($Reimport) {
    Write-Host 'Mode reimport aktif: database akan di-reset dari dump SQL.' -ForegroundColor Yellow
    Invoke-MySqlQuery -Query "DROP DATABASE IF EXISTS `$dbName`;" | Out-Null
    Invoke-MySqlQuery -Query "CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" | Out-Null
    Import-MySqlDump -Database $dbName -FilePath $sqlPath
    Write-Host 'Reimport database selesai.' -ForegroundColor Green
}
elseif ($tableCount -eq 0) {
    Write-Host 'Database masih kosong, import dump final...' -ForegroundColor Yellow
    Import-MySqlDump -Database $dbName -FilePath $sqlPath
    Write-Host 'Import database selesai.' -ForegroundColor Green
}
else {
    Write-Host "Database sudah berisi $tableCount tabel, import dilewati agar data lokal tidak tertimpa." -ForegroundColor Green
}

if (Test-Path $migrationDir) {
    $migrationFiles = Get-ChildItem -Path $migrationDir -Filter *.sql | Sort-Object Name
    if ($migrationFiles.Count -gt 0) {
        Write-Host 'Menjalankan migration tambahan...' -ForegroundColor Yellow
        foreach ($migration in $migrationFiles) {
            Import-MySqlDump -Database $dbName -FilePath $migration.FullName
            Write-Host "Applied: $($migration.Name)" -ForegroundColor Green
        }
    }
}

$portInUse = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($portInUse) {
    Write-Host ''
    Write-Host "Port $Port sudah dipakai oleh PID $($portInUse.OwningProcess)." -ForegroundColor Yellow
    Write-Host "Kalau itu server project ini, langsung buka: $serverUrl"
    return
}

Write-Host ''
Write-Host 'Menjalankan server lokal...' -ForegroundColor Yellow
Write-Host 'Tekan Ctrl+C untuk menghentikan server.' -ForegroundColor DarkGray
Write-Host ''

& $phpExe -c $phpIni -S "$serverHost`:$Port" router.php
