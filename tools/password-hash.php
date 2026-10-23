<?php
declare(strict_types=1);
// Run LOCALLY, never upload this utility into a public directory.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !is_file($argv[1]) || !is_readable($argv[1])) { fwrite(STDERR,"Informe um arquivo privado local contendo a senha, nunca a senha como argumento.\n"); exit(1); }
$raw=file_get_contents($argv[1],false,null,0,1024);
$password=is_string($raw) ? rtrim($raw,"\r\n") : '';
if (strlen($password)<16 || strlen($password)>72 || str_contains($password,"\n") || str_contains($password,"\r") || str_contains($password,"\0")) {
    fwrite(STDERR,"A senha deve conter de 16 a 72 bytes em uma única linha.\n"); exit(1);
}
echo password_hash($password,PASSWORD_DEFAULT),"\n";
