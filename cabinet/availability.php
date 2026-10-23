<?php
declare(strict_types=1);
if (!defined('ELIMORA_API')) {http_response_code(404);exit;}
// Capacity is an estimate of Monday-Friday production, not a booking guarantee.
function next_workday(string $date): string {
    $day=new DateTimeImmutable($date,new DateTimeZone('Asia/Saigon'));
    while ((int)$day->format('N')>5) $day=$day->modify('+1 day');
    return $day->format('Y-m-d');
}
function work_window(string $start,int $count=5): array {
    $date=next_workday($start);$days=[];
    for($i=0;$i<$count;$i++) {$days[]=$date;$date=next_workday((new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'));}
    return $days;
}
function work_forecast(array $projects,string $today): array {
    $capacity=3;$duration=5;$busy=[];$unplanned=[];$active=0;
    $earliest=next_workday((new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
    $reserve=function(string $start,string $end)use(&$busy):void {
        $date=next_workday($start);
        // Bound pathological manual dates; normal projects are a few workdays.
        for($n=0;$date<=$end&&$n<1300;$n++) {$busy[$date]=($busy[$date]??0)+1;$date=next_workday((new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'));}
    };
    foreach($projects as $p){
        $started=!empty($p['work_started_at'])||in_array($p['status'],['work','review','access','delivery'],true);
        if($started)$active++;
        $start=$p['planned_start']??null;
        if($started){$start=substr($p['work_started_at']??$today,0,10);if($start>$today)$start=$today;}
        if(!$start){$unplanned[]=$p;continue;}
        $end=$p['due_date']??null;
        if(!$end)$end=work_window($start,$duration)[$duration-1];
        // An unfinished overdue project keeps a conservative production slot.
        if($end<$today){$start=$today;$end=work_window($today,$duration)[$duration-1];}
        if($start<$today)$start=$today;
        $reserve($start,$end);
    }
    $find=function()use(&$busy,$earliest,$duration,$capacity):?array {
        $candidate=$earliest;
        for($i=0;$i<1300;$i++){
            $window=work_window($candidate,$duration);$fits=true;
            foreach($window as $date)if(($busy[$date]??0)>=$capacity){$fits=false;break;}
            if($fits)return $window;
            $candidate=next_workday((new DateTimeImmutable($candidate))->modify('+1 day')->format('Y-m-d'));
        }
        return null;
    };
    // Projects arrive from SQL in original request order. Unpaid requests do not reserve capacity.
    foreach($unplanned as $p){$window=$find();if(!$window)break;$reserve($window[0],$window[$duration-1]);}
    $window=$find();$calendar=[];$availableStarts=[];
    foreach(work_window($earliest,40) as $date)$calendar[]=['date'=>$date,'occupied'=>min($capacity,$busy[$date]??0),'free'=>max(0,$capacity-($busy[$date]??0))];
    foreach(work_window($earliest,35) as $candidate){
        $days=work_window($candidate,$duration);$fits=true;
        foreach($days as $date)if(($busy[$date]??0)>=$capacity){$fits=false;break;}
        if($fits)$availableStarts[]=['start'=>$days[0],'finish'=>$days[$duration-1]];
    }
    return ['capacity'=>$capacity,'duration_workdays'=>$duration,'active_projects'=>$active,'reserved_projects'=>count($projects),'start'=>$window[0]??null,'finish'=>$window[$duration-1]??null,'calendar'=>$calendar,'available_starts'=>$availableStarts,'timezone'=>'Asia/Saigon','weekdays'=>'Monday-Friday'];
}
