param([Parameter(Mandatory=$true)][string]$WordPressPackage,[Parameter(Mandatory=$true)][string]$WooCommercePackage)
# New actual Woo fixture; both native storage modes. No HTTP server, credentials, or real mail.
$ErrorActionPreference='Stop'
$repository=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$output=[IO.Path]::GetFullPath((Join-Path $repository '../../outputs'))
Set-Location -LiteralPath $repository
$php='C:/xampp/php/php.exe';$python='C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
$mysql='C:/xampp/mysql/bin/mysql.exe';$databaseProcess=$null
$environmentNames=@('WPHUB_FIXTURE_ROOT','WPHUB_FIXTURE_STORAGE','WPHUB_VALIDATION_MODE','WPHUB_VALIDATION_MAIL_TRANSPORT','WPHUB_VALIDATION_RECIPIENTS','WPHUB_FIXTURE_EMAIL_IMPROVEMENTS')
$savedEnvironment=@{};foreach($name in $environmentNames){$savedEnvironment[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
try {
    $probe=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,33177);$probe.ExclusiveAddressUse=$true
    try{$probe.Start()}catch{throw 'Loopback port 33177 is occupied or unavailable.'}finally{$probe.Stop()}
    $created=& $python tools/validation/network-fixture-create.py $output $WordPressPackage $WooCommercePackage
    if($LASTEXITCODE -ne 0){throw 'Fresh fixture creation failed.'}
    $fixtureRoot=($created|ConvertFrom-Json).fixture_path
    Write-Output "Fresh runtime compatibility fixture: $fixtureRoot"
    & C:/xampp/mysql/bin/mysql_install_db.exe ('--datadir='+$fixtureRoot+'/data') --port=33177 *> ($fixtureRoot+'/mysql-install.private.log')
    if($LASTEXITCODE -ne 0){throw 'Fresh database initialization failed.'}
    $databaseProcess=Start-Process -FilePath C:/xampp/mysql/bin/mysqld.exe -ArgumentList @('--no-defaults',('--datadir='+$fixtureRoot+'/data'),'--port=33177','--bind-address=127.0.0.1','--skip-name-resolve','--console') -WindowStyle Hidden -RedirectStandardOutput ($fixtureRoot+'/mysql.stdout.private.log') -RedirectStandardError ($fixtureRoot+'/mysql.stderr.private.log') -PassThru
    $ready=$false
    for($attempt=0;$attempt -lt 30;$attempt++){
        if($databaseProcess.HasExited){throw 'Private database exited.'}
        $identity=& $mysql --batch --raw --skip-column-names --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='SELECT @@datadir, @@port;' 2> ($fixtureRoot+'/database-ready.private.log')
        if($LASTEXITCODE -eq 0){
            if($databaseProcess.HasExited){throw 'Owned database exited during readiness check.'}
            $identityParts=($identity -join "`n").Trim().Split("`t")
            $expectedDirectory=($fixtureRoot -replace '\\','/').TrimEnd('/')+'/data/'
            if($identityParts.Count -ne 2 -or ($identityParts[0] -replace '\\','/').TrimEnd('/')+'/' -ine $expectedDirectory -or $identityParts[1] -ne '33177'){throw 'Loopback database identity does not match the freshly owned datadir and port.'}
            $ready=$true;break
        }
        Start-Sleep -Milliseconds 200
    }
    if(-not $ready){throw 'Private loopback database unavailable.'}
    $env:WPHUB_FIXTURE_ROOT=$fixtureRoot;$env:WPHUB_VALIDATION_MODE='true';$env:WPHUB_VALIDATION_MAIL_TRANSPORT='capture'
    $env:WPHUB_VALIDATION_RECIPIENTS='fixture-recipient@example.invalid';$env:WPHUB_FIXTURE_EMAIL_IMPROVEMENTS='yes'
    foreach($mode in @('hpos','legacy')){
        $env:WPHUB_FIXTURE_STORAGE=$mode
        & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root ("--execute=CREATE DATABASE wphub_fixture_$mode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") *> ($fixtureRoot+"/database-create-$mode.private.log")
        if($LASTEXITCODE -ne 0){throw 'Fresh schema creation failed.'}
        & $php -d disable_functions=mail,is_readable integrations/wphub-documents/tests/real/install.php *> ($fixtureRoot+"/store-install-$mode.private.log")
        if($LASTEXITCODE -ne 0){throw 'Actual isolated WordPress/Woo installation failed.'}
        & $php -d disable_functions=mail,is_readable tools/validation/fixture-runtime-init.php *> ($fixtureRoot+"/store-init-$mode.private.log")
        if($LASTEXITCODE -ne 0){throw 'Actual Woo schema and manager initialization failed.'}
        & $php -d disable_functions=mail,is_readable integrations/wphub-documents/tests/real/run.php $mode *> ($fixtureRoot+"/compatibility-$mode.private.log")
        if($LASTEXITCODE -ne 0){throw "Compatibility suite stopped in $mode; preserve private evidence."}
        $result=Get-Content -Raw -LiteralPath ($fixtureRoot+"/result-$mode.json") | ConvertFrom-Json
        [pscustomobject]@{fixture=$fixtureRoot;mode=$mode;passed=$result.passed;checks=$result.checks.Count;wordpress=$result.wordpress;woocommerce=$result.woocommerce;php=$result.php;email_improvements=$result.email_improvements;real_external_email_sent=$result.real_external_email_sent}|ConvertTo-Json -Compress
    }
}finally{
    if($databaseProcess -and -not $databaseProcess.HasExited){Stop-Process -Id $databaseProcess.Id -ErrorAction SilentlyContinue}
    foreach($name in $environmentNames){[Environment]::SetEnvironmentVariable($name,$savedEnvironment[$name],'Process')}
}
