<?php
declare(strict_types=1);
require __DIR__ . '/support.php';
$c = testConfig(); $c['token_url'] = $argv[1];
try {
    $tokens = (new \Digital4All\TokenClient($c))->exchange(['grant_type' => 'authorization_code', 'code' => 'fixture+code&value']);
    if ($tokens['access_token'] !== 'fixture-access') { exit(3); }
    echo 'ok';
} catch (\Digital4All\OAuthFailure $e) { echo $e->reason; }
