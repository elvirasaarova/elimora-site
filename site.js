const fitArt=()=>document.documentElement.style.setProperty('--viewport-width',document.documentElement.clientWidth+'px');fitArt();addEventListener('resize',fitArt,{passive:true});
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
