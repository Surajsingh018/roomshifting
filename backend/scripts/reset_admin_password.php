<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../config/db.php';
if($argc!==2){fwrite(STDERR,"Usage: php backend/scripts/reset_admin_password.php <username>\nSet ROOMSHIFT_INITIAL_ADMIN_PASSWORD in the process environment; do not pass passwords as command arguments.\n");exit(2);}
$username=$argv[1];$password=getenv('ROOMSHIFT_INITIAL_ADMIN_PASSWORD')?:'';
$allowShortLocal=getenv('ROOMSHIFT_APP_ENV')==='local'&&getenv('ROOMSHIFT_ALLOW_SHORT_LOCAL_PASSWORD')==='1';
$minimumPasswordLength=$allowShortLocal?8:12;
if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username)||strlen($password)<$minimumPasswordLength){fwrite(STDERR,"Username must use 3-50 safe characters and password must be at least {$minimumPasswordLength} characters.\n");exit(2);}
$db=roomshift_db();$stmt=$db->prepare('UPDATE users SET password_hash=?,is_active=1 WHERE username=?');$stmt->execute([password_hash($password,PASSWORD_DEFAULT),$username]);
if($stmt->rowCount()===0){$check=$db->prepare('SELECT id FROM users WHERE username=?');$check->execute([$username]);if(!$check->fetchColumn()){fwrite(STDERR,"No matching administrator account.\n");exit(1);}}
fwrite(STDOUT,"Password reset. Sign in with the supplied username and the new password.\n");
