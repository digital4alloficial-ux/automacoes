<?php
declare(strict_types=1);
require __DIR__ . '/support.php';
$db = testDb();
$service = testService($db, new FakeTransport($db));
try {
    if ($argv[1] === 'refresh') {
        $service->accessToken(new \Digital4All\Principal('user-a', 1));
    } else {
        $service->callback(new \Digital4All\Principal('user-a', 1), 'session-a', ['state' => $argv[2], 'code' => 'fixture-code']);
    }
    echo 'ok';
} catch (\Digital4All\OAuthFailure $e) {
    echo $e->reason;
    exit(2);
}
