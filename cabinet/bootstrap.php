<?php
declare(strict_types=1);
if (!defined('ELIMORA_API')) { http_response_code(404); exit; }
ini_set('display_errors', '0');
date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(400); echo json_encode(['error'=>'Откройте сайт по HTTPS.']); exit;
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('__Host-elimora');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 7200) {
    $_SESSION = []; session_regenerate_id(true);
}
$_SESSION['last_seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function reply(array $data, int $code=200): never {
    http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function fail(string $message, int $code=400): never { reply(['error'=>$message], $code); }
function config(): array {
    static $c;
    if (isset($c)) return $c;
    // This file is one level ABOVE public_html and never enters GitHub or FTP deployment.
    $path = dirname(__DIR__, 2).'/elimora-config.php';
    if (!is_file($path)) fail('Кабинет ещё настраивается. Пока напишите Эльвире в Telegram.', 503);
    $c = require $path;
    if (!is_array($c) || strlen((string)($c['install_key'] ?? '')) < 32) fail('Не завершена настройка кабинета.',503);
    return $c;
}
function db(): PDO {
    static $pdo;
    if (isset($pdo)) return $pdo;
    $c = config();
    $pdo = new PDO('mysql:host='.($c['db_host']??'localhost').';dbname='.$c['db_name'].';charset=utf8mb4',
        $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function query(string $sql, array $params=[]): PDOStatement {
    $s=db()->prepare($sql); $s->execute($params); return $s;
}
function installed(): bool {
    try { return (bool)query("SELECT value FROM em_settings WHERE name='installed'")->fetchColumn(); }
    catch (PDOException $e) { if ($e->getCode()==='42S02') return false; throw $e; }
}
function post(): array {
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail('Нужен POST-запрос.',405);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) fail('Обновите страницу и повторите действие.',403);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>17*1024*1024) fail('Слишком большой запрос.',413);
    if (str_contains($_SERVER['CONTENT_TYPE']??'', 'multipart/form-data')) return $_POST;
    $raw=file_get_contents('php://input');
    if (strlen($raw)>65536) fail('Слишком большой текст.',413);
    $data=json_decode($raw,true);
    if (!is_array($data)) fail('Некорректный запрос.');
    return $data;
}
function field(array $data,string $key,int $max=200,bool $required=true): string {
    $v=$data[$key]??'';
    if (!is_string($v) || strlen($v)>$max || ($required && trim($v)==='')) fail('Проверьте поле «'.$key.'».');
    return trim($v);
}
function email(array $d): string {
    $v=strtolower(field($d,'email',254));
    if (!filter_var($v,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$v)) fail('Укажите корректный email.');
    return $v;
}
function password(array $d): string {
    $v=$d['password']??'';
    if (!is_string($v) || strlen($v)<12 || strlen($v)>72) fail('Пароль: от 12 до 72 байт. Используйте длинную фразу.');
    return $v;
}
function user(): array {
    if (empty($_SESSION['uid'])) fail('Войдите в кабинет.',401);
    $u=query('SELECT id,name,email,role,session_version FROM em_users WHERE id=?',[$_SESSION['uid']])->fetch();
    if (!$u || (int)$u['session_version']!==($_SESSION['version']??0)) { $_SESSION=[]; fail('Войдите заново.',401); }
    return $u;
}
function public_user(array $u): array { return array_intersect_key($u,array_flip(['id','name','email','role'])); }
function login_user(array $u): void {
    session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; $_SESSION['version']=(int)$u['session_version'];
    $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function limit(string $action,int $maximum,int $seconds,string $identity=''): void {
    $bucket=hash_hmac('sha256',$action.'|'.($_SERVER['REMOTE_ADDR']??'').'|'.$identity.'|'.intdiv(time(),$seconds),config()['install_key']);
    query('INSERT INTO em_limits (bucket,hits,expires) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$bucket,time()+$seconds]);
    if ((int)query('SELECT hits FROM em_limits WHERE bucket=?',[$bucket])->fetchColumn()>$maximum) fail('Слишком много попыток. Попробуйте позже.',429);
    if (random_int(1,100)===1) query('DELETE FROM em_limits WHERE expires < ?', [time()]);
}
function project(int $id,array $u): array {
    $o=query('SELECT * FROM em_orders WHERE id=?',[$id])->fetch();
    if (!$o || ($u['role']!=='admin' && (int)$o['user_id']!==(int)$u['id'])) fail('Заявка не найдена.',404);
    return $o;
}
function storage(): string {
    $dir=dirname(__DIR__,2).'/elimora-storage';
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('storage');
    return $dir;
}
function notify_admin(int $id): void {
    $c=config(); $to=$c['admin_email']??'';
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return;
    // Notification contains no brief or personal details; full information stays behind login.
    @mail($to,'ELIMORA: new project #'.$id,"Новая заявка №$id.\nОткройте https://elimora.ru/account.html",'From: ELIMORA <no-reply@elimora.ru>');
}
