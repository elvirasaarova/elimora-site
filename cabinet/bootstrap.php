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
    if (!is_string($v) || preg_match_all('/./us',$v)<8 || strlen($v)>72) fail('Пароль: минимум 8 символов, до 72 байт.');
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
function migrate(): void {
    if (query("SELECT value FROM em_settings WHERE name='schema_version'")->fetchColumn()==='4') return;
    $lock='elimora-schema-'.substr(hash('sha256',config()['db_name']),0,20);
    if ((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1) throw new RuntimeException('schema lock');
    try {
        $columns=query('SHOW COLUMNS FROM em_orders')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('company',$columns,true)) db()->exec("ALTER TABLE em_orders ADD company VARCHAR(200) NOT NULL DEFAULT ''");
        $columns=query('SHOW COLUMNS FROM em_files')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category',$columns,true)) {
            db()->exec("ALTER TABLE em_files ADD category VARCHAR(20) NOT NULL DEFAULT 'documents'");
            db()->exec("UPDATE em_files SET category='media' WHERE mime LIKE 'image/%'");
        }
        db()->exec("CREATE TABLE IF NOT EXISTS em_uploads (id CHAR(64) PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, original_name VARCHAR(200) NOT NULL, category VARCHAR(20) NOT NULL, expected_bytes BIGINT NOT NULL, received_bytes BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, INDEX(user_id), INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns=query('SHOW COLUMNS FROM em_orders')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['kind'=>"VARCHAR(20) NOT NULL DEFAULT 'design'",'released'=>"TINYINT NOT NULL DEFAULT 0",'access_note'=>"TEXT NULL",'access_reported'=>"TINYINT NOT NULL DEFAULT 0"] as $name=>$type) if (!in_array($name,$columns,true)) db()->exec("ALTER TABLE em_orders ADD $name $type");
        foreach (['em_files','em_uploads'] as $table) {
            $columns=query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('purpose',$columns,true)) db()->exec("ALTER TABLE $table ADD purpose VARCHAR(20) NOT NULL DEFAULT 'material'");
        }
        $columns=query('SHOW COLUMNS FROM em_files')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('review_id',$columns,true)) db()->exec('ALTER TABLE em_files ADD review_id BIGINT UNSIGNED NULL');
        db()->exec("CREATE TABLE IF NOT EXISTS em_reviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', note TEXT NOT NULL, feedback TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, decided_at TIMESTAMP NULL, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS em_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', note TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS em_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, body VARCHAR(500) NOT NULL, seen TINYINT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(user_id,seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS em_briefs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, payload MEDIUMTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns=query('SHOW COLUMNS FROM em_files')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_id',$columns,true)) db()->exec('ALTER TABLE em_files ADD payment_id BIGINT UNSIGNED NULL');
        $columns=query('SHOW COLUMNS FROM em_uploads')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_id',$columns,true)) db()->exec('ALTER TABLE em_uploads ADD payment_id BIGINT UNSIGNED NULL');
        query("INSERT INTO em_settings (name,value) VALUES ('schema_version','4') ON DUPLICATE KEY UPDATE value='4'");
    } finally { query('SELECT RELEASE_LOCK(?)',[$lock]); }
}
function file_category(string $category): string {
    if (!in_array($category,['media','brand','documents'],true)) fail('Выберите раздел для материалов.');
    return $category;
}
function file_name(string $original): string {
    if (!preg_match('//u',$original) || strlen($original)>200 || $original==='') fail('Имя файла должно быть короче 200 байт.');
    return preg_replace('/[\x00-\x1f\x7f\\\\\/]/','_',$original);
}
function file_mime(string $path,string $name,string $category): string {
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $allowed=['media'=>['jpg','jpeg','png','mp4','mov'],
        'brand'=>['jpg','jpeg','png','webp','svg','pdf','zip'],
        'documents'=>['pdf','doc','docx','xls','xlsx','csv','txt']];
    if (!in_array($ext,$allowed[$category]??[],true)) fail($category==='media'?'Фото — только PNG/JPEG. HEIC нужно предварительно преобразовать. Видео — MP4/MOV.':'Этот формат не подходит для выбранного раздела.');
    $types=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],
        'pdf'=>['application/pdf'],'mp4'=>['video/mp4','application/mp4'],'mov'=>['video/quicktime','video/mp4'],
        'txt'=>['text/plain'],'csv'=>['text/plain','text/csv','application/csv'],
        'svg'=>['image/svg+xml','text/xml','application/xml','text/plain'],
        'zip'=>['application/zip','application/x-zip-compressed'],
        'doc'=>['application/msword','application/CDFV2','application/x-ole-storage'],
        'xls'=>['application/vnd.ms-excel','application/CDFV2','application/x-ole-storage'],
        'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip']];
    if (!in_array($mime,$types[$ext]??[],true)) fail('Содержимое файла не соответствует расширению. Проверьте формат файла.');
    if (in_array($ext,['docx','xlsx'],true)) {
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) fail('Не удалось прочитать документ Office.');
        $valid=$zip->locateName('[Content_Types].xml')!==false && $zip->locateName($ext==='docx'?'word/document.xml':'xl/workbook.xml')!==false;
        // Inspect entries only. Never unpack an uploaded archive.
        $zip->close();
        if (!$valid) fail('Некорректный документ Word или Excel.');
    }
    if ($ext==='svg' && !str_contains(strtolower(file_get_contents($path,false,null,0,65536)),'<svg')) fail('Некорректный SVG.');
    return $mime;
}
function file_budget(int $userId,int $orderId,int $size): void {
    query('SELECT id FROM em_users WHERE id=? FOR UPDATE',[$userId]);
    query('SELECT id FROM em_orders WHERE id=? FOR UPDATE',[$orderId]);
    if ((int)query('SELECT COUNT(*) FROM em_files WHERE order_id=?',[$orderId])->fetchColumn()>=300 ||
        (int)query('SELECT COALESCE(SUM(bytes),0) FROM em_files WHERE user_id=?',[$userId])->fetchColumn()+$size>1024*1024*1024) {
        db()->rollBack(); fail('Лимит материалов достигнут: 300 файлов на проект или 1 ГБ на аккаунт. Можно передать ссылку на подборку.');
    }
}
function notify_admin(int $id): void {
    $c=config(); $to=$c['admin_email']??'';
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return;
    // Notification contains no brief or personal details; full information stays behind login.
    @mail($to,'ELIMORA: new project #'.$id,"Новая заявка №$id.\nОткройте https://elimora.ru/account.html",'From: ELIMORA <no-reply@elimora.ru>');
}

function purpose(array $d,array $u): string {
    $v=$d['purpose']??'material';
    if (!in_array($v,['material','review','contract','invoice','act','qr','final'],true)) fail('Некорректное назначение файла.');
    if ($v!=='material' && $u['role']!=='admin') fail('Результаты и документы добавляет администратор.',403);
    return $v;
}
function notify_project(array $o,string $body,bool $toAdmin=false): void {
    $ids=$toAdmin?query("SELECT id FROM em_users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN):[$o['user_id']];
    foreach ($ids as $uid) query('INSERT INTO em_notifications (user_id,order_id,body) VALUES (?,?,?)',[$uid,$o['id'],$body]);
    $mail=$toAdmin?config()['admin_email']:query('SELECT email FROM em_users WHERE id=?',[$o['user_id']])->fetchColumn();
    // Email contains no attachments, financial amounts or client material.
    @mail($mail,'ELIMORA: project update',"В вашем кабинете есть обновление по проекту №".$o['id'].".\nhttps://elimora.ru/account.html",'From: ELIMORA <no-reply@elimora.ru>');
}
function visible_file(array $f,array $o,array $u): bool {
    return $u['role']==='admin' || ($f['purpose']==='final'?(bool)$o['released']:($f['purpose']!=='review' || !empty($f['review_id'])));
}
