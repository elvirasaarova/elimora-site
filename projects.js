const sectors={"food": "Кафе и рестораны", "beauty": "Красота и уход", "auto": "Автосервисы", "plants": "Цветы и растения", "building": "Строительство, недвижимость и ремонт", "education": "Детские центры и образование", "hotels": "Отели и базы отдыха", "retail": "Магазины и торговля", "business": "Услуги для бизнеса", "medical": "Медицина и стоматология"};
const area=document.getElementById('project-list'),viewer=document.getElementById('art-viewer');let data=[],items=[],active=0,opener;
fetch('projects.json?v=8').then(r=>{if(!r.ok)throw Error();return r.json()}).then(p=>{data=p;const key=new URLSearchParams(location.search).get('sector');if(sectors[key])select(key)}).catch(()=>{document.getElementById('gallery-heading').textContent='Не удалось загрузить галерею. Обновите страницу.'});
const groups=[['stories','Истории'],['creative','Витрина / Креативы'],['logo','Логотип'],['posts','Публикации'],['qr','QR-коды']];
function dragStrip(strip){
 let startX=0,startScroll=0,dragged=false,pressed=false;
 strip.addEventListener('pointerdown',e=>{if(e.pointerType!=='mouse'||e.button!==0||strip.scrollWidth<=strip.clientWidth)return;pressed=true;dragged=false;startX=e.clientX;startScroll=strip.scrollLeft});
 strip.addEventListener('pointermove',e=>{if(!pressed)return;const delta=e.clientX-startX;if(Math.abs(delta)>7){dragged=true;strip.classList.add('is-dragging');if(!strip.hasPointerCapture(e.pointerId))strip.setPointerCapture(e.pointerId);strip.scrollLeft=startScroll-delta;e.preventDefault()}});
 const finish=()=>{pressed=false;strip.classList.remove('is-dragging')};
 strip.addEventListener('pointerup',finish);strip.addEventListener('pointercancel',finish);strip.addEventListener('lostpointercapture',finish);
 strip.addEventListener('pointerleave',()=>{if(!dragged)finish()});
 strip.addEventListener('click',e=>{if(dragged){e.preventDefault();e.stopImmediatePropagation();dragged=false}},true);
 strip.addEventListener('dragstart',e=>e.preventDefault());
}
function select(key){
 document.querySelectorAll('[data-sector]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.sector===key)));
 const ps=data.filter(p=>p.sector===key);items=[];area.replaceChildren();
 document.getElementById('gallery-heading').textContent=sectors[key]+' / '+ps.length+' '+(ps.length===1?'проект':ps.length<5?'проекта':'проектов');
 for(const p of ps){
  const article=document.createElement('article');article.className='project-series';
  const title=document.createElement('h3');title.textContent=p.title;article.append(title);
  const info=document.createElement('p');info.textContent=p.images.length+' макетов'+(p.id==='tyres-sign'?' · Визуализация, не фотография установленной вывески':'');article.append(info);
  for(const [kind,label] of groups){
   const files=p.images.filter(file=>file.kind===kind);if(!files.length)continue;
   const section=document.createElement('section');section.className='project-kind kind-'+kind;section.setAttribute('aria-label',label);
   const grid=document.createElement('div');grid.className='art-grid';
   for(const file of files){
    const index=items.push(file)-1,b=document.createElement('button');b.className='art-thumb';b.setAttribute('aria-label','Открыть: '+label+' — '+file.alt);
    const img=document.createElement('img');img.src=file.src;img.alt=file.alt;img.loading='lazy';img.draggable=false;b.append(img);
    b.onclick=()=>{active=index;opener=b;show();viewer.showModal()};grid.append(b);
   }
   const caption=document.createElement('h4');caption.className='kind-caption';caption.textContent=label;
   const count=document.createElement('small');count.textContent=String(files.length).padStart(2,'0')+' / '+(kind==='stories'?'ВЕРТИКАЛЬНЫЙ ФОРМАТ':kind==='posts'?'БАННЕРЫ И ТЕКСТЫ':'МАТЕРИАЛЫ');caption.append(count);
   section.append(grid,caption);article.append(section);dragStrip(grid);
  }
  area.append(article);
 }
 history.replaceState(null,'','?sector='+key);
}
function show(){const file=items[active];viewer.querySelector('img').src=file.src;viewer.querySelector('img').alt=file.alt;viewer.querySelector('figcaption').textContent=(active+1)+' / '+items.length+' · '+file.format;}
document.querySelectorAll('[data-sector]').forEach(b=>b.onclick=()=>select(b.dataset.sector));viewer.querySelector('.viewer-close').onclick=()=>viewer.close();viewer.addEventListener('close',()=>opener?.focus());viewer.querySelector('[data-next]').onclick=()=>{active=(active+1)%items.length;show()};viewer.querySelector('[data-prev]').onclick=()=>{active=(active+items.length-1)%items.length;show()};viewer.addEventListener('keydown',e=>{if(e.key==='ArrowRight')viewer.querySelector('[data-next]').click();if(e.key==='ArrowLeft')viewer.querySelector('[data-prev]').click()});viewer.addEventListener('click',e=>{if(e.target===viewer)viewer.close()});
