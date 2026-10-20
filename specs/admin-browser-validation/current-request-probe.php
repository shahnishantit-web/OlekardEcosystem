<?php
declare(strict_types=1);
require __DIR__.'/../../wallet-ecosystem/services/api/vendor/autoload.php';
$app=require __DIR__.'/../../wallet-ecosystem/services/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$login=Illuminate\Support\Facades\Http::post('http://127.0.0.1:8000/v1/auth/login', ['identifier'=>'admin@olekard.local','credential'=>'Admin123!','credential_type'=>'password','device_fingerprint'=>'admin-web'])->json('data');
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
foreach(array_slice($argv,1) as $uri) {
    $request=Illuminate\Http\Request::create($uri,'GET',[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$login['access_token'],'HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);
    echo $uri,': ',$response->getStatusCode(),PHP_EOL;
}
