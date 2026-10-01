// 日本郵便の同梱データを必要な先頭3桁だけ読み込む。郵便番号は外部に送信しない。
export const normalizePostalCode=value=>value.replace(/[０-９]/g,char=>String.fromCharCode(char.charCodeAt(0)-0xfee0)).replace(/[-－−‐‑–—ー]/g,'').trim();
export const addressValues=row=>({full:row.join(''),prefecture:row[0],city:row[1]+row[2]});
const cache=new Map();
async function loadPrefix(base,prefix) {
  const key=base+prefix;
  if(!cache.has(key)) {
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
    const request=fetch(`${base}${prefix}.json`,{signal:controller.signal,cache:'no-cache',credentials:'same-origin'})
      .then(response=>{if(!response.ok)throw Error('住所データを読み込めません');return response.json();})
      .catch(error=>{cache.delete(key);throw error;}).finally(()=>clearTimeout(timer));
    cache.set(key,request);
  }
  return cache.get(key);
}
if(typeof document!=='undefined') document.querySelectorAll('[data-omf-postal]:not([data-omf-address-targets])').forEach(postal=>postal.addEventListener('blur',()=>{const code=normalizePostalCode(postal.value);if(/^[0-9]{7}$/.test(code)){postal.value=code.slice(0,3)+'-'+code.slice(3);postal.dispatchEvent(new Event('change',{bubbles:true}));}}));
if(typeof document!=='undefined') document.querySelectorAll('[data-omf-address-targets]').forEach(postal=>{
  const form=postal.form;if(!form)return;
  let targets;try{targets=JSON.parse(postal.dataset.omfAddressTargets);}catch{return;}
  const container=postal.closest('.omf-managed-control')||postal.parentElement;
  const status=document.createElement('p');status.className='omf-managed-help';status.setAttribute('role','status');container.append(status);
  const candidates=document.createElement('select');candidates.hidden=true;candidates.setAttribute('aria-label','自動入力する住所を選択');candidates.dataset.omfRole='address-candidate';container.append(candidates);
  let sequence=0,lastCode='',rows=[];const autoValues=new Map();
  const apply=row=>{
    const values=addressValues(row);let preserved=false;
    Object.entries(targets).forEach(([role,key])=>{
      const target=form.elements.namedItem(`omf_fields[${key}]`);if(!target||!('value' in target))return;
      if(target.value!==''&&target.value!==autoValues.get(key)){preserved=true;return;}
      if(target.tagName==='SELECT'&&![...target.options].some(option=>option.value===values[role])){preserved=true;return;}
      target.value=values[role];autoValues.set(key,target.value);target.dispatchEvent(new Event('input',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));
    });
    status.textContent=preserved?'入力済みの住所は変更していません。住所をご確認ください。':'住所を入力しました。番地・建物名などを追記してください。';
  };
  candidates.addEventListener('change',()=>{const index=Number(candidates.value);if(candidates.value!==''&&rows[index])apply(rows[index]);});
  const lookup=async()=>{
    const code=normalizePostalCode(postal.value),ticket=++sequence;
    candidates.hidden=true;
    if(!/^[0-9]{7}$/.test(code)){status.textContent='';lastCode='';return;}
    if(code===lastCode){if(rows.length>1)candidates.hidden=false;return;}
    status.textContent='住所を検索しています…';
    try {
      const data=await loadPrefix(postal.dataset.omfPostalBase,code.slice(0,3));
      if(ticket!==sequence||normalizePostalCode(postal.value)!==code)return;
      rows=data[code]||[];lastCode=code;
      if(!rows.length){status.textContent='住所が見つかりません。住所を手入力してください。';return;}
      if(rows.length===1){apply(rows[0]);return;}
      candidates.replaceChildren(new Option('住所を選択してください',''));
      rows.forEach((row,index)=>candidates.add(new Option(row.join(''),String(index))));
      candidates.hidden=false;status.textContent='複数の住所が該当します。自動入力する住所を選択してください。';
    }catch{
      if(ticket!==sequence)return;
      lastCode='';status.textContent='住所データを読み込めませんでした。住所を手入力するか、もう一度お試しください。';
    }
  };
  postal.addEventListener('input',lookup);
  postal.addEventListener('blur',()=>{
    const code=normalizePostalCode(postal.value);
    if(/^[0-9]{7}$/.test(code)){postal.value=code.slice(0,3)+'-'+code.slice(3);postal.dispatchEvent(new Event('change',{bubbles:true}));}
    lookup();
  });
});
