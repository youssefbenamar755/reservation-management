param([ValidateSet('Prepare','Send','Evidence')][string]$Phase='Prepare',[string]$Fixture='')
# Prepare creates a fresh fixture and saves reviewed emails. Only Send transmits exactly those saved previews.
$ErrorActionPreference='Stop'
$repository=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$output=[IO.Path]::GetFullPath((Join-Path $repository '../../outputs'))
Set-Location -LiteralPath $repository
$php='C:/xampp/php/php.exe';$python='C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
$mysql='C:/xampp/mysql/bin/mysql.exe';$processes=@();$fixtureRoot=$null
try {
    foreach($port in @(33177,8802,8443)) {
        $probe=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$port);$probe.ExclusiveAddressUse=$true
        try {$probe.Start()} catch {throw "Cannot exclusively bind loopback port $port; refusing other processes."} finally {$probe.Stop()}
    }
    if($Phase -eq 'Prepare') {
        if($Fixture) {throw 'Prepare requires a NEW fixture; no existing fixture argument is accepted.'}
        $creation=& $python tools/validation/network-fixture-create.py $output
        if($LASTEXITCODE -ne 0){throw 'Fresh isolated fixture creation failed.'}
        $fixtureRoot=($creation|ConvertFrom-Json).fixture_path
        & C:/xampp/mysql/bin/mysql_install_db.exe ('--datadir='+$fixtureRoot+'/data') --port=33177 *> ($fixtureRoot+'/mysql-install.private.log')
        if($LASTEXITCODE -ne 0){throw 'Fresh database initialization failed.'}
    } else {
        $fixtureRoot=[IO.Path]::GetFullPath($Fixture)
        if(-not $fixtureRoot.StartsWith($output+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -or
            -not(Test-Path -LiteralPath ($fixtureRoot+'/gmail-bridge-prepared.json')) -or -not(Test-Path -LiteralPath ($fixtureRoot+'/FICTIONAL-ONLY'))) {throw 'Exact newly prepared private Gmail network fixture required.'}
    }
    Write-Output "Isolated Gmail journey fixture: $fixtureRoot"
    $databaseProcess=Start-Process -FilePath C:/xampp/mysql/bin/mysqld.exe -ArgumentList @('--no-defaults',('--datadir='+$fixtureRoot+'/data'),'--port=33177','--bind-address=127.0.0.1','--skip-name-resolve','--console') -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/mysql.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/mysql.stderr.private.log') -PassThru
    $processes+=$databaseProcess;$ready=$false
    for($attempt=0;$attempt -lt 30;$attempt++) {
        & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='SELECT 1;' *> ($fixtureRoot+'/database-ready.private.log')
        if($LASTEXITCODE -eq 0){$ready=$true;break}
        if($databaseProcess.HasExited){throw 'Private MariaDB process exited'}
        Start-Sleep -Milliseconds 200
    }
    if(-not $ready){throw 'Private loopback database unavailable'}
    $env:WPHUB_FIXTURE_ROOT=$fixtureRoot;$env:WPHUB_FIXTURE_STORAGE='hpos';$env:WPHUB_VALIDATION_MODE='true';$env:WPHUB_VALIDATION_MAIL_TRANSPORT='capture'
    if($Phase -eq 'Prepare') {
        & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='CREATE DATABASE wphub_fixture_hpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' *> ($fixtureRoot+'/database-create.private.log')
        if($LASTEXITCODE -ne 0){throw 'New fictional database creation failed.'}
        $env:WPHUB_VALIDATION_RECIPIENTS='fixture-recipient@example.invalid'
        & $php -d disable_functions=mail,is_readable integrations/wphub-documents/tests/real/install.php *> ($fixtureRoot+'/store-install.private.log')
        if($LASTEXITCODE -ne 0){throw 'Fictional store install failed'}
        & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-store.php init *> ($fixtureRoot+'/store-init.private.log')
        if($LASTEXITCODE -ne 0){throw 'Fictional store init failed'}
    }
    $env:WPHUB_VALIDATION_RECIPIENTS='youssefbenamar755@gmail.com,benamar-y14@outlook.com'
    $storeProcess=Start-Process -FilePath $php -ArgumentList @('-d','disable_functions=mail,is_readable','-S','127.0.0.1:8802','tools/validation/network-fixture-router.php') -WorkingDirectory $repository -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/http.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/http.stderr.private.log') -PassThru
    $processes+=$storeProcess
    $tlsProcess=Start-Process -FilePath 'C:/Program Files/nodejs/node.exe' -ArgumentList @('tools/validation/network-fixture-proxy.cjs',$fixtureRoot) -WorkingDirectory $repository -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/tls.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/tls.stderr.private.log') -PassThru
    $processes+=$tlsProcess;Start-Sleep -Milliseconds 400
    if($Phase -eq 'Prepare') {
        & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-app.php ('--fixture='+$fixtureRoot) --phase=setup *> ($fixtureRoot+'/app-setup.private.log')
        if($LASTEXITCODE -ne 0){throw 'Fresh app and actual TLS setup failed'}
        foreach($step in @('configure','seed-store','prepare')) {
            & $php -d disable_functions=mail,is_readable tools/validation/network-gmail-journey.php ('--fixture='+$fixtureRoot) ('--phase='+$step) *> ($fixtureRoot+'/gmail-'+$step+'.private.log')
            if($LASTEXITCODE -ne 0){throw "Gmail journey $step failed; no automatic continuation. Private log retained."}
        }
        Get-Content -LiteralPath ($fixtureRoot+'/gmail-bridge-prepared.json')
        Write-Output 'Review the saved two fictional MIME previews and recipient/attachment evidence. No email has been sent. Send requires a separate explicit phase.'
    } else {
        & $php -d disable_functions=mail,is_readable tools/validation/network-gmail-journey.php ('--fixture='+$fixtureRoot) ('--phase='+$Phase.ToLowerInvariant()) *> ($fixtureRoot+'/gmail-'+$Phase.ToLowerInvariant()+'.private.log')
        if($LASTEXITCODE -ne 0){throw 'Gmail journey stopped for review. Do not recreate previews or resend automatically.'}
        if($Phase -in @('Send','Evidence')) {
            & $php -d disable_functions=mail,is_readable tools/validation/network-gmail-store-evidence.php ('--fixture='+$fixtureRoot) *> ($fixtureRoot+'/gmail-store-evidence.private.log')
            if($LASTEXITCODE -ne 0){throw 'Post-send read-only store evidence failed. Do not resend.'}
            & $python tools/validation/network-gmail-postsend-audit.py $fixtureRoot
            if($LASTEXITCODE -ne 0){throw 'Independent post-send evidence audit failed. Do not resend.'}
        }
        Get-Content -LiteralPath ($fixtureRoot+'/gmail-bridge-evidence.json')
    }
} finally {
    if($processes.Count -gt 0 -and -not $processes[0].HasExited) {
        & C:/xampp/mysql/bin/mysqladmin.exe --protocol=tcp --host=127.0.0.1 --port=33177 --user=root shutdown *> ($fixtureRoot+'/database-shutdown.private.log')
        $processes[0].WaitForExit(5000)|Out-Null
    }
    foreach($process in $processes){if(-not $process.HasExited){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}}
    if($fixtureRoot){Write-Output "Only this fixture's services stopped. Capture is its persisted default: $fixtureRoot"}
}
