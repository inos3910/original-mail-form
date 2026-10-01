// data-validate の入力補助。送信時のPHP検証は常に優先する。
export const validateValue = (value, checks) => {
  const length=Array.from(value).length;
  if(checks.min&&length<checks.min) return `${checks.min}文字以上入力してください`;
  if(checks.max&&length>checks.max) return `${checks.max}文字以内で入力してください`;
  if(!value||!checks.pattern) return '';
  const normalized=checks.format==='tel'?value.replace(/[０-９]/g,char=>String.fromCharCode(char.charCodeAt(0)-0xfee0)).replace(/-/g,''):value;
  let valid=new RegExp(`^(?:${checks.pattern})$`,'u').test(normalized);
  // JavaScriptの$は末尾改行の直前にも一致するため、PHPのD指定と同じ終端にする。
  if(valid) valid=new RegExp(`^(?:${checks.pattern})(?![\\s\\S])`,'u').test(normalized);
  if(valid&&checks.format==='date') {
    const [year,month,day]=value.match(/[0-9]+/g).map(Number);
    const days=month===2?((year%4===0&&year%100!==0)||year%400===0?29:28):([4,6,9,11].includes(month)?30:31);
    valid=year>=1&&month>=1&&month<=12&&day>=1&&day<=days;
  }
  return valid?'':checks.message;
};

const messageFor = (field, form) => {
  const rules=(field.dataset.validate||'').split('|').filter(Boolean);
  const group=field.type==='radio'||field.type==='checkbox';
  const peers=group?[...form.elements].filter(item=>item.name===field.name):[field];
  const value=group ? (peers.find(item=>item.checked)?.value||'') : field.value.replace(/^[\x00-\x20]+|[\x00-\x20]+$/g,'');
  const conditional=field.dataset.omfRequiredIfKey;
  const source=conditional?form.elements.namedItem(`omf_fields[${conditional}]`):null;
  const required=conditional?Boolean(source&&source.value===field.dataset.omfRequiredIfValue):(rules.includes('required')||field.required);
  if(field.type==='file') {
    const file=field.files?.[0];
    if(required&&!file&&!field.dataset.omfFileRetained) return '必須項目です';
    if(!file) return '';
    if(field.dataset.omfMaxBytes&&file.size>Number(field.dataset.omfMaxBytes)) return '添付ファイルの容量が上限を超えています';
    if(field.dataset.omfExtensions&&!field.dataset.omfExtensions.split(',').includes(file.name.split('.').pop().toLowerCase())) return '許可されていない拡張子です';
    return '';
  }
  if(required&&!value) return '必須項目です';
  if(!required&&!value) return '';
  if(field.dataset.omfAllowedValues) {
    const allowed=JSON.parse(field.dataset.omfAllowedValues);const values=group?peers.filter(item=>item.checked).map(item=>item.value):[value];
    if(values.some(item=>item!==''&&!allowed.includes(item))) return '選択肢にない値が送信されました。';
  }
  if(field.dataset.omfValidation) {
    try { return validateValue(value,JSON.parse(field.dataset.omfValidation)); } catch { return '検証設定を読み込めません'; }
  }
  const patterns={numeric:'[0-9]+',alpha:'[a-zA-Z]+',alphanumeric:'[a-zA-Z0-9]+',hiragana:'[ぁ-んー \\t\\r\\n　]+',katakana:'[ァ-ヶー \\t\\r\\n　]+',kana:'[ァ-ヾぁ-んー]+',postal_code:'[0-9]{3}-?[0-9]{4}',postalCode:'[0-9]{3}-?[0-9]{4}',tel:'0[0-9]{9,10}',email:'[^ @\\t\\r\\n]+@[^ @\\t\\r\\n]+\\.[^ @\\t\\r\\n]+',url:'https?://[^ \\t\\r\\n]+',date:'[0-9]{4}-[0-9]{2}-[0-9]{2}'};
  for(const rule of rules) {
    const [name,raw]=rule.split(':');const limit=Number(raw);
    const checks={min:name==='minLength'?limit:0,max:name==='maxLength'?limit:0,format:name,pattern:patterns[name],message:'入力形式を確認してください'};
    const message=validateValue(value,checks);if(message)return message;
  }
  return '';
};

const showError = (field, form, message) => {
  const group=field.type==='radio'||field.type==='checkbox';
  const peers=group?[...form.elements].filter(item=>item.name===field.name):[field];
  const container=field.closest('.omf-managed-control,.js-form-item,.p-form__field')||field.parentElement;
  if(!container) return;
  let error=[...container.children].find(item=>item.classList?.contains('omf-client-error'));
  if(message&&!error){error=document.createElement('p');error.className='omf-client-error';error.setAttribute('role','alert');error.id=`omf-client-error-${Math.random().toString(36).slice(2)}`;container.append(error);}
  if(error){error.textContent=message;error.hidden=!message;}
  peers.forEach(item=>{
    if(message){item.setAttribute('aria-invalid','true');const ids=(item.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean);if(!ids.includes(error.id)) item.setAttribute('aria-describedby',[...ids,error.id].join(' '));}
    else {item.removeAttribute('aria-invalid');if(error){const ids=(item.getAttribute('aria-describedby')||'').split(/\s+/).filter(id=>id&&id!==error.id);if(ids.length)item.setAttribute('aria-describedby',ids.join(' '));else item.removeAttribute('aria-describedby');}}
  });
};

if(typeof document!=='undefined') document.querySelectorAll('form').forEach(form=>{
  if(!form.querySelector('[name="omf_token"]')) return;
  const fields=[...form.querySelectorAll('[data-validate]')];
  if(!fields.length) return;
  form.noValidate=true;
  const validate=field=>{const message=messageFor(field,form);showError(field,form,message);return !message;};
  const buttons=[...form.querySelectorAll('button[type="submit"],input[type="submit"]')].filter(button=>!button.formNoValidate&&!['submit_back','omf_restart'].includes(button.name));
  const hint=document.createElement('p');hint.className='omf-validation-status';hint.setAttribute('role','status');hint.setAttribute('aria-live','polite');
  if(buttons.length){hint.id=`omf-validation-status-${Math.random().toString(36).slice(2)}`;const actions=buttons[0].closest('.omf-managed-actions')||buttons[0];actions.after(hint);buttons.forEach(button=>button.setAttribute('aria-describedby',[(button.getAttribute('aria-describedby')||''),hint.id].filter(Boolean).join(' ')));}
  const syncButtons=()=>{
    if(form.dataset.omfState==='submitting') return;
    const seen=new Set();let invalid=0;
    fields.forEach(field=>{if(seen.has(field.name))return;seen.add(field.name);if(messageFor(field,form))invalid++;});
    buttons.forEach(button=>{button.disabled=invalid>0;button.setAttribute('aria-disabled',String(invalid>0));});
    hint.textContent=invalid?`入力条件を満たすとボタンを押せます。未入力・修正が必要な項目はあと${invalid}件です。`:'';
    hint.hidden=invalid===0;
  };
  fields.forEach(field=>{field.addEventListener('blur',()=>{validate(field);syncButtons();});field.addEventListener('input',()=>validate(field));field.addEventListener('change',()=>validate(field));});
  form.addEventListener('input',syncButtons);form.addEventListener('change',syncButtons);
  form.addEventListener('reset',()=>queueMicrotask(syncButtons));window.addEventListener('pageshow',syncButtons);syncButtons();
  form.addEventListener('submit',event=>{
    if(form.dataset.omfState==='submitting'){event.preventDefault();return;}
    if(event.submitter?.formNoValidate||['submit_back','omf_restart'].includes(event.submitter?.name)) return;
    const seen=new Set();let first=null;
    fields.forEach(field=>{if(seen.has(field.name))return;seen.add(field.name);if(!validate(field)&&!first)first=field;});
    if(first){event.preventDefault();first.focus();first.scrollIntoView({block:'center',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
  });
});
