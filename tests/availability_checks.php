<?php
define('ELIMORA_API',true);
require dirname(__DIR__).'/cabinet/availability.php';
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
$empty=work_forecast([],'2026-10-23');
check($empty['start']==='2026-10-26'&&$empty['finish']==='2026-10-30','Friday rolls to Monday and five weekdays');
check(work_window('2026-10-24')===['2026-10-26','2026-10-27','2026-10-28','2026-10-29','2026-10-30'],'weekend is skipped');
$manual=['status'=>'work','work_started_at'=>'2026-10-26 09:00:00','planned_start'=>'2026-10-26','due_date'=>'2026-10-30'];
// Future plans are reservations, not current active work.
$plan=['status'=>'materials','work_started_at'=>null,'planned_start'=>'2026-10-26','due_date'=>'2026-10-30'];
$full=work_forecast([$plan,$plan,$plan],'2026-10-23');
check($full['start']==='2026-11-02'&&$full['finish']==='2026-11-06','three simultaneous reservations fill the first week');
$queued=['status'=>'materials','work_started_at'=>null,'planned_start'=>null,'due_date'=>null];
$six=work_forecast(array_fill(0,6,$queued),'2026-10-23');
check($six['start']==='2026-11-09','FIFO backlog occupies two batches of three');
$old=['status'=>'work','work_started_at'=>'2026-10-01 09:00:00','planned_start'=>null,'due_date'=>null];
$overdue=work_forecast([$old,$old,$old],'2026-10-23');
check($overdue['start']==='2026-10-30','unfinished overdue projects keep conservative capacity');
check($full['capacity']===3&&$full['duration_workdays']===5,'capacity and duration');
foreach($full['calendar'] as $day)check($day['free']>=0&&$day['occupied']<=3,'public slot bounds');
check(!array_key_exists('projects',$full),'no client records exposed');
echo "Passed: three production slots, five workdays, weekends, planned dates, FIFO backlog, overdue reservations and public aggregation\n";

check(count($empty['calendar'])===40,'calendar covers several weeks');
check(count($empty['available_starts'])===35,'free starts are selectable');
check($full['available_starts'][0]['start']==='2026-11-02','full week cannot be selected');
foreach($full['available_starts'] as $option) {
    $window=work_window($option['start']);
    check(end($window)===$option['finish'],'selection finish uses five workdays');
    foreach($window as $date)check($date>'2026-10-30','selected work window avoids full capacity');
}
