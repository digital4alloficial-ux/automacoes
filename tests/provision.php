<?php
declare(strict_types=1);
$file=tempnam(sys_get_temp_dir(),'d4a-password-'); chmod($file,0600);
function hashUtility(string $file): array {
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/tools/password-hash.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$error];
}
try {
    $password='fixture-password-for-provision';file_put_contents($file,$password);
    [$exit,$out,$error]=hashUtility($file);
    check($exit===0 && password_verify($password,trim($out)),'utilitário privado gera hash válido');
    check(!str_contains($out.$error,$password),'utilitário não imprime senha plaintext');
    file_put_contents($file,'short');[$exit,$out,$error]=hashUtility($file);
    check($exit===1 && $out==='' && !str_contains($error,'short'),'provisionamento recusa senha fora da política sem expô-la');
} finally { unlink($file); }
