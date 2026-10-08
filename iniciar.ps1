param(
    [switch]$SemNavegador
)

$ErrorActionPreference = 'Stop'
$raiz = $PSScriptRoot
$apiUrl = 'http://localhost:5173/api/health'
$siteUrl = 'http://localhost:5173'

function Require-Command([string]$Name, [string]$InstallHint) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Não encontrei '$Name'. $InstallHint"
    }
}

function Wait-ForUrl([string]$Url, [int]$Seconds = 60) {
    $until = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $until) {
        try {
            $null = Invoke-WebRequest -Uri $Url -TimeoutSec 2 -UseBasicParsing
            return $true
        } catch {
            Start-Sleep -Seconds 1
        }
    }
    return $false
}

try {
    Require-Command 'docker' 'Instale e inicie o Docker Desktop.'

    Write-Host 'Iniciando aplicação PHP e MySQL...' -ForegroundColor Cyan
    Push-Location $raiz
    try { docker compose up -d --build mysql backend; if ($LASTEXITCODE -ne 0) { throw 'Docker Compose não conseguiu iniciar a aplicação e o MySQL.' } }
    finally { Pop-Location }

    Write-Host 'Aguardando MySQL ficar pronto...' -ForegroundColor Cyan
    $dbReady = $false
    for ($i = 0; $i -lt 60; $i++) {
        $state = docker inspect --format '{{.State.Health.Status}}' super-adicto-mysql 2>$null
        if ($state -eq 'healthy') { $dbReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $dbReady) { throw 'O MySQL não ficou pronto. Confira: docker compose logs mysql' }

    Write-Host 'Aguardando aplicação ficar pronta...' -ForegroundColor Cyan
    if (-not (Wait-ForUrl $apiUrl 90)) {
        docker compose logs --tail 40 backend
        throw 'A aplicação não respondeu em http://localhost:5173.'
    }

    if (-not $SemNavegador) { Start-Process $siteUrl | Out-Null }
    Write-Host ''
    Write-Host 'Aplicação pronta: http://localhost:5173' -ForegroundColor Green
    Write-Host 'Frontend e API PHP: http://localhost:5173 | MySQL: localhost:3307'
    Write-Host 'A aplicação continua rodando em Docker. Para parar: docker compose down'
} catch {
    Write-Host "Erro ao iniciar a aplicação: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
