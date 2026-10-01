// ポインターを捕捉し、マウス・タッチ共通で移動先を示す。
export function attachReorder(handle,list,index,move) {
  let target=null,start=null;
  const clear=()=>{list.querySelectorAll('.is-drop-target').forEach(el=>el.classList.remove('is-drop-target'));};
  handle.style.touchAction='none';
  handle.addEventListener('pointerdown',e=>{
    if(e.button!==0)return;
    start={x:e.clientX,y:e.clientY};target=null;handle.setPointerCapture(e.pointerId);e.preventDefault();
  });
  handle.addEventListener('pointermove',e=>{
    if(!start||Math.hypot(e.clientX-start.x,e.clientY-start.y)<6)return;
    clear();const card=document.elementFromPoint(e.clientX,e.clientY)?.closest('.omf-builder-card');
    target=card&&card.parentElement===list?[...list.children].indexOf(card):null;
    if(target!==null&&target!==index)card.classList.add('is-drop-target');
  });
  handle.addEventListener('pointerup',()=>{const to=target;start=null;target=null;clear();if(to!==null)move(index,to);});
  handle.addEventListener('pointercancel',()=>{start=null;target=null;clear();});
  handle.addEventListener('keydown',e=>{if(['ArrowUp','ArrowDown'].includes(e.key)){e.preventDefault();move(index,index+(e.key==='ArrowUp'?-1:1));}});
}
