<?php
// Spike only. Boots the gw-test-statamic app from outside it, so the spike
// needs no route or code in the site or the addon.
$site = getenv('GW_SITE') ?: getenv('HOME').'/Dev/gw-test-statamic';
require $site.'/vendor/autoload.php';
$app = require $site.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Markers.php';
return $app;
