<?php
declare(strict_types=1);
if (!defined('ELIMORA_API')) {http_response_code(404);exit;}
if ($action==='brief-download') {
    $brief=query('SELECT payload,created_at FROM em_briefs WHERE order_id=? ORDER BY id DESC LIMIT 1',[$id])->fetch();
    if(!$brief)fail('Бриф ещё не отправлен.',404);
    $fields=['products'=>'Услуги и товары','goals'=>'Задачи проекта','audience'=>'Аудитория','messages'=>'Что рассказать клиентам','brand'=>'Стиль','links'=>'Ссылки','references'=>'Примеры','constraints'=>'Требования','deadline'=>'Сроки и контакт'];
    $answers=json_decode($brief['payload'],true);$lines=['Бриф: '.$o['company'],'Проект №'.$id.': '.$o['title'],'Отправлен: '.$brief['created_at']];
    foreach($fields as $key=>$label)$lines[]= "\n".$label."\n".($answers[$key]??'');
    header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="brief-'.$id.'.txt"');echo implode("\n",$lines);exit;
}
// Included only after authentication and ownership checks in api.php.
if (in_array($action,['project-settings','review-publish','review-answer','payment-create','payment-update','release','access-report','brief-submit','link-add','link-delete'],true)) {
    $d=post();
    $admin=$u['role']==='admin';
    if (!in_array($action,['review-answer','access-report','brief-submit'],true) && $action!=='payment-update' && !$admin) fail('Доступ запрещён.',403);
    if ($action==='link-add') {
        $url=field($d,'url',2000);$title=field($d,'title',200);$type=asset_type($d);
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS))fail('Укажите публичную ссылку https:// без логина и пароля.');
        if($o['status']==='done')fail('Проект завершён.');
        if((int)query('SELECT COUNT(*) FROM em_links WHERE order_id=?',[$id])->fetchColumn()>=100)fail('Не больше 100 ссылок на проект.');
        query('INSERT INTO em_links (order_id,asset_type,title,url) VALUES (?,?,?,?)',[$id,$type,$title,$url]);reply(['ok'=>true]);
    }
    if ($action==='link-delete') {
        $link=filter_var($d['link']??null,FILTER_VALIDATE_INT);
        $row=query('SELECT * FROM em_links WHERE id=? AND order_id=?',[$link,$id])->fetch();
        if(!$row)fail('Ссылка не найдена.',404);
        if($row['review_id'])fail('Ссылка опубликованной версии остаётся в истории.',409);
        query('DELETE FROM em_links WHERE id=?',[$link]);reply(['ok'=>true]);
    }
    if ($action==='brief-submit') {
        if ($admin) fail('Бриф заполняет клиент.',403);
        if (!query("SELECT id FROM em_payments WHERE order_id=? AND status='paid'",[$id])->fetchColumn()) fail('Подробный бриф откроется после подтверждения первого платежа.',403);
        if (in_array($o['status'],['done','review','access','delivery'],true)) fail('На этом этапе бриф уже зафиксирован. Уточнения отправьте в обсуждении.',409);
        $values=[];
        foreach (['products','goals','audience','messages','brand','links','references','constraints','deadline'] as $key) $values[$key]=field($d,$key,4000,in_array($key,['products','goals','audience'],true));
        db()->beginTransaction();query('SELECT id FROM em_orders WHERE id=? FOR UPDATE',[$id]);
        query('INSERT INTO em_briefs (order_id,payload) VALUES (?,?)',[$id,json_encode($values,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        if (in_array($o['status'],['new','payment','discussion'],true)) query("UPDATE em_orders SET status='materials' WHERE id=?",[$id]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],'Клиент заполнил подробный бриф.']);
        db()->commit();notify_project($o,'Клиент заполнил подробный бриф.',true);reply(['ok'=>true]);
    }
    if ($action==='project-settings') {
        $kind=field($d,'kind',20);if(!in_array($kind,['design','maps','website','combined'],true))fail('Выберите тип проекта.');
        $note=field($d,'access_note',3000,false);
        $start=array_key_exists('planned_start',$d)?project_date($d,'planned_start'):$o['planned_start'];$due=array_key_exists('due_date',$d)?project_date($d,'due_date'):$o['due_date'];
        if($start&&$due&&$due<$start)fail('Дата завершения должна быть не раньше старта.');
        query('UPDATE em_orders SET kind=?,access_note=?,planned_start=?,due_date=? WHERE id=?',[$kind,$note,$start,$due,$id]);reply(['ok'=>true]);
    }
    if ($action==='review-publish') {
        $note=field($d,'note',6000,false);
        db()->beginTransaction();$fresh=query('SELECT status FROM em_orders WHERE id=? FOR UPDATE',[$id])->fetch();
        if($fresh['status']==='done'){db()->rollBack();fail('Проект уже завершён.');}
        if (query("SELECT id FROM em_reviews WHERE order_id=? AND state='pending'",[$id])->fetchColumn()) {db()->rollBack();fail('Сначала дождитесь решения по текущей версии.');}
        $files=query("SELECT id FROM em_files WHERE order_id=? AND purpose='review' AND review_id IS NULL FOR UPDATE",[$id])->fetchAll(PDO::FETCH_COLUMN);
        $links=query('SELECT id FROM em_links WHERE order_id=? AND review_id IS NULL FOR UPDATE',[$id])->fetchAll(PDO::FETCH_COLUMN);
        if (!$files && !$links) {db()->rollBack();fail('Сначала добавьте файлы новой версии.');}
        query('INSERT INTO em_reviews (order_id,note) VALUES (?,?)',[$id,$note]);$review=(int)db()->lastInsertId();
        query("UPDATE em_files SET review_id=? WHERE order_id=? AND purpose='review' AND review_id IS NULL",[$review,$id]);
        query('UPDATE em_links SET review_id=? WHERE order_id=? AND review_id IS NULL',[$review,$id]);
        query("UPDATE em_orders SET status='review',released=0,access_reported=0 WHERE id=?",[$id]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],'Отправлена версия на согласование. '.$note]);
        db()->commit();notify_project($o,'Новые материалы на согласовании.');reply(['ok'=>true]);
    }
    if ($action==='review-answer') {
        if ($admin) fail('Решение по версии принимает клиент.',403);
        $decision=field($d,'decision',20);if(!in_array($decision,['approved','changes'],true))fail('Выберите решение.');
        $review=filter_var($d['review']??null,FILTER_VALIDATE_INT);$feedback=field($d,'feedback',6000,$decision==='changes');
        db()->beginTransaction();query('SELECT id FROM em_orders WHERE id=? FOR UPDATE',[$id]);
        $r=query('SELECT * FROM em_reviews WHERE id=? AND order_id=? FOR UPDATE',[$review,$id])->fetch();
        $latest=(int)query('SELECT MAX(id) FROM em_reviews WHERE order_id=?',[$id])->fetchColumn();
        if (!$r || $r['state']!=='pending' || $latest!==(int)$review) {db()->rollBack();fail('По этой версии уже принято решение. Обновите страницу.',409);}
        query('UPDATE em_reviews SET state=?,feedback=?,decided_at=CURRENT_TIMESTAMP WHERE id=?',[$decision,$feedback,$review]);
        $fresh=project($id,$u);$next=$decision==='changes'?'work':(in_array($fresh['kind'],['maps','combined'],true)?'access':'delivery');
        query('UPDATE em_orders SET status=? WHERE id=?',[$next,$id]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],($decision==='approved'?'Версия согласована. ':'Правки по версии: ').$feedback]);
        db()->commit();notify_project($o,$decision==='approved'?'Клиент согласовал версию.':'Клиент прислал правки.',true);reply(['ok'=>true]);
    }
    if ($action==='payment-create') {
        $title=field($d,'title',200);$note=field($d,'note',6000,false);
        $amount=filter_var($d['amount_minor']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000000000]]);
        if (!$amount)fail('Укажите сумму платежа в рублях и копейках.');
        db()->beginTransaction();$fresh=query('SELECT status FROM em_orders WHERE id=? FOR UPDATE',[$id])->fetch();
        if($fresh['status']==='done'){db()->rollBack();fail('Проект завершён. Создайте новый проект для новой работы.');}
        query('INSERT INTO em_payments (order_id,title,amount_minor,note) VALUES (?,?,?,?)',[$id,$title,$amount,$note]);
        $payment=(int)db()->lastInsertId();if($fresh['status']==='new')query("UPDATE em_orders SET status='payment' WHERE id=?",[$id]);db()->commit();notify_project($o,'Добавлен счёт на оплату.');reply(['payment'=>$payment]);
    }
    if ($action==='payment-update') {
        $payment=filter_var($d['payment']??null,FILTER_VALIDATE_INT);$state=field($d,'status',20);
        if (($admin && !in_array($state,['paid','pending','cancelled'],true)) || (!$admin && $state!=='reported'))fail('Недоступное действие.',403);
        db()->beginTransaction();query('SELECT id FROM em_orders WHERE id=? FOR UPDATE',[$id]);$p=query('SELECT * FROM em_payments WHERE id=? AND order_id=? FOR UPDATE',[$payment,$id])->fetch();
        if(!$p){db()->rollBack();fail('Платёж не найден.',404);}
        if (!$admin && $p['status']!=='pending') {db()->rollBack();fail('Платёж уже отмечен. Обновите страницу.',409);}
        query('UPDATE em_payments SET status=? WHERE id=?',[$state,$payment]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],'Платёж «'.$p['title'].'»: '.(['paid'=>'получение подтверждено','pending'=>'ожидает оплаты','cancelled'=>'отменён','reported'=>'клиент сообщил об оплате'][$state])]);
        if ($admin && $state==='paid' && $o['status']==='payment' && !query("SELECT id FROM em_payments WHERE order_id=? AND status IN ('pending','reported')",[$id])->fetchColumn()) query("UPDATE em_orders SET status='discussion' WHERE id=?",[$id]);
        db()->commit();notify_project($o,$state==='reported'?'Клиент сообщил об оплате.':'Статус платежа обновлён.',!$admin);reply(['ok'=>true]);
    }
    if ($action==='access-report') {
        if($admin || $o['status']!=='access')fail('Сейчас подтверждение доступа не требуется.',409);
        query('UPDATE em_orders SET access_reported=1 WHERE id=?',[$id]);notify_project($o,'Клиент сообщил о предоставлении доступа Яндекса.',true);reply(['ok'=>true]);
    }
    if ($action==='release') {
        db()->beginTransaction();$fresh=query('SELECT * FROM em_orders WHERE id=? FOR UPDATE',[$id])->fetch();
        $r=query('SELECT state FROM em_reviews WHERE order_id=? ORDER BY id DESC LIMIT 1',[$id])->fetchColumn();
        if($r!=='approved'){db()->rollBack();fail('Сначала клиент должен согласовать последнюю версию.');}
        if(query("SELECT id FROM em_payments WHERE order_id=? AND status IN ('pending','reported')",[$id])->fetchColumn()){db()->rollBack();fail('Сначала подтвердите поступление всех платежей или отмените ненужные счета.');}
        if(in_array($fresh['kind'],['maps','combined'],true) && $fresh['status']!=='delivery'){db()->rollBack();fail('Сначала проверьте доступ, завершите размещение и переведите проект на этап выдачи.');}
        query("UPDATE em_orders SET released=1,status='done',completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP) WHERE id=?",[$id]);
        query('INSERT INTO em_messages (order_id,user_id,body) VALUES (?,?,?)',[$id,$u['id'],'Проект завершён. Финальные материалы доступны для скачивания.']);
        db()->commit();notify_project($o,'Проект завершён: можно скачать материалы.');reply(['ok'=>true]);
    }
}
