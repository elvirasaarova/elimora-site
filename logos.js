const buttons=[...document.querySelectorAll('.logo-open')],viewer=document.getElementById('art-viewer');
let active=0,opener;
function show(){const image=buttons[active].querySelector('img');viewer.querySelector('img').src=image.src;viewer.querySelector('img').alt=image.alt;viewer.querySelector('figcaption').textContent=(active+1)+' / '+buttons.length+' · '+buttons[active].dataset.title}
buttons.forEach((button,index)=>button.addEventListener('click',()=>{active=index;opener=button;show();viewer.showModal()}));
viewer.querySelector('.viewer-close').addEventListener('click',()=>viewer.close());
viewer.querySelector('[data-next]').addEventListener('click',()=>{active=(active+1)%buttons.length;show()});
viewer.querySelector('[data-prev]').addEventListener('click',()=>{active=(active+buttons.length-1)%buttons.length;show()});
viewer.addEventListener('keydown',event=>{if(event.key==='ArrowRight'){event.preventDefault();viewer.querySelector('[data-next]').click()}if(event.key==='ArrowLeft'){event.preventDefault();viewer.querySelector('[data-prev]').click()}});
viewer.addEventListener('click',event=>{if(event.target===viewer)viewer.close()});
viewer.addEventListener('close',()=>opener?.focus());
