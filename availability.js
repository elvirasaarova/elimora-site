const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const format=d=>new Date(d+'T12:00:00').toLocaleDateString('ru-RU',{day:'numeric',month:'long'});
const choiceKey='elimora-start-choice';
function saveChoice(choice){try{sessionStorage.setItem(choiceKey,JSON.stringify(choice))}catch{}}
function readChoice(){try{return JSON.parse(sessionStorage.getItem(choiceKey)||'null')}catch{return null}}
export function withPreferredStart(draft){
 const choice=readChoice();
 if(!choice||!/^\d{4}-\d{2}-\d{2}$/.test(choice.start)||!/^\d{4}-\d{2}-\d{2}$/.test(choice.finish))return draft;
 return {...draft,answers:{...draft.answers,preferred_start:choice.start,preferred_finish:choice.finish},brief:draft.brief+'\n\nЖелаемая дата старта: '+format(choice.start)+' ('+choice.start+').\nОриентировочная готовность: '+format(choice.finish)+'. Дата требует подтверждения после оплаты и получения материалов.'};
}
export async function loadAvailability(card){
 if(!card)return;
 const fallback=()=>{card.innerHTML='<span class="eyebrow">ПЛАНИРУЕМ СТАРТ</span><h3>Дату начала <em>уточним вместе.</em></h3><p>Сейчас календарь недоступен. Вы можете оставить заявку и обсудить удобную дату в чате.</p><p class="small">Обычно 5 рабочих дней. Работа начинается после оплаты и получения материалов.</p>';try{sessionStorage.removeItem(choiceKey)}catch{}};
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
 try{
  const response=await fetch('cabinet/api.php?action=availability',{credentials:'omit',cache:'no-store',signal:controller.signal});
  if(!response.ok)throw Error('availability');
  const r=await response.json();if(!card.isConnected)return;
  if(!r.start||r.capacity!==3||r.duration_workdays!==5||!Array.isArray(r.available_starts)||!Array.isArray(r.calendar)||!r.calendar.length){fallback();return;}
  const options=r.available_starts.filter(d=>/^\d{4}-\d{2}-\d{2}$/.test(d.start)&&/^\d{4}-\d{2}-\d{2}$/.test(d.finish));
  if(!options.length){fallback();return;}
  const saved=readChoice();let selected=options.find(d=>d.start===saved?.start)||options[0];
  const months=[...new Set(r.calendar.map(d=>d.date.slice(0,7)))];let monthIndex=Math.max(0,months.indexOf(selected.start.slice(0,7)));
  const render=()=>{
   saveChoice(selected);
   const month=months[monthIndex],first=new Date(month+'-01T12:00:00'),offset=(first.getDay()+6)%7;
   const count=new Date(first.getFullYear(),first.getMonth()+1,0).getDate();
   const range=[];for(let d=new Date(selected.start+'T12:00:00');range.length<5;d.setDate(d.getDate()+1))if(d.getDay()!==0&&d.getDay()!==6)range.push(d.toISOString().slice(0,10));
   const days=Array.from({length:offset},()=>'<span class="booking-empty" aria-hidden="true"></span>').join('')+Array.from({length:count},(_,i)=>{
    const date=month+'-'+String(i+1).padStart(2,'0'),option=options.find(o=>o.start===date);
    const active=date===selected.start,inside=range.includes(date),weekend=[0,6].includes(new Date(date+'T12:00:00').getDay());
    return '<button type="button" class="booking-day '+(active?'selected ':inside?'in-range ':'')+(option?'available':'unavailable')+'" data-start="'+date+'" '+(!option?'disabled ':'')+'aria-pressed="'+active+'" aria-label="'+esc(format(date))+', '+(option?'можно выбрать для старта':weekend?'выходной':'старт недоступен')+'"><strong>'+String(i+1)+'</strong><small>'+(option?'свободно':weekend?'выходной':'—')+'</small></button>';
   }).join('');
   card.innerHTML='<div class="booking-heading"><div><span class="eyebrow">ПЛАНИРУЕМ СТАРТ</span><h3>Когда начнём <em>ваш проект?</em></h3><p>Нажмите на свободный день. Ближайшая доступная дата уже выбрана.</p></div></div><div class="booking-layout"><div class="booking-month"><div class="booking-month-bar"><button type="button" class="booking-month-switch" data-month="-1" aria-label="Предыдущий месяц" '+(monthIndex===0?'disabled':'')+'><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><h4>'+esc(first.toLocaleDateString('ru-RU',{month:'long',year:'numeric'}))+'</h4><button type="button" class="booking-month-switch" data-month="1" aria-label="Следующий месяц" '+(monthIndex===months.length-1?'disabled':'')+'><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></div><div class="booking-weekdays" aria-hidden="true">'+['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map(d=>'<span>'+d+'</span>').join('')+'</div><div class="booking-days">'+days+'</div><div class="booking-legend"><span><i class="free"></i> Можно начать</span><span><i class="chosen"></i> Ваш выбор</span><span><i class="busy"></i> Недоступно</span></div></div><div class="booking-selection" role="status" aria-live="polite"><span class="booking-tag">ВАШ ПЛАН</span><div><span>Начало проекта</span><strong>'+esc(format(selected.start))+'</strong></div><span class="booking-duration">5 рабочих дней · пн–пт</span><div><span>Ориентировочная готовность</span><strong>'+esc(format(selected.finish))+'</strong></div><p>Выбранная дата перенесётся в заявку.</p><p class="small">Это пожелание, а не бронь. Подтверждаю дату после оплаты и получения материалов. Для сайта и больших задач сроки обсуждаем отдельно.</p></div></div>';
   card.querySelectorAll('[data-start]').forEach(b=>b.onclick=()=>{const option=options.find(o=>o.start===b.dataset.start);if(!option)return;selected=option;render();card.querySelector('[data-start="'+option.start+'"]')?.focus()});
   card.querySelectorAll('[data-month]').forEach(b=>b.onclick=()=>{monthIndex=Math.max(0,Math.min(months.length-1,monthIndex+Number(b.dataset.month)));render();card.querySelector('[data-month="'+b.dataset.month+'"]')?.focus()});
  };
  render();
 }catch{if(card.isConnected)fallback()}finally{clearTimeout(timer)}
}
