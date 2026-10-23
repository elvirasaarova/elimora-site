<?php
declare(strict_types=1);
define('ELIMORA_API',true);
require __DIR__.'/bootstrap.php';
try {
    $action=$_GET['action']??'status';
    if (!is_string($action)) fail('Некорректное действие.');
    if ($action==='status') {
        $exists=is_file(dirname(__DIR__,2).'/elimora-config.php');
        if (!$exists) reply(['ready'=>false,'csrf'=>$_SESSION['csrf']]);
        $ready=installed();
        if ($ready) migrate();
        $u=null;
        if ($ready && !empty($_SESSION['uid'])) $u=public_user(user());
        reply(['ready'=>$ready,'csrf'=>$_SESSION['csrf'],'user'=>$u]);
    }
    if ($action==='install') {
        $d=post(); $c=config();
        if (!hash_equals($c['install_key'],field($d,'key',256))) fail('Неверный ключ установки.',403);
        $lock=fopen(storage().'/install.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('install lock');
        if (installed()) fail('Установка уже выполнена.',409);
        $name=field($d,'name',120); $mail=email(['email'=>$c['admin_email']]); $pass=password($d);
        foreach (explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql) if (trim($sql)!=='') db()->exec($sql);
        db()->beginTransaction();
        query('INSERT INTO em_users (name,email,password_hash,role) VALUES (?,?,?,?)',[$name,$mail,password_hash($pass,PASSWORD_DEFAULT),'admin']);
        query("INSERT INTO em_settings (name,value) VALUES ('installed','1')");
        db()->commit();
        flock($lock,LOCK_UN); fclose($lock);
        reply(['ok'=>true]);
    }
    if (!installed()) fail('Кабинет ещё настраивается.',503);
    migrate();
    if ($action==='register') {
        $d=post(); limit('register',5,3600);
        $name=field($d,'name',120); $mail=email($d); $pass=password($d);
        if (($d['consent']??false)!==true) fail('Подтвердите согласие с политикой конфиденциальности.');
        try { query('INSERT INTO em_users (name,email,password_hash) VALUES (?,?,?)',[$name,$mail,password_hash($pass,PASSWORD_DEFAULT)]); }
        catch (PDOException $e) { if ($e->getCode()==='23000') fail('Не удалось создать аккаунт. Попробуйте войти или восстановить пароль.',409); throw $e; }
        $u=query('SELECT * FROM em_users WHERE email=?',[$mail])->fetch(); login_user($u);
        reply(['user'=>public_user($u),'csrf'=>$_SESSION['csrf']]);
    }
    if ($action==='login') {
        $d=post(); $mail=email($d); $pass=$d['password']??'';
        if (!is_string($pass) || strlen($pass)>200) fail('Проверьте пароль.');
        limit('login',30,900); limit('login-email',10,900,$mail);
        $u=query('SELECT * FROM em_users WHERE email=?',[$mail])->fetch();
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid=password_verify($pass,$u['password_hash']??$dummy);
        if (!$u || !$valid) fail('Неверный email или пароль.',401);
        if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) query('UPDATE em_users SET password_hash=? WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$u['id']]);
        login_user($u); reply(['user'=>public_user($u),'csrf'=>$_SESSION['csrf']]);
    }
    if ($action==='forgot') {
        $d=post(); $mail=email($d); limit('forgot',5,3600);
        $u=query('SELECT id FROM em_users WHERE email=?',[$mail])->fetch();
        if ($u) {
            $token=bin2hex(random_bytes(32));
            db()->beginTransaction();
            query('DELETE FROM em_resets WHERE user_id=? OR expires<?',[$u['id'],time()]);
            query('INSERT INTO em_resets (token_hash,user_id,expires) VALUES (?,?,?)',[hash('sha256',$token),$u['id'],time()+3600]);
            db()->commit();
            @mail($mail,'ELIMORA: password reset',"Для смены пароля откройте ссылку в течение часа:\nhttps://elimora.ru/account.html#reset=".$token."\nЕсли вы не запрашивали смену, проигнорируйте письмо.",'From: ELIMORA <no-reply@elimora.ru>');
        }
        reply(['ok'=>true,'message'=>'Если аккаунт существует, письмо отправлено. Проверьте также папку «Спам».']);
    }
    if ($action==='reset') {
        $d=post(); limit('reset',10,3600); $token=field($d,'token',64); $pass=password($d);
        db()->beginTransaction();
        $r=query('SELECT * FROM em_resets WHERE token_hash=? AND expires>? FOR UPDATE',[hash('sha256',$token),time()])->fetch();
        if (!$r) { db()->rollBack(); fail('Ссылка устарела. Запросите новое письмо.'); }
        query('UPDATE em_users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$r['user_id']]);
        query('DELETE FROM em_resets WHERE user_id=?',[$r['user_id']]); db()->commit(); $_SESSION=[];
        reply(['ok'=>true]);
    }
    $u=user();
    if ($action==='logout') { post(); $_SESSION=[]; session_regenerate_id(true); reply(['ok'=>true]); }
    if ($action==='notifications') {
        reply(['notifications'=>query('SELECT id,order_id,body,seen,created_at FROM em_notifications WHERE user_id=? ORDER BY id DESC LIMIT 30',[$u['id']])->fetchAll()]);
    }
    if ($action==='notifications-read') {post();query('UPDATE em_notifications SET seen=1 WHERE user_id=?',[$u['id']]);reply(['ok'=>true]);}
    if ($action==='payments') {
        $sql='SELECT p.*,o.company,o.title AS project_title FROM em_payments p JOIN em_orders o ON o.id=p.order_id';$args=[];
        if($u['role']!=='admin'){$sql.=' WHERE o.user_id=?';$args[]=$u['id'];}
        reply(['payments'=>query($sql.' ORDER BY p.id DESC LIMIT 500',$args)->fetchAll()]);
    }
    if ($action==='orders') {
        if ($_SERVER['REQUEST_METHOD']!=='GET') fail('Нужен GET-запрос.',405);
        $sql='SELECT o.id,o.company,o.title,o.kind,o.released,o.status,o.created_at,o.updated_at,u.name,u.email FROM em_orders o JOIN em_users u ON u.id=o.user_id';
        $args=[];
        if ($u['role']!=='admin') { $sql.=' WHERE o.user_id=?'; $args[]=$u['id']; }
        $sql.=' ORDER BY o.updated_at DESC,o.id DESC LIMIT 100';
        reply(['orders'=>query($sql,$args)->fetchAll(),'user'=>public_user($u)]);
    }
    if ($action==='files') {
        if ($_SERVER['REQUEST_METHOD']!=='GET') fail('Нужен GET-запрос.',405);
        $sql='SELECT f.id,f.order_id,f.original_name,f.bytes,f.mime,f.category,f.purpose,f.review_id,f.payment_id,o.released,f.user_id,f.created_at,o.company,o.title FROM em_files f JOIN em_orders o ON o.id=f.order_id';
        $args=[];
        if ($u['role']!=='admin') {$sql.=" WHERE o.user_id=? AND (f.purpose<>'review' OR f.review_id IS NOT NULL) AND (f.purpose<>'final' OR o.released=1)";$args[]=$u['id'];}
        reply(['files'=>query($sql.' ORDER BY f.id DESC LIMIT 1000',$args)->fetchAll()]);
    }
    if ($action==='create') {
        $d=post(); limit('create',15,3600,(string)$u['id']);
        $company=field($d,'company',200); $title=field($d,'title',200); $brief=field($d,'brief',24000);
        if (($d['consent']??false)!==true) fail('Подтвердите передачу информации для обработки заявки.');
        $draft=$d['answers']??[];
        if (!is_array($draft)) fail('Некорректные ответы анкеты.');
        $encoded=json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($encoded)>16000) fail('Слишком много данных анкеты.');
        // Store questionnaire answers as a proposal, never as a confirmed price or invoice.
        $proposal=json_encode(['answers'=>$draft,'consent'=>'privacy-2026-10','consent_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        query('INSERT INTO em_orders (user_id,company,title,brief,proposal) VALUES (?,?,?,?,?)',[$u['id'],$company,$title,$brief,$proposal]);
        $id=(int)db()->lastInsertId(); $kind=in_array('maps',$draft['tasks']??[],true)?'maps':'design';query('UPDATE em_orders SET kind=? WHERE id=?',[$kind,$id]); notify_admin($id); reply(['id'=>$id]);
    }
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) fail('Некорректный номер заявки.');
    $o=project($id,$u);
    require __DIR__.'/workflow.php';
    if ($action==='upload-begin') {
        $d=post(); limit('upload-start',400,3600,(string)$u['id']);
        $filePurpose=purpose($d,$u);
        $paymentId=filter_var($d['payment']??null,FILTER_VALIDATE_INT)?:null;
        if (in_array($filePurpose,['invoice','qr'],true) && (!$paymentId || !query('SELECT id FROM em_payments WHERE id=? AND order_id=?',[$paymentId,$id])->fetchColumn())) fail('Выберите платёж для счёта или QR-кода.');
        if ($filePurpose==='review' && (int)$o['released']) fail('Проект уже завершён. Для новой работы создайте отдельный проект.');
        $name=file_name(field($d,'name',200)); $category=file_category(field($d,'category',20));
        $size=filter_var($d['size']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>150*1024*1024]]);
        if (!$size) fail('Один файл — не больше 150 МБ.');
        $allowed=['media'=>['png','jpg','jpeg','mp4','mov'],'brand'=>['png','jpg','jpeg','webp','svg','pdf','zip'],'documents'=>['pdf','doc','docx','xls','xlsx','csv','txt']];
        if (!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),$allowed[$category],true)) fail('Проверьте формат файла. Фото — PNG/JPEG, видео — MP4/MOV.');
        foreach (query('SELECT id FROM em_uploads WHERE created_at<? LIMIT 100',[time()-86400])->fetchAll(PDO::FETCH_COLUMN) as $expired) {
            if (preg_match('/^[a-f0-9]{64}$/',$expired)) @unlink(storage().'/'.$expired.'.part');
            query('DELETE FROM em_uploads WHERE id=?',[$expired]);
        }
        db()->beginTransaction(); file_budget((int)$u['id'],$id,$size);
        $reserved=(int)query('SELECT COALESCE(SUM(expected_bytes),0) FROM em_uploads WHERE user_id=?',[$u['id']])->fetchColumn();
        $used=(int)query('SELECT COALESCE(SUM(bytes),0) FROM em_files WHERE user_id=?',[$u['id']])->fetchColumn();
        if ($reserved+$used+$size>1024*1024*1024 || (int)query('SELECT COUNT(*) FROM em_uploads WHERE user_id=?',[$u['id']])->fetchColumn()>=30) {
            db()->rollBack(); fail('Дождитесь завершения текущей загрузки или освободите место.');
        }
        $token=bin2hex(random_bytes(32));
        query('INSERT INTO em_uploads (id,order_id,user_id,original_name,category,purpose,payment_id,expected_bytes,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$token,$id,$u['id'],$name,$category,$filePurpose,$paymentId,$size,time()]);
        db()->commit(); reply(['upload'=>$token,'chunk_bytes'=>2*1024*1024]);
    }
    if ($action==='upload-chunk' || $action==='upload-cancel') {
        $d=post(); $token=field($d,'upload',64);
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) fail('Неверная загрузка.');
        db()->beginTransaction();
        $upload=query('SELECT * FROM em_uploads WHERE id=? AND user_id=? AND order_id=? FOR UPDATE',[$token,$u['id'],$id])->fetch();
        if (!$upload) { db()->rollBack(); fail('Загрузка не найдена. Выберите файл снова.',404); }
        $part=storage().'/'.$token.'.part';
        if ($action==='upload-cancel') {
            @unlink($part); query('DELETE FROM em_uploads WHERE id=?',[$token]);db()->commit();reply(['ok'=>true]);
        }
        $chunk=$_FILES['chunk']??null; $offset=filter_var($d['offset']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if (!$chunk || is_array($chunk['error']) || $chunk['error']!==UPLOAD_ERR_OK || $offset===false || $offset!==(int)$upload['received_bytes']) {
            db()->rollBack(); fail('Фрагмент не загрузился. Выберите файл снова.');
        }
        $length=filesize($chunk['tmp_name']);
        if ($length<1 || $length>2*1024*1024 || $offset+$length>(int)$upload['expected_bytes'] || (is_file($part)?filesize($part):0)!==$offset) {
            db()->rollBack(); fail('Неверный размер фрагмента.');
        }
        $target=fopen($part,'ab');$source=fopen($chunk['tmp_name'],'rb');
        if (!$target || !$source) throw new RuntimeException('chunk open');
        chmod($part,0600);$written=stream_copy_to_stream($source,$target);fclose($source);fclose($target);
        if ($written!==$length) throw new RuntimeException('chunk write');
        $received=$offset+$length;
        query('UPDATE em_uploads SET received_bytes=? WHERE id=?',[$received,$token]);db()->commit();
        if ($received<(int)$upload['expected_bytes']) reply(['received'=>$received,'complete'=>false]);
        $mime=file_mime($part,$upload['original_name'],$upload['category']);
        if ($upload['purpose']==='qr' && !in_array($mime,['image/jpeg','image/png'],true)) fail('QR-код — PNG или JPEG.');
        if ($upload['purpose']==='review' && !in_array($mime,['image/jpeg','image/png','image/webp','application/pdf','video/mp4','video/quicktime'],true)) fail('Для согласования — JPG, PNG, WEBP, PDF или MP4/MOV. Финальные архивы можно добавить отдельным согласуемым PDF со ссылкой в комментарии.');
        db()->beginTransaction();file_budget((int)$u['id'],$id,$received);
        // Row lock and exact ownership prevent double finalization of a temporary upload.
        if (!query('SELECT id FROM em_uploads WHERE id=? FOR UPDATE',[$token])->fetchColumn()) {db()->rollBack();fail('Загрузка уже завершена.',409);}
        if (!rename($part,storage().'/'.$token)) throw new RuntimeException('chunk finalize');
        try {
            query('INSERT INTO em_files (order_id,user_id,original_name,storage_name,bytes,mime,category,purpose,payment_id) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$u['id'],$upload['original_name'],$token,$received,$mime,$upload['category'],$upload['purpose'],$upload['payment_id']]);
            query('DELETE FROM em_uploads WHERE id=?',[$token]);query('UPDATE em_orders SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);db()->commit();
        } catch (Throwable $e) { if(db()->inTransaction())db()->rollBack();@rename(storage().'/'.$token,$part);throw $e; }
        reply(['received'=>$received,'complete'=>true]);
    }
    if ($action==='order') {
        $owner=query('SELECT name,email FROM em_users WHERE id=?',[$o['user_id']])->fetch();
        $o['messages']=query('SELECT m.id,m.body,m.created_at,u.name,u.role FROM em_messages m JOIN em_users u ON u.id=m.user_id WHERE m.order_id=? ORDER BY m.id',[$id])->fetchAll();
        $files=query('SELECT id,original_name,bytes,mime,category,purpose,review_id,payment_id,user_id,created_at FROM em_files WHERE order_id=? ORDER BY id',[$id])->fetchAll();
        $o['files']=array_values(array_filter($files,fn($f)=>visible_file($f,$o,$u)));
        $o['reviews']=query('SELECT * FROM em_reviews WHERE order_id=? ORDER BY id DESC',[$id])->fetchAll();
        $o['payments']=query('SELECT * FROM em_payments WHERE order_id=? ORDER BY id DESC',[$id])->fetchAll();
        $latestBrief=query('SELECT id,payload,created_at FROM em_briefs WHERE order_id=? ORDER BY id DESC LIMIT 1',[$id])->fetch();
        $o['detailed_brief']=$latestBrief?['id'=>$latestBrief['id'],'answers'=>json_decode($latestBrief['payload'],true),'created_at'=>$latestBrief['created_at']]:null;
        $o['owner']=$owner; $o['proposal']=json_decode($o['proposal'],true); reply(['order'=>$o]);
    }
    if ($action==='message') {
        $d=post(); limit('message',60,3600,(string)$u['id']); $body=field($d,'body',6000);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],$body]);
        query('UPDATE em_orders SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$id]); reply(['ok'=>true]);
    }
    if ($action==='status-change') {
        $d=post(); if ($u['role']!=='admin') fail('Доступ запрещён.',403);
        $status=field($d,'status',30);
        $labels=['new'=>'Новая заявка','discussion'=>'Обсуждаем задачу','materials'=>'Жду материалы','work'=>'В работе','review'=>'На согласовании','done'=>'Готово'];
        if (in_array($status,['review','done'],true)) fail('Используйте отправку на согласование или выдачу результата.');
        $labels+=['payment'=>'Оплата','access'=>'Доступы Яндекса','delivery'=>'Выдача результата'];
        if (!isset($labels[$status])) fail('Некорректный статус.');
        db()->beginTransaction();
        query('UPDATE em_orders SET status=? WHERE id=?',[$status,$id]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],'Статус проекта: '.$labels[$status]]);
        db()->commit(); reply(['ok'=>true]);
    }
    if ($action==='upload') {
        $d=post(); limit('upload',400,3600,(string)$u['id']);
        $f=$_FILES['file']??null;
        if (!$f || !is_array($f) || is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK) fail('Файл не загрузился. Максимальный размер — 15 МБ.');
        $size=filesize($f['tmp_name']);
        if ($size<1 || $size>15*1024*1024) fail('Файл должен быть от 1 байта до 15 МБ.');
        $name=file_name((string)$f['name']);
        $category=file_category((string)($d['category']??'documents'));
        $mime=file_mime($f['tmp_name'],$name,$category);
        $storageName=bin2hex(random_bytes(32)); $path=storage().'/'.$storageName;
        db()->beginTransaction();
        file_budget((int)$u['id'],$id,$size);
        if (!move_uploaded_file($f['tmp_name'],$path)) { db()->rollBack(); throw new RuntimeException('upload move'); }
        chmod($path,0600);
        try {
            query('INSERT INTO em_files (order_id,user_id,original_name,storage_name,bytes,mime,category) VALUES (?,?,?,?,?,?,?)',[$id,$u['id'],$name,$storageName,$size,$mime,$category]);
            query('UPDATE em_orders SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$id]); db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); @unlink($path); throw $e; }
        reply(['ok'=>true]);
    }
    if ($action==='download' || $action==='preview' || $action==='delete-file') {
        $fid=filter_var($_GET['file']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $f=query('SELECT * FROM em_files WHERE id=? AND order_id=?',[$fid?:0,$id])->fetch();
        if (!$f || !visible_file($f,$o,$u) || !preg_match('/^[a-f0-9]{64}$/',$f['storage_name'])) fail('Файл не найден.',404);
        $path=storage().'/'.$f['storage_name'];
        if ($u['role']!=='admin' && $f['purpose']==='review' && $action==='download' && !(int)$o['released']) fail('Скачивание финальных материалов откроется после выдачи результата.',403);
        if ($action==='delete-file') {
            post(); if ($u['role']!=='admin' && (int)$f['user_id']!==(int)$u['id']) fail('Можно удалить только свои материалы.',403);
            if (!empty($f['review_id'])) fail('Файл опубликованной версии остаётся в истории. Добавьте новую версию вместо удаления.',409);
            if (is_file($path) && !unlink($path)) throw new RuntimeException('file delete');
            query('DELETE FROM em_files WHERE id=?',[$fid]); reply(['ok'=>true]);
        }
        if (!is_file($path)) fail('Файл не найден.',404);
        session_write_close();
        if ($action==='preview') {
            if (!in_array($f['mime'],['image/jpeg','image/png','image/webp','application/pdf','video/mp4','video/quicktime'],true)) fail('Предпросмотр недоступен.',415);
            header('Content-Length: '.filesize($path));header('Content-Type: '.$f['mime']);header('Content-Disposition: inline');readfile($path);exit;
        }
        header('Content-Length: '.filesize($path));
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"material\"; filename*=UTF-8''".rawurlencode($f['original_name']));
        readfile($path); exit;
    }
    fail('Действие не найдено.',404);
} catch (Throwable $e) {
    error_log('ELIMORA cabinet: '.get_class($e).' code '.$e->getCode());
    reply(['error'=>'Не удалось выполнить действие. Попробуйте позже или напишите Эльвире в Telegram.'],503);
}
