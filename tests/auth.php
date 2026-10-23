<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/src/LocalAuth.php';
use Digital4All\LocalAuth;
$auth=new LocalAuth($db,str_repeat('b',32));
$password='fixture-password-long-enough';
$s=$db->prepare('INSERT INTO app_users(email,password_hash) VALUES (?,?)');
$s->execute(['client@example.invalid',password_hash($password,PASSWORD_DEFAULT)]); $uid=(int)$db->lastInsertId();
$db->prepare('INSERT INTO company_users(user_id,company_id,can_manage_bling) VALUES (?,1,1)')->execute([$uid]);
check($auth->login('CLIENT@example.invalid',$password,'127.0.0.2')['id'] === $uid,'login valida hash e normaliza email');
check($auth->login('client@example.invalid','wrong','127.0.0.2') === null,'senha errada recusada');
check($auth->login('unknown@example.invalid',$password,'127.0.0.2') === null,'usuário inexistente recusado');
$session=['user_id'=>$uid,'auth_version'=>1,'auth_started'=>time(),'auth_seen'=>time(),'company_id'=>1];
check($auth->identity($session)['company_id'] === 1,'login autoriza somente associação persistida');
$bad=$session; $bad['company_id']=2; check($auth->identity($bad) === null,'empresa sem vínculo recusada');
$bad=$session; $bad['auth_seen']=time()-1801; check($auth->identity($bad) === null,'sessão expira por inatividade');
$bad=$session; $bad['auth_started']=time()-28801; check($auth->identity($bad) === null,'sessão tem expiração absoluta');
$db->exec('UPDATE app_users SET session_version=2'); check($auth->identity($session) === null,'reset administrativo invalida sessões anteriores');
$db->exec('UPDATE app_users SET session_version=1,active=0'); check($auth->login('client@example.invalid',$password,'127.0.0.2') === null && $auth->identity($session) === null,'conta desativada bloqueia login e sessão');
$db->exec('UPDATE app_users SET active=1');
$db->exec('UPDATE company_users SET can_manage_bling=0'); check($auth->identity($session) === null,'revogação de permissão vale imediatamente');
$db->exec('UPDATE company_users SET can_manage_bling=1');
$db->exec('TRUNCATE login_limits');
for($i=0;$i<8;$i++) { $auth->login('client@example.invalid','wrong','127.0.0.2'); }
check($auth->login('client@example.invalid',$password,'127.0.0.3') === null,'limite de login persiste entre IPs e sessões');
$db->exec('UPDATE login_limits SET window_start=1');
check($auth->login('client@example.invalid',$password,'127.0.0.3')['id'] === $uid,'login recupera após janela de bloqueio');
check(!str_contains(json_encode($db->query('SELECT * FROM login_limits')->fetchAll()),'example.invalid'),'rate limit persiste somente HMAC da identidade');
$db->exec('TRUNCATE login_limits');
