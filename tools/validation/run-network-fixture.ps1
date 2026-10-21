param([string]$OutputParent='C:/Users/AERO/Documents/Codex/2026-09-06/i/outputs')
$ErrorActionPreference='Stop'
$repository=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$output=[IO.Path]::GetFullPath($OutputParent)
if (-not (Test-Path -LiteralPath $output -PathType Container)) { throw 'An existing private output parent is required.' }
if ($output.StartsWith((Join-Path $repository 'public'),[StringComparison]::OrdinalIgnoreCase)) { throw 'Public output refused.' }
Set-Location -LiteralPath $repository
$php='C:/xampp/php/php.exe'
$python='C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
$mysql='C:/xampp/mysql/bin/mysql.exe'
$processes=@()
$fixtureRoot=$null
try {
    foreach($port in @(33177,8802,8443)) {
        $portProbe=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$port)
        $portProbe.ExclusiveAddressUse=$true
        try { $portProbe.Start() } catch { throw "Cannot exclusively bind fixture loopback port $port; refusing to replace any process." }
        finally { $portProbe.Stop() }
    }
    $creation=& $python tools/validation/network-fixture-create.py $output
    if($LASTEXITCODE -ne 0) {throw 'Fresh fixture creation failed.'}
    $fixtureRoot=($creation | ConvertFrom-Json).fixture_path
    if(-not ([IO.Path]::GetFullPath($fixtureRoot).StartsWith($output+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase))) {throw 'Unexpected fixture path.'}
    Write-Output "Fresh capture-only fixture: $fixtureRoot"
    & C:/xampp/mysql/bin/mysql_install_db.exe ('--datadir='+$fixtureRoot+'/data') --port=33177 *> ($fixtureRoot+'/mysql-install.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Fresh MariaDB initialization failed.'}
    $databaseProcess=Start-Process -FilePath C:/xampp/mysql/bin/mysqld.exe -ArgumentList @('--no-defaults',('--datadir='+$fixtureRoot+'/data'),'--port=33177','--bind-address=127.0.0.1','--skip-name-resolve','--console') -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/mysql.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/mysql.stderr.private.log') -PassThru
    $processes += $databaseProcess
    $ready=$false
    for($attempt=0;$attempt -lt 30;$attempt++) {
        & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='SELECT 1;' *> ($fixtureRoot+'/database-ready.private.log')
        if($LASTEXITCODE -eq 0) {$ready=$true;break}
        if($databaseProcess.HasExited) {throw 'Fixture database stopped; see private server log.'}
        Start-Sleep -Milliseconds 200
    }
    if(-not $ready) {throw 'Loopback database is unreachable; no store work executed.'}
    & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='CREATE DATABASE wphub_fixture_hpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' *> ($fixtureRoot+'/database-create.private.log')
    if($LASTEXITCODE -ne 0) {throw 'New fictional database creation failed.'}
    $env:WPHUB_FIXTURE_ROOT=$fixtureRoot
    $env:WPHUB_FIXTURE_STORAGE='hpos'
    $env:WPHUB_VALIDATION_MODE='true'
    $env:WPHUB_VALIDATION_MAIL_TRANSPORT='capture'
    $env:WPHUB_VALIDATION_RECIPIENTS='fixture-recipient@example.invalid'
    & $php -d disable_functions=mail,is_readable integrations/wphub-documents/tests/real/install.php *> ($fixtureRoot+'/store-install.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Fictional WordPress installation failed.'}
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-store.php init *> ($fixtureRoot+'/store-init.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Fictional store initialization failed.'}
    $storeProcess=Start-Process -FilePath $php -ArgumentList @('-d','disable_functions=mail,is_readable','-S','127.0.0.1:8802','tools/validation/network-fixture-router.php') -WorkingDirectory $repository -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/http.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/http.stderr.private.log') -PassThru
    $processes += $storeProcess
    $tlsProcess=Start-Process -FilePath 'C:/Program Files/nodejs/node.exe' -ArgumentList @('tools/validation/network-fixture-proxy.cjs',$fixtureRoot) -WorkingDirectory $repository -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/tls.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/tls.stderr.private.log') -PassThru
    $processes += $tlsProcess
    Start-Sleep -Milliseconds 400
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-app.php ('--fixture='+$fixtureRoot) --phase=setup *> ($fixtureRoot+'/app-setup.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Actual app/TLS/Woo setup failed; inspect private app-setup log.'}
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-store.php seed *> ($fixtureRoot+'/store-seed.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Fictional paid orders failed to seed.'}
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-app.php ('--fixture='+$fixtureRoot) --phase=journey *> ($fixtureRoot+'/app-journey.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Full capture/TLS/Woo journey failed; inspect private app-journey log.'}
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-store.php evidence *> ($fixtureRoot+'/store-evidence.private.log')
    if($LASTEXITCODE -ne 0) {throw 'Final store evidence read failed.'}
    & $python tools/validation/network-fixture-audit.py $fixtureRoot
    if($LASTEXITCODE -ne 0) {throw 'Network/store/MIME audit failed.'}
    Get-Content -LiteralPath ($fixtureRoot+'/journey-evidence.json')
} finally {
    if($processes.Count -gt 0 -and -not $processes[0].HasExited) {
        & C:/xampp/mysql/bin/mysqladmin.exe --protocol=tcp --host=127.0.0.1 --port=33177 --user=root shutdown *> ($fixtureRoot+'/database-shutdown.private.log')
        $processes[0].WaitForExit(5000) | Out-Null
    }
    foreach($fixtureProcess in $processes) {
        if(-not $fixtureProcess.HasExited) {Stop-Process -Id $fixtureProcess.Id -ErrorAction SilentlyContinue}
    }
    if($fixtureRoot) {Write-Output "Fixture services stopped. Private evidence retained at $fixtureRoot"}
}
