const endpoint = '/cabinet/api.php?action=';

export async function sendLead(payload, request = fetch) {
 const controller = new AbortController();
 const timer = setTimeout(() => controller.abort(), 20000);
 try {
  const status = await request(endpoint+'status', {credentials:'same-origin', cache:'no-store', signal:controller.signal});
  const session = await status.json();
  if (!status.ok || !session.ready || !session.csrf) throw Error('Приём заявок временно недоступен. Напишите мне в Telegram.');
  const response = await request(endpoint+'lead-create', {method:'POST', credentials:'same-origin', signal:controller.signal, headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf}, body:JSON.stringify(payload)});
  const result = await response.json();
  if (!response.ok || !result.ok || !Number.isSafeInteger(result.id) || result.id<1) throw Error(result.error || 'Не удалось сохранить заявку. Повторите отправку или напишите в Telegram.');
  return result.id;
 } finally { clearTimeout(timer); }
}

if (typeof document !== 'undefined') {
 const form = document.querySelector('[data-public-lead]');
 if (form) {
  const bytes = crypto.getRandomValues(new Uint8Array(24));
  const key = Array.from(bytes, v=>v.toString(16).padStart(2,'0')).join('');
  let submitted = false;
  form.addEventListener('submit', async event => {
   event.preventDefault();
   if (submitted) return;
   const button = form.querySelector('[type="submit"]');
   const status = form.querySelector('[role="status"]');
   const values = Object.fromEntries(new FormData(form));
   button.disabled = true; status.textContent = 'Сохраняю заявку…';
   let brief = values.brief;
   try {
    const catalogue = JSON.parse(document.getElementById('catalogue-data')?.textContent || '[]');
    const selected = JSON.parse(sessionStorage.getItem('elimora-cart') || '[]');
    if (Array.isArray(selected)) {
     const names = catalogue.filter(p=>selected.includes(p[0])).map(p=>p[1]);
     if (names.length) brief += '\n\nВыбранные услуги: '+names.join(', ');
    }
   } catch { /* A blocked browser store must not prevent a first enquiry. */ }
   try {
    const payload = {...values, brief, consent:values.consent==='on', source:form.dataset.source};
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(payload))));
    const requestKey = key + Array.from(digest.slice(0,8),v=>v.toString(16).padStart(2,'0')).join('');
    const id = await sendLead({...payload, request_key:requestKey});
    submitted = true;
    status.textContent = 'Заявка №'+id+' сохранена. Я свяжусь с вами по указанному контакту. Для переписки в Telegram можно написать мне прямо сейчас.';
    button.textContent = 'Заявка отправлена ✓';
    // The goal records a confirmed save only; contacts and the brief never enter analytics.
    try { if (typeof window.ym === 'function') window.ym(113823074,'reachGoal','request_sent'); } catch {}
   } catch (error) {
    status.textContent = error.name==='AbortError'?'Сервер не успел ответить. Повторите отправку: заявка не продублируется. Можно также написать в Telegram.':error.message;
   } finally { if (!submitted) button.disabled = false; }
  });
 }
}
