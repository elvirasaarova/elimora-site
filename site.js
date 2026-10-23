const fitArt=()=>document.documentElement.style.setProperty('--viewport-width',document.documentElement.clientWidth+'px');fitArt();addEventListener('resize',fitArt,{passive:true});
// Draw arrows ourselves: iOS otherwise substitutes coloured emoji glyphs.
const svgNS='http://www.w3.org/2000/svg';
function arrowIcon(direction='→'){
 const svg=document.createElementNS(svgNS,'svg');svg.classList.add('ui-arrow');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');svg.setAttribute('focusable','false');
 const group=document.createElementNS(svgNS,'g'),angle={'↗':-45,'↘':45,'←':180,'↓':90,'↑':-90}[direction]||0;
 group.setAttribute('transform',`rotate(${angle} 12 12)`);
 const path=document.createElementNS(svgNS,'path');path.setAttribute('d','M4 12h16m-7-7 7 7-7 7');group.append(path);svg.append(group);return svg;
}
function drawArrows(root){
 if(root.nodeType!==1&&root.nodeType!==3)return;
 const nodes=[],walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);if(root.nodeType===3)nodes.push(root);while(walker.nextNode())nodes.push(walker.currentNode);
 for(const node of nodes){
  if(!/[↗↘→←↓↑]/u.test(node.data)||!node.parentElement?.closest('a,button,.route-orbit,.availability-arrow')||node.parentElement.closest('svg,textarea,script,style,[contenteditable]'))continue;
  const parts=node.data.split(/([↗↘→←↓↑])\uFE0F?/u),fragment=document.createDocumentFragment();
  for(const part of parts)fragment.append(/^[↗↘→←↓↑]$/u.test(part)?arrowIcon(part):document.createTextNode(part));node.replaceWith(fragment);
 }
}
const header=document.querySelector('.site-header');
if(header){
 const nav=header.querySelector('nav');nav.id='main-navigation';
 const menu=document.createElement('button');menu.className='menu-toggle';menu.type='button';menu.setAttribute('aria-label','Открыть меню');menu.setAttribute('aria-expanded','false');menu.setAttribute('aria-controls',nav.id);menu.innerHTML='<i></i><i></i>';header.append(menu);
 const backdrop=document.createElement('button');backdrop.type='button';backdrop.className='menu-backdrop';backdrop.tabIndex=-1;backdrop.setAttribute('aria-label','Закрыть меню');header.after(backdrop);
 const setMenu=(open,returnFocus=false)=>{header.classList.toggle('menu-open',open);backdrop.classList.toggle('is-open',open);menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');if(returnFocus)menu.focus()};
 menu.onclick=()=>setMenu(menu.getAttribute('aria-expanded')!=='true');backdrop.onclick=()=>setMenu(false,true);
 nav.addEventListener('click',event=>{if(event.target.closest('a'))setMenu(false)});
 document.addEventListener('keydown',event=>{
  if(menu.getAttribute('aria-expanded')!=='true')return;
  if(event.key==='Escape'){event.preventDefault();setMenu(false,true)}
  if(event.key==='Tab'){
   const focusable=[...header.querySelectorAll('a,button')].filter(el=>el.getClientRects().length),first=focusable[0],last=focusable.at(-1);
   if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
  }
 });
 matchMedia('(min-width:701px)').addEventListener('change',event=>{if(event.matches)setMenu(false)});
}
const wordArt=document.querySelector('.letter-world');
if(wordArt){
 const mobile=document.createElementNS(svgNS,'svg');mobile.classList.add('letter-world','letter-world-mobile');mobile.setAttribute('viewBox','0 20 780 695');mobile.setAttribute('role','img');mobile.setAttribute('aria-label','ELIMORA');
 const defs=wordArt.querySelector('defs').cloneNode(true);defs.querySelectorAll('[id]').forEach(el=>el.id='mobile-'+el.id);mobile.append(defs);
 const outlines=[...wordArt.querySelector('g[aria-hidden]').children],positions=[[0,-25],[-10,10],[-20,-35],[-30,-5],[-656,305],[-619,275],[-582,315]];
 wordArt.querySelectorAll('.hero-letter').forEach((letter,index)=>{
  const group=document.createElementNS(svgNS,'g');group.setAttribute('transform',`translate(${positions[index].join(' ')})`);
  const art=letter.cloneNode(true);art.removeAttribute('class');art.removeAttribute('style');art.setAttribute('clip-path',`url(#mobile-letter${index})`);
  group.append(art,outlines[index].cloneNode(true));mobile.append(group);
 });wordArt.after(mobile);
 const note=document.querySelector('.hero-baseline p br');note?.before(document.createTextNode(' '));
}
// Visible dots are shared by the large home gallery and profile format rows.
const strips=new Map(),reducedMotion=()=>matchMedia('(prefers-reduced-motion:reduce)').matches;
function addScrollCues(strip){
 if(strips.has(strip)||strip.children.length<2)return;
 const slides=[...strip.children],cues=document.createElement('div');cues.className='scroll-cues';
 const dots=document.createElement('div');dots.className='scroll-dots';dots.setAttribute('role','group');dots.setAttribute('aria-label','Перелистывание изображений');
 const buttons=slides.map((slide,index)=>{const button=document.createElement('button');button.type='button';button.className='scroll-dot';button.setAttribute('aria-label',`Показать изображение ${index+1} из ${slides.length}`);button.onclick=()=>strip.scrollTo({left:slide.offsetLeft-slides[0].offsetLeft,behavior:reducedMotion()?'instant':'smooth'});dots.append(button);return button});
 const hint=document.createElement('span');hint.className='scroll-hint';hint.append('Листайте',arrowIcon());cues.append(dots,hint);strip.after(cues);
 const update=()=>{
  const overflow=strip.scrollWidth>strip.clientWidth+2;cues.classList.toggle('has-overflow',overflow);
  let active=0,distance=Infinity;slides.forEach((slide,index)=>{const delta=Math.abs(slide.offsetLeft-slides[0].offsetLeft-strip.scrollLeft);if(delta<distance){distance=delta;active=index}});
  buttons.forEach((button,index)=>{if(index===active)button.setAttribute('aria-current','true');else button.removeAttribute('aria-current')});
 };
 let pending=false;strip.addEventListener('scroll',()=>{if(pending)return;pending=true;requestAnimationFrame(()=>{update();pending=false})},{passive:true});
 strips.set(strip,{update,cues});requestAnimationFrame(update);
}
function enhanceMobile(root){
 drawArrows(root);
 if(root.nodeType!==1)return;
 if(root.matches('.art-grid,.preview-images'))addScrollCues(root);
 root.querySelectorAll('.art-grid,.preview-images').forEach(addScrollCues);
}
enhanceMobile(document.body);
new MutationObserver(records=>{
 records.forEach(record=>record.addedNodes.forEach(enhanceMobile));
 for(const [strip,state] of strips)if(!strip.isConnected){state.cues.remove();strips.delete(strip)}
}).observe(document.body,{childList:true,subtree:true});
addEventListener('resize',()=>strips.forEach(state=>state.update()),{passive:true});
document.querySelectorAll('.site-header nav a,.project-tabs a').forEach(a=>{if(a.pathname===location.pathname)a.setAttribute('aria-current','page')});
document.querySelectorAll('.nav-projects').forEach(group=>{
 const toggle=group.querySelector('.nav-projects-toggle'),panel=group.querySelector('.project-submenu');
 if(panel.querySelector('[aria-current=page]'))group.classList.add('is-current');
 const close=()=>{toggle.setAttribute('aria-expanded','false');panel.hidden=true};
 toggle.addEventListener('click',()=>{const opening=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(opening));panel.hidden=!opening});
 document.addEventListener('click',e=>{if(!group.contains(e.target))close()});
 group.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){close();toggle.focus()}});
 group.addEventListener('focusout',e=>{if(!group.contains(e.relatedTarget))close()});
});
if(!matchMedia('(prefers-reduced-motion: reduce)').matches){const obs=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in-view');obs.unobserve(e.target)}}),{threshold:.08});document.querySelectorAll('.material-lab,.process-lab,.world-preview,.service-row,.package,.project-series').forEach(e=>{e.classList.add('reveal');obs.observe(e)})}
