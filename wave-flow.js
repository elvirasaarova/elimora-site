// A transparent, continuously changing ribbon. Only animate while it is visible.
document.querySelectorAll('.flow-divider canvas,.ambient-wave canvas').forEach(canvas=>{
 const quiet=canvas.dataset.waveStyle==='quiet';
 const ctx=canvas.getContext('2d'),motion=matchMedia('(prefers-reduced-motion: reduce)');
 let width=0,height=0,phase=0,last=0,frame=0,visible=false;
 function draw(){
  ctx.clearRect(0,0,width,height);
  for(let line=0;line<68;line++){
   const strand=line/67;
   ctx.beginPath();
   for(let x=0;x<=width+8;x+=8){
    const u=x/width;
    const envelope=.45+.55*Math.sin(Math.PI*u);
    const center=height*.5+Math.sin(u*Math.PI*2.15-phase)*height*.17;
    const twist=Math.sin(u*Math.PI*2.15-phase+strand*.7);
    const y=center+(strand-.5)*height*.48*twist*envelope+Math.sin(u*12-phase*1.3+strand*3)*height*.025;
    if(x===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);
   }
   const lime=line>27&&line<39;
   ctx.strokeStyle=lime?'rgba(153,189,33,.48)':'rgba(119,57,178,'+(.16+Math.sin(strand*Math.PI)*.2)+')';
   ctx.lineWidth=lime?1.15:.8;ctx.globalAlpha=quiet?.62:1;ctx.stroke();
  }
 }
 function tick(time){frame=0;if(!visible||document.hidden||motion.matches)return;phase+=Math.min(time-last,45)*.00022;last=time;draw();frame=requestAnimationFrame(tick)}
 function sync(){cancelAnimationFrame(frame);frame=0;if(visible&&!document.hidden&&!motion.matches){last=performance.now();frame=requestAnimationFrame(tick)}}
 function resize(){const scale=Math.min(devicePixelRatio||1,1.5);width=canvas.clientWidth;height=canvas.clientHeight;canvas.width=Math.round(width*scale);canvas.height=Math.round(height*scale);ctx.setTransform(scale,0,0,scale,0,0);if(width)draw()}
 new ResizeObserver(resize).observe(canvas);
 new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;sync()},{rootMargin:'80px'}).observe(canvas);
 document.addEventListener('visibilitychange',sync);motion.addEventListener('change',()=>{draw();sync()});resize();
});

