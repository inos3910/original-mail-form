// 保存値は従来どおりカンマ区切りのID。検索・タグ表示だけを拡張する。
export function setupPostPicker() {
  const original=document.querySelector('[name="cf_omf_condition_id"]');
  if(!original||!window.omfPostPicker)return;
  const config=window.omfPostPicker;
  const root=document.createElement('div');root.className='omf-post-picker';
  const tags=document.createElement('div');tags.className='omf-post-picker-tags';tags.setAttribute('aria-label','選択した投稿・固定ページ');
  const input=document.createElement('input');input.type='text';input.placeholder='タイトルで検索、またはIDを入力';input.setAttribute('aria-label','投稿・固定ページを検索またはIDで追加');input.autocomplete='off';
  const add=document.createElement('button');add.type='button';add.textContent='IDを追加';
  const suggestions=document.createElement('div');suggestions.className='omf-post-picker-results';suggestions.setAttribute('aria-label','検索候補');
  const message=document.createElement('p');message.setAttribute('role','status');message.textContent='タイトルで検索して候補を選択。IDはEnterで追加できます。';
  root.append(tags,input,add,suggestions,message);original.after(root);original.hidden=true;
  let ids=[...new Set(original.value.split(',').map(v=>v.trim()).filter(Boolean))];
  const titles=new Map(Object.entries(config.titles||{}));let timer,controller,sequence=0;
  function render() {
    tags.replaceChildren();
    ids.forEach(id=>{
      const chip=document.createElement('span');chip.className='omf-post-picker-tag';
      const title=document.createElement('span');title.textContent=titles.has(id)?`${titles.get(id)} · ID ${id}`:`ID ${id}`;
      const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label',`${title.textContent}を削除`);
      remove.addEventListener('click',()=>{ids=ids.filter(v=>v!==id);sync();input.focus();message.textContent=`ID ${id}を解除しました。`;});chip.append(title,remove);tags.append(chip);
    });
  }
  function sync(){original.value=ids.join(',');original.dispatchEvent(new Event('input',{bubbles:true}));render();}
  function cancel(){clearTimeout(timer);controller?.abort();sequence++;suggestions.replaceChildren();}
  function append(id,title){id=String(Number(id));if(title)titles.set(id,title);if(!ids.includes(id))ids.push(id);sync();input.value='';cancel();input.focus();message.textContent=`ID ${id}を選択しました。保存すると反映されます。`;}
  function direct(){const value=input.value.trim();if(!/^[1-9]\d*$/.test(value)||!Number.isSafeInteger(Number(value))){message.textContent='IDは1以上の整数で入力してください。タイトルは検索候補から選んでください。';return false;}append(value);return true;}
  add.addEventListener('click',direct);
  input.addEventListener('keydown',e=>{if(e.isComposing)return;if(e.key==='Enter'){e.preventDefault();direct();}if(e.key==='Escape')cancel();if(e.key==='ArrowDown'){e.preventDefault();suggestions.querySelector('button')?.focus();}});
  suggestions.addEventListener('keydown',e=>{const buttons=[...suggestions.querySelectorAll('button')],i=buttons.indexOf(document.activeElement);if(e.key==='Escape'){cancel();input.focus();}if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();const next=i+(e.key==='ArrowDown'?1:-1);if(next<0)input.focus();else buttons[Math.min(next,buttons.length-1)]?.focus();}});
  input.addEventListener('input',()=>{
    cancel();const q=input.value.trim();if(!q){message.textContent='タイトルで検索、またはIDを入力してください。';return;}
    const ticket=sequence;message.textContent='検索しています…';
    timer=setTimeout(async()=>{
      controller=new AbortController();
      try {
        const url=new URL(config.url,location.href);url.search=new URLSearchParams({action:'omf_search_pages',nonce:config.nonce,q}).toString();
        const response=await fetch(url,{signal:controller.signal,credentials:'same-origin'});const result=await response.json();if(ticket!==sequence)return;if(!response.ok||!result.success)throw new Error('search');
        suggestions.replaceChildren();
        result.data.forEach(item=>{const b=document.createElement('button');b.type='button';b.textContent=`${item.title} · ${item.type} · ID ${item.id}`;b.addEventListener('click',()=>append(item.id,item.title));suggestions.append(b);});
        message.textContent=result.data.length?`${result.data.length}件の候補があります。選択してください。`:'候補がありません。IDを直接入力して追加することもできます。';
      } catch(error){if(error.name!=='AbortError'&&ticket===sequence)message.textContent='検索できませんでした。再入力して試すか、IDを直接追加してください。';}
    },250);
  });
  original.closest('form').addEventListener('submit',e=>{if(!input.value.trim())return;if(!direct()){e.preventDefault();document.getElementById('omf-tab-settings')?.click();input.focus();}});
  render();
}
