// 保存エラーでページを離れても、同じタブで編集内容を復元する。
export function createDraft(root,schema) {
  const id=root.closest('form').querySelector('[name="post_ID"]')?.value;
  const key=`omf-builder-draft:${location.pathname}:${id}`;
  const base=JSON.stringify(schema);
  // 編集UI専用の値を除いて比較し、同じ設定を保存した後の再復元を防ぐ。
  const comparable=fields=>JSON.stringify(fields.filter(field=>!field.deleted).map(({open,deleted,original_key,...field})=>field));
  return {
    save(fields,mode){try{sessionStorage.setItem(key,JSON.stringify({base,fields,mode,time:Date.now()}));}catch{/* ストレージ制限下でも保存処理を続ける。 */}},
    restore(currentMode){
      try {
        const raw=sessionStorage.getItem(key);if(!raw)return null;
        const draft=JSON.parse(raw);sessionStorage.removeItem(key);
        if(draft.base!==base||Date.now()-draft.time>3600000||!Array.isArray(draft.fields)||!['code','builder'].includes(draft.mode))return null;
        // 項目以外の設定だけを保存した場合も、保存済みの項目を未保存にしない。
        if(draft.mode===currentMode&&comparable(draft.fields)===comparable(schema.fields))return null;
        return draft;
      }catch{return null;}
    }
  };
}
