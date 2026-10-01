import { makeAddressPreset } from './builder/address-presets';
import { attachReorder } from './builder/reorder';
import { createDraft } from './builder/draft';
import { setupPostPicker } from './builder/post-picker';
import { setupScreenPicker } from './builder/screen-picker';
import { setupEditorTabs } from './builder/tabs';
import { renderPreview, renderPalette } from './builder/preview';
import { prefectures, parseChoiceLines, appendChoices } from './builder/choice-presets';
// 項目定義を画面操作で編集し、既存の投稿保存へまとめて渡す。
const root = document.querySelector('#omf-field-builder');
if (root) {
  const types = {text:'一行テキスト',textarea:'複数行テキスト',email:'メールアドレス',tel:'電話番号',url:'URL',select:'選択リスト',radio:'単一選択',checkboxes:'複数選択',acceptance:'同意',file:'添付ファイル'};
  const list = root.querySelector('.omf-builder-fields');
  const status = root.querySelector('[role="status"]');
  const payload = root.querySelector('[name="omf_builder_schema"]');
  const mode = root.querySelector('[name="omf_builder_mode"]');
  const save = root.querySelector('[data-builder-save]');
  const workspace = root.querySelector('[data-builder-workspace]');
  const saveState = root.querySelector('[data-save-state]');
  let schema;
  try { schema = JSON.parse(root.dataset.schema); } catch { schema = null; }
  if (!schema || schema.version !== 1 || !Array.isArray(schema.fields)) {
    status.textContent = '項目設定を読み込めません。保存済みの定義を管理者に確認してください。';
    mode.disabled = true;
  } else {
    let fields = schema.fields.map(f => ({...f, original_key:f.key}));
    const draft=createDraft(root,schema);
    const recovered=draft.restore(mode.value);
    if(recovered){fields=recovered.fields;mode.value=recovered.mode;}
    const dirty = () => { saveState.textContent='未保存の変更があります'; saveState.dataset.dirty='true'; };
    root.closest('form').addEventListener('input',dirty);
    setupPostPicker();
    setupScreenPicker();
    const refreshTabs=setupEditorTabs(root,()=>mode.value);
    function syncMode() {
      workspace.hidden=mode.value!=='builder';
      root.querySelector('[data-code-note]').hidden=mode.value==='builder';
      refreshTabs();
    }
    mode.addEventListener('change',()=>{syncMode();dirty();});
    save.disabled=false;
    save.addEventListener('click',()=>{
      const publish=document.getElementById('publish');
      if(publish) publish.click();
    });
    const node = (tag, text, className) => {
      const el = document.createElement(tag);
      if (text) el.textContent = text;
      if (className) el.className = className;
      return el;
    };
    const announce = text => { status.textContent = text; };
    const button = (text, action) => {
      const el = node('button',text,'omf-builder-button'); el.type='button'; el.addEventListener('click',action); return el;
    };
    function control(parent, title, value, onChange, type='text') {
      const label=node('label',title); const input=node(type==='textarea'?'textarea':'input');
      if (type!=='textarea') input.type=type;
      if (type==='checkbox') input.checked=Boolean(value); else input.value=value??'';
      input.addEventListener('input',()=>onChange(type==='checkbox'?input.checked:input.value));
      label.append(input); parent.append(label); return input;
    }
    function move(from,to) {
      if (to<0 || to>=fields.length || from===to) return;
      fields.splice(to,0,fields.splice(from,1)[0]); dirty();render();
      list.children[to].querySelector('[data-edit]').focus(); announce(`${to+1}番目に移動しました。`);
    }
    function render() {
      list.replaceChildren();
      if (!fields.length) list.append(node('p','まだ入力欄がありません。上の「お名前」「メールアドレス」などを選んで、最初の項目を追加しましょう。','omf-builder-empty'));
      fields.forEach((field,index)=> {
        let card=node('section',null,'omf-builder-card');
        const heading=node('div',null,'omf-builder-heading');
        const title=node('strong',`${String(index+1).padStart(2,'0')}  ${field.label || '新しい項目'}`);
        heading.append(title); card.append(heading);
        if (field.deleted) {
          card.append(node('p','保存すると削除されます。'));
          card.append(button('削除を取り消す',()=>{field.deleted=false;dirty();render();announce('削除を取り消しました。');}));
          list.append(card); return;
        }
        const up=button('↑ 上へ',()=>move(index,index-1)); up.disabled=index===0;
        const down=button('↓ 下へ',()=>move(index,index+1)); down.disabled=index===fields.length-1;
        const handle=button('⠿',()=>{});handle.classList.add('omf-builder-drag');handle.setAttribute('aria-label',`${field.label||'新しい項目'}をドラッグして並べ替え`);heading.prepend(handle);attachReorder(handle,list,index,move);
        const actions=node('div',null,'omf-builder-actions');
        const edit=button(field.open?'閉じる':'編集',()=>{const next=!field.open;fields.forEach(f=>f.open=false);field.open=next;render();list.children[index].querySelector('[data-edit]').focus();});edit.dataset.edit='';edit.setAttribute('aria-expanded',String(Boolean(field.open)));
        const badge=node('span',`${types[field.type]}${field.required||field.type==='acceptance'?' ・ 必須':''}`,'omf-builder-badge');
        actions.append(up,down,edit);heading.append(actions);
        title.append(badge);
        const preview=node('div',null,'omf-builder-preview');renderPreview(preview,field);card.append(preview);

        if(!field.open){list.append(card);return;}
        const editor=node('div',null,'omf-builder-editor');card.append(editor);
        const surface=card;card=editor;
        control(card,'項目名（改行できます）',field.label,v=>{field.label=v;title.textContent=`${index+1}. ${v||'新しい項目'}`;title.append(badge);renderPreview(preview,field);},'textarea');
        const label=node('label','入力形式');const select=node('select');
        Object.entries(types).forEach(([value,text])=>{const option=node('option',text);option.value=value;select.append(option);});
        select.value=field.type;label.append(select);card.append(label);
        select.addEventListener('change',()=>{
          field.type=select.value;field.default=field.type==='checkboxes'?[]:'';
          field.required_if=null;field.max_length=0;field.min_length=0;field.validation_format='';field.address_targets={};
          field.choices=['select','radio','checkboxes'].includes(field.type)?(field.choices||[]):[];
          field.extensions=field.type==='file'?['pdf']:[];field.max_bytes=10485760;render();
        });
        if(field.type!=='acceptance') control(card,'必須にする',field.required,v=>{field.required=v;if(v)field.required_if=null;badge.textContent=`${types[field.type]}${v?' ・ 必須':''}`;if(v)render();},'checkbox');
        else card.append(node('p','同意は必須です。初期選択はしません。'));
        control(card,'入力する人への説明（任意）',field.description,v=>{field.description=v;renderPreview(preview,field);},'textarea');
        if(field.type==='acceptance') control(card,'同意前に表示する規約・方針の本文（任意）',field.policy_text||'',v=>{field.policy_text=v;renderPreview(preview,field);},'textarea');
        if(field.type==='acceptance') card.append(node('p','方針へのリンクは [個人情報保護方針](/privacy/) の形式で入力できます。'));
        const advanced=node('details',null,'omf-builder-advanced');advanced.append(node('summary','詳しい設定（入力例・初期値・メールタグ）'));
        const validation=node('fieldset',null,'omf-builder-validation');validation.append(node('legend','バリデーション'));
        if(['text','textarea','email','tel','url'].includes(field.type)) {
          control(advanced,'入力例',field.placeholder,v=>{field.placeholder=v;renderPreview(preview,field);});
          control(advanced,'最初から入力しておく内容',field.default,v=>{field.default=v;},field.type==='textarea'?'textarea':'text');
          const minLength=control(validation,'最小文字数（0なら制限なし）',field.min_length||0,v=>{field.min_length=Number(v);},'number');minLength.min='0';minLength.max='100000';minLength.step='1';
          if(['text','textarea'].includes(field.type)) {
            const formatLabel=node('label','入力内容の検証');const formatSelect=node('select');
            Object.entries({'':'形式の制限なし',numeric:'半角数字',alpha:'半角英字',alphanumeric:'半角英数字',katakana:'全角カタカナ（空白可）',hiragana:'ひらがな（空白可）',kana:'ひらがな or カタカナ（空白不可）',postal_code:'郵便番号',date:'日付（年・月・日）'}).forEach(([value,text])=>{const option=node('option',text);option.value=value;formatSelect.append(option);});
            formatSelect.value=field.validation_format||'';formatSelect.addEventListener('change',()=>{field.validation_format=formatSelect.value;if(field.validation_format!=='postal_code')field.address_targets={};dirty();render();});formatLabel.append(formatSelect);validation.append(formatLabel);
          } else validation.append(node('p','入力形式に応じてメールアドレス・電話番号・URLを検証します。'));
          const maxLength=control(validation,'最大文字数（0なら制限なし）',field.max_length||0,v=>{field.max_length=Number(v);},'number');maxLength.min='0';maxLength.max='100000';maxLength.step='1';
        }
        if(['text','textarea','email','tel','url'].includes(field.type)) card.append(validation);
        if(field.validation_format==='postal_code') {
          const mapping=node('fieldset');mapping.append(node('legend','郵便番号から住所を自動入力'));
          ['full','prefecture','city'].forEach(role=>{const text={full:'住所（1行）の入力先',prefecture:'都道府県の入力先',city:'住所1の入力先'}[role];const lab=node('label',text);const sel=node('select');const none=node('option','自動入力しない');none.value='';sel.append(none);fields.filter(other=>other!==field&&!other.deleted&&(other.type==='text'||(role==='prefecture'&&other.type==='select'))).forEach(other=>{const op=node('option',other.label);op.value=other.key;sel.append(op);});sel.value=field.address_targets?.[role]||'';sel.addEventListener('change',()=>{field.address_targets||={};if(sel.value){if(role==='full')field.address_targets={full:sel.value};else{delete field.address_targets.full;field.address_targets[role]=sel.value;}}else delete field.address_targets[role];dirty();render();});lab.append(sel);mapping.append(lab);});card.append(mapping);
        }
        if(['text','textarea','email','tel','url','select'].includes(field.type) && !field.required) {
          const sources=fields.filter(other=>!other.deleted&&other!==field&&['select','radio'].includes(other.type)&&(other.choices||[]).length);
          if(sources.length) {
            const condition=node('fieldset');condition.append(node('legend','特定の選択時だけ必須にする（任意）'));
            const sourceLabel=node('label','条件となる項目');const sourceSelect=node('select');
            const none=node('option','条件なし');none.value='';sourceSelect.append(none);
            sources.forEach(other=>{const option=node('option',other.label||other.key);option.value=other.key;sourceSelect.append(option);});
            sourceSelect.value=field.required_if?.key||'';
            sourceSelect.addEventListener('change',()=>{const selected=sources.find(other=>other.key===sourceSelect.value);field.required_if=selected?{key:selected.key,value:selected.choices[0].value}:null;dirty();render();});
            sourceLabel.append(sourceSelect);condition.append(sourceLabel);
            const selected=sources.find(other=>other.key===field.required_if?.key);
            if(selected){const valueLabel=node('label','必須になる選択肢');const valueSelect=node('select');selected.choices.forEach(choice=>{const option=node('option',choice.label);option.value=choice.value;valueSelect.append(option);});valueSelect.value=field.required_if.value;valueSelect.addEventListener('change',()=>{field.required_if.value=valueSelect.value;dirty();});valueLabel.append(valueSelect);condition.append(valueLabel);}
            advanced.append(condition);
          }
        }
        if(['select','radio','checkboxes'].includes(field.type)) {
          const choices=node('fieldset');choices.append(node('legend','選択肢'));
          field.choices ||= [];
          const bulk=node('details',null,'omf-builder-choice-bulk');bulk.append(node('summary','選択肢をまとめて追加'));
          const bulkIntro=node('p','1行に1つずつ貼り付けます。表示名と送信値は同じ内容で追加し、必要なら追加後に個別編集できます。');bulk.append(bulkIntro);
          const pasteLabel=node('label','追加する選択肢');const paste=node('textarea');paste.rows=6;paste.placeholder='北海道\n青森県\n岩手県';pasteLabel.append(paste);bulk.append(pasteLabel);
          const bulkActions=node('div',null,'omf-builder-bulk-actions');
          const addBulk=(incoming)=>{const result=appendChoices(field.choices,incoming);field.choices=result.choices;if(result.added){dirty();render();}announce(result.added?`${result.added}件の選択肢を追加しました。${result.overflow?'上限は100件です。':''}`:'追加できる新しい選択肢がありません。');};
          bulkActions.append(button('貼り付けた選択肢を追加',()=>addBulk(parseChoiceLines(paste.value))));
          bulkActions.append(button('都道府県47件を追加',()=>addBulk(prefectures.map(label=>({label,value:label})))));
          bulkActions.append(button('海外も追加',()=>addBulk([{label:'海外',value:'海外'}])));
          bulk.append(bulkActions);choices.append(bulk);
          field.choices.forEach((choice,i)=>{
            const row=node('div',null,'omf-builder-choice');
            control(row,'選択肢',choice.label,v=>{choice.label=v;renderPreview(preview,field);});
            const choiceDetails=node('details');choiceDetails.append(node('summary','送信値'));control(choiceDetails,'メールに送る値',choice.value,v=>{choice.value=v;field.default=field.type==='checkboxes'?[]:'';});row.append(choiceDetails);
            row.append(button('選択肢を削除',()=>{dirty();field.choices.splice(i,1);field.default=field.type==='checkboxes'?[]:'';render();}));choices.append(row);
          });
          choices.append(button('＋ 選択肢を追加',()=>{dirty();let n=1;while(field.choices.some(c=>c.value===`option_${n}`))n++;field.choices.push({label:'',value:`option_${n}`});render();}));
          const defaults=node('fieldset');defaults.append(node('legend','初期選択（任意）'));
          field.choices.forEach(choice=>control(defaults,choice.label||choice.value,Array.isArray(field.default)?field.default.includes(choice.value):field.default===choice.value,checked=>{
            if(field.type==='checkboxes') field.default=checked?[...new Set([...(field.default||[]),choice.value])]:(field.default||[]).filter(v=>v!==choice.value);
            else field.default=checked?choice.value:'';
            render();
          },'checkbox'));
          card.append(choices,defaults);
        }
        if(field.type==='file') {
          const options=node('fieldset');options.append(node('legend','許可する拡張子'));
          ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','txt','mp3','wav','avi','mp4','mov'].forEach(ext=>control(options,ext,(field.extensions||[]).includes(ext),checked=>{field.extensions=checked?[...(field.extensions||[]),ext]:(field.extensions||[]).filter(v=>v!==ext);},'checkbox'));
          card.append(options);
          const size=control(card,'サイズ上限（MiB、最大10）',(field.max_bytes||10485760)/1048576,v=>{field.max_bytes=Math.round(Number(v)*1048576);},'number');size.min='0.01';size.max='10';size.step='0.01';
        }
        const details=advanced;
        const key=control(details,'項目キー',field.key,v=>{field.key=v;});key.readOnly=Boolean(field.original_key);
        details.append(node('p',field.original_key?`メールには {${field.key}} を使用できます。保存済みキーは変更できません。`:'英小文字で始め、英小文字・数字・_で指定します。保存後は変更できません。'));
        card.append(details);
        const footer=node('div',null,'omf-builder-edit-footer');
        const remove=button('この項目を削除',()=>{field.deleted=true;dirty();render();announce('項目を削除予定にしました。保存前なら取り消せます。');});remove.classList.add('omf-builder-button--danger');
        footer.append(remove,button('編集を閉じる',()=>{field.open=false;render();list.children[index].querySelector('[data-edit]').focus();}));
        card.append(footer);list.append(surface);
      });
    }
    renderPalette(root.querySelector('.omf-builder-palette'),(type,label)=>{
      if(type.startsWith('preset-')) {
        const added=makeAddressPreset(type,fields.map(f=>f.key));
        if(fields.filter(f=>!f.deleted).length+added.length>100){announce('項目は100件までです。');return;}
        fields.forEach(f=>f.open=false);fields.push(...added);dirty();render();announce(`「${label}」を追加しました。`);return;
      }
      if(fields.filter(f=>!f.deleted).length>=100){announce('項目は100件までです。');return;}
      let n=1;while(fields.some(f=>f.key===`field_${n}`))n++;
      fields.forEach(f=>f.open=false);
      fields.push({key:`field_${n}`,type,label,required:type==='acceptance',default:type==='checkboxes'?[]:'',choices:label==='都道府県'?prefectures.map(name=>({label:name,value:name})):['select','radio','checkboxes'].includes(type)?[{label:'選択肢1',value:'option_1'},{label:'選択肢2',value:'option_2'}]:[],extensions:type==='file'?['pdf']:[],max_bytes:10485760,open:true});dirty();render();
      list.lastElementChild.querySelector('textarea').focus();announce(`「${label}」を追加しました。`);
    });
    root.closest('form').addEventListener('submit',event=>{
      const removed=fields.filter(f=>f.deleted&&f.original_key);
      const mailInputs=[...root.closest('form').querySelectorAll('[name^="cf_omf_reply_"],[name^="cf_omf_admin_"]')];
      const referenced=removed.find(f=>mailInputs.some(input=>input.value.includes(`{${f.key}}`)));
      if(referenced){event.preventDefault();announce(`「${referenced.label}」はメールで使用中です。削除を取り消すか、「メール」で {${referenced.key}} を外してください。`);status.tabIndex=-1;status.focus();return;}
      const active=fields.filter(f=>!f.deleted);const keys=active.map(f=>f.key);
      const bad=active.findIndex(f=>!f.label?.trim()||!/^[a-z][a-z0-9_]{0,63}$/.test(f.key)||keys.filter(k=>k===f.key).length>1);
      if(bad>=0 || (mode.value==='builder'&&!active.length)) {
        event.preventDefault();if(bad>=0){fields.forEach(f=>f.open=f===active[bad]);render();} announce(bad>=0?`項目${bad+1}の項目名・項目キーを確認してください。キーの重複は使用できません。`:'管理画面方式には項目を1つ以上追加してください。');status.tabIndex=-1;status.focus();return;
      }
      payload.value=JSON.stringify({version:1,fields:active.map(({deleted,open,...field})=>field)});payload.disabled=false;draft.save(fields,mode.value);
    });
    render();syncMode();if(recovered){dirty();announce('保存前の編集内容を復元しました。内容を確認して保存してください。');}
  }
}


// 手入力でも利用できるコピー欄。クリップボード拒否時は選択して案内する。
document.addEventListener('click', async event => {
  if (!event.target.closest('[data-omf-copy-shortcode]')) return;
  const input = document.getElementById('omf-shortcode');
  const status = document.querySelector('[data-omf-copy-status]');
  if (!input || !status) return;
  try {
    await navigator.clipboard.writeText(input.value);
    status.textContent = 'コピーしました';
  } catch {
    input.focus(); input.select();
    status.textContent = '選択した文字をコピーしてください';
  }
});
