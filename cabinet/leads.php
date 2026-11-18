<?php
declare(strict_types=1);
if (!defined('ELIMORA_API')) { http_response_code(404); exit; }

function create_public_lead(): never {
    $d=post();
    if (($d['consent']??false)!==true) fail('Подтвердите согласие на обработку обращения.');
    if (field($d,'website_check',200,false)!=='') fail('Не удалось отправить форму. Напишите в Telegram.');
    $service=field($d,'service',20);
    if (!in_array($service,['maps','creative','logo','website','unsure'],true)) fail('Выберите услугу.');
    $contact=field($d,'contact',200);
    $valid=filter_var($contact,FILTER_VALIDATE_EMAIL) || preg_match('/^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/D',$contact) || preg_match('~^https://t\.me/[a-zA-Z][a-zA-Z0-9_]{4,31}/?$~D',$contact);
    $digits=preg_replace('/\D/','',$contact);
    $valid=$valid || (preg_match('/^[+\d\s()\-]+$/D',$contact) && strlen($digits)>=10 && strlen($digits)<=15);
    if (!$valid) fail('Укажите email, телефон с кодом страны или Telegram @username.');
    $brief=field($d,'brief',6000);
    $url=field($d,'url',1000,false);
    if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true))) fail('Ссылка должна начинаться с https:// или http://.');
    $source=field($d,'source',30);
    if (!in_array($source,['home','maps','visual','logos','websites','services'],true)) fail('Обновите страницу и повторите отправку.');
    $token=field($d,'request_key',64);
    if (!preg_match('/^[a-f0-9]{32,64}$/D',$token)) fail('Обновите страницу и повторите отправку.');
    $hash=hash('sha256',$token);
    $existing=query('SELECT id FROM em_leads WHERE request_hash=?',[$hash])->fetchColumn();
    if ($existing) reply(['ok'=>true,'id'=>(int)$existing]);
    limit('public-lead',10,3600);
    $consent=json_encode(['version'=>'privacy-2026-11-18','at'=>gmdate('c')],JSON_THROW_ON_ERROR);
    // No account or project is created; contact details remain admin-only.
    query('INSERT INTO em_leads (request_hash,service,contact,brief,url,source,consent) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$hash,$service,$contact,$brief,$url,$source,$consent]);
    $id=(int)db()->lastInsertId();
    $to=config()['admin_email']??'';
    if (filter_var($to,FILTER_VALIDATE_EMAIL)) @mail($to,'ELIMORA: new enquiry #'.$id,"Новое обращение №$id. Контакт и задача доступны в разделе «Обращения»:\nhttps://elimora.ru/account",'From: ELIMORA <no-reply@elimora.ru>');
    reply(['ok'=>true,'id'=>$id]);
}

function manage_public_leads(string $action,array $u): never {
    if ($u['role']!=='admin') fail('Доступ запрещён.',403);
    if ($action==='leads') {
        if ($_SERVER['REQUEST_METHOD']!=='GET') fail('Нужен GET-запрос.',405);
        reply(['leads'=>query('SELECT id,service,contact,brief,url,source,status,created_at,updated_at FROM em_leads ORDER BY created_at ASC,id ASC LIMIT 1000')->fetchAll()]);
    }
    $d=post();
    $id=filter_var($d['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $state=field($d,'status',20);
    if (!$id || !in_array($state,['new','contacted','closed'],true)) fail('Проверьте статус обращения.');
    if (!query('SELECT id FROM em_leads WHERE id=?',[$id])->fetchColumn()) fail('Обращение не найдено.',404);
    query('UPDATE em_leads SET status=? WHERE id=?',[$state,$id]);
    reply(['ok'=>true]);
}
