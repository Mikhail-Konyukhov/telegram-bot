# Ловит свободную ёмкость Always Free и создаёт инстанс, как только она появится.
# Требует настроенный OCI CLI (oci setup config) и уже созданные VCN + публичную подсеть.
# Останавливать — Ctrl+C.
#
# E2.1.Micro идёт первым: после переезда классификации в Gemini проекту хватает 1 GB,
# а micro освобождается чаще, чем A1. A1 пробуем следом — если он вдруг свободен,
# бесплатные 4 OCPU / 24 GB брать выгоднее.

$ErrorActionPreference = 'Continue'
$env:SUPPRESS_LABEL_WARNING = 'True'
if (-not (Get-Command oci -ErrorAction SilentlyContinue)) { $env:Path += ";$HOME\lib\oracle-cli\Scripts" }

$Name       = 'money-bot'
# A1.Flex пробуем по убыванию: он потом увеличивается через stop → Edit shape,
# поэтому занять место меньшим размером выгоднее, чем ждать больший.
# Micro — фиксированный shape, --shape-config ему передавать нельзя (InvalidParameter).
$Targets    = @(
    @{ shape = 'VM.Standard.E2.1.Micro' },
    @{ shape = 'VM.Standard.A1.Flex'; o = 4; m = 24 },
    @{ shape = 'VM.Standard.A1.Flex'; o = 2; m = 12 },
    @{ shape = 'VM.Standard.A1.Flex'; o = 1; m = 6 }
)
# Хватает с запасом: кэша HuggingFace больше нет. Меньший boot оставляет место
# под второй бесплатный инстанс — Always Free даёт 200 GB на все тома.
$BootGB     = 50
$SubnetName = 'public subnet-vcn-money-bot'
$SshPubPath = "$HOME\.ssh\oracle-money-bot.pub"
$DelaySec    = 180    # пауза между попытками; чаще — ловим 429 от Oracle
$MaxDelaySec = 1800   # потолок паузы при троттлинге

$tenancy = (Select-String -Path "$HOME\.oci\config" -Pattern '^tenancy\s*=\s*(\S+)').Matches[0].Groups[1].Value
$sshKey  = (Get-Content $SshPubPath -Raw).Trim()

# PowerShell 5.1 рвёт аргумент по пробелам внутри JSON-строки, а в ssh-ключе они есть,
# поэтому метаданные передаём файлом через file://
$metaFile = Join-Path $env:TEMP 'oci-launch-metadata.json'
@{ ssh_authorized_keys = $sshKey } | ConvertTo-Json -Compress | Set-Content $metaFile -Encoding ascii
$metaArg  = 'file://' + ($metaFile -replace '\\', '/')

# ponytail: всё, кроме shape, вытаскиваем из API — меньше полей, которые можно перепутать руками.
# Образ свой на каждый shape: A1 — aarch64, micro — x86, перепутать нельзя.
$images = @{}
foreach ($shape in ($Targets.shape | Select-Object -Unique)) {
    $images[$shape] = oci compute image list -c $tenancy --operating-system 'Canonical Ubuntu' `
                        --operating-system-version '22.04' --shape $shape `
                        --sort-by TIMECREATED --query 'data[0].id' --raw-output
    if (-not $images[$shape]) { throw "Не нашёл образ Ubuntu 22.04 под $shape" }
}
$subnet = oci network subnet list -c $tenancy --display-name $SubnetName `
            --query 'data[0].id' --raw-output
$ads    = (oci iam availability-domain list --query 'data[].name' --raw-output) | ConvertFrom-Json

if (-not $subnet) { throw "Не нашёл подсеть '$SubnetName'" }
Write-Host "Ловим $(($Targets | ForEach-Object { if ($_.o) { "A1 $($_.o)/$($_.m)" } else { 'micro' } }) -join ', ') в: $($ads -join ', ')"

$try   = 0
$delay = $DelaySec
while ($true) {
  foreach ($target in $Targets) {
    foreach ($ad in $ads) {
        $try++
        $label = if ($target.o) { "A1 $($target.o) OCPU / $($target.m) GB" } else { 'E2.1.Micro 1 GB' }

        $launchArgs = @(
            '--availability-domain', $ad, '-c', $tenancy,
            '--shape', $target.shape,
            '--image-id', $images[$target.shape], '--subnet-id', $subnet,
            '--assign-public-ip', 'true',
            '--display-name', $Name, '--boot-volume-size-in-gbs', $BootGB,
            '--metadata', $metaArg,
            '--wait-for-state', 'RUNNING'
        )
        # Только Flex-шейпы принимают --shape-config; micro на нём падает InvalidParameter
        if ($target.o) {
            $launchArgs += @('--shape-config', "{\`"ocpus\`":$($target.o),\`"memoryInGBs\`":$($target.m)}")
        }

        $out = (oci compute instance launch @launchArgs 2>&1) | Out-String

        if ($LASTEXITCODE -eq 0) {
            Write-Host "`nПоймали $label! Инстанс создан." -ForegroundColor Green
            $out
            return
        }
        $ts = Get-Date -Format HH:mm:ss

        if ($out -match 'Out of (host )?capacity') {
            $delay = $DelaySec
            Write-Host "$ts  попытка $try  $label  — занято"
        }
        # Сдаёмся только на том, что повтором не лечится: права, квота, кривые параметры
        elseif ($out -match 'NotAuthenticated|NotAuthorized|InvalidParameter|CannotParseRequest|NotFound|LimitExceeded|QuotaExceeded|unexpected extra arguments|Usage: oci') {
            Write-Host "`nОшибка не про ёмкость, разбирайся:" -ForegroundColor Red
            $out
            return
        }
        # Троттлинг, таймауты, 5xx — временное: отступаем и продолжаем
        else {
            $delay = [Math]::Min($delay * 2, $MaxDelaySec)
            $why = if ($out -match 'TooManyRequests') { '429' } elseif ($out -match 'timed out') { 'таймаут' } else { 'сбой запроса' }
            Write-Host "$ts  попытка $try  — $why, пауза $delay c" -ForegroundColor Yellow
        }
        # Пауза после каждой попытки, а не после круга: три размера подряд без задержки ловят 429
        Start-Sleep -Seconds $delay
    }
  }
}
