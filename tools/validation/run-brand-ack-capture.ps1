param([Parameter(Mandatory=$true)][string]$Profiles,[string]$WordPressPackage='',[string]$WooCommercePackage='')
# Fresh actual WooCommerce acknowledgement generation, captured only. Never starts an email transport.
$ErrorActionPreference='Stop'
$repository=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$output=[IO.Path]::GetFullPath((Join-Path $repository '../../outputs'))
$profilesFile=[IO.Path]::GetFullPath($Profiles)
if(-not $profilesFile.StartsWith($output+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -or -not(Test-Path -LiteralPath $profilesFile)){throw 'Private frozen profile snapshot required.'}
Set-Location -LiteralPath $repository
$php='C:/xampp/php/php.exe';$python='C:/Users/AERO/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
$mysql='C:/xampp/mysql/bin/mysql.exe';$databaseProcess=$null
try {
    $probe=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,33177);$probe.ExclusiveAddressUse=$true
    try{$probe.Start()}catch{throw 'Loopback port 33177 is occupied or unavailable.'}finally{$probe.Stop()}
    if($WordPressPackage -and $WooCommercePackage){
        $created=& $python tools/validation/network-fixture-create.py $output $WordPressPackage $WooCommercePackage
    }elseif($WordPressPackage -or $WooCommercePackage){throw 'Supply both explicit packages or neither.'}
    else{$created=& $python tools/validation/network-fixture-create.py $output}
    if($LASTEXITCODE -ne 0){throw 'Fresh fixture creation failed.'}
    $fixtureRoot=($created|ConvertFrom-Json).fixture_path
    Write-Output "Fresh acknowledgement fixture: $fixtureRoot"
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
    & $mysql --protocol=tcp --host=127.0.0.1 --port=33177 --user=root --execute='CREATE DATABASE wphub_fixture_hpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' *> ($fixtureRoot+'/database-create.private.log')
    if($LASTEXITCODE -ne 0){throw 'Fresh schema creation failed.'}
    $env:WPHUB_FIXTURE_ROOT=$fixtureRoot;$env:WPHUB_FIXTURE_STORAGE='hpos';$env:WPHUB_VALIDATION_MODE='true';$env:WPHUB_VALIDATION_MAIL_TRANSPORT='capture'
    # Installer welcome mail is captured to its built-in .invalid admin. The actual
    # brand-order phase uses only the two explicitly approved real test inboxes.
    $env:WPHUB_VALIDATION_RECIPIENTS='fixture-recipient@example.invalid'
    & $php -d disable_functions=mail,is_readable integrations/wphub-documents/tests/real/install.php *> ($fixtureRoot+'/store-install.private.log')
    if($LASTEXITCODE -ne 0){throw 'Actual isolated WordPress/Woo installation failed.'}
    & $php -d disable_functions=mail,is_readable tools/validation/network-fixture-store.php init *> ($fixtureRoot+'/store-init.private.log')
    if($LASTEXITCODE -ne 0){throw 'Actual companion initialization failed.'}
    $env:WPHUB_VALIDATION_RECIPIENTS='youssefbenamar755@gmail.com,benamar-y14@outlook.com'
    & $php -d disable_functions=mail,is_readable tools/validation/brand-acknowledgement-capture.php $profilesFile *> ($fixtureRoot+'/brand-ack-capture.private.log')
    if($LASTEXITCODE -ne 0){throw 'Acknowledgement capture stopped for inspection; do not rerun or send.'}
    Write-Output "Captured acknowledgement file: $fixtureRoot/brand-ack-captures.json"
    Get-Content -LiteralPath ($fixtureRoot+'/brand-ack-capture.private.log')
}finally{
    if($databaseProcess -and -not $databaseProcess.HasExited){Stop-Process -Id $databaseProcess.Id -ErrorAction SilentlyContinue}
}
