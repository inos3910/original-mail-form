// 編集する項目と入力欄の見本を同じ定義から組み立てる。
const element = (tag, text, className) => {
  const el=document.createElement(tag);
  if(text) el.textContent=text;
  if(className) el.className=className;
  return el;
};
export function renderPalette(parent,add) {
  const items=[['text','お名前','短い文章'],['email','メールアドレス','メールの入力'],['tel','電話番号','電話の入力'],['textarea','お問い合わせ内容','長い文章'],['select','選択リスト','一覧から1つ'],['select','都道府県','47件をまとめて用意'],['radio','単一選択','ボタンで1つ'],['checkboxes','複数選択','いくつでも選択'],['acceptance','同意事項','規約などへの同意'],['file','添付ファイル','画像や資料'],['url','URL','Webアドレス'],['preset-postal','郵便番号','7桁の番号'],['preset-address-line','郵便番号＋住所（1行）','住所の自動入力付き'],['preset-address-split','郵便番号＋住所（分割）','都道府県・住所1・住所2']];
  items.forEach(([type,label,hint])=>{
    const b=element('button',null,'omf-builder-type');b.type='button';
    b.append(element('strong','＋ '+label),element('span',hint));
    b.addEventListener('click',()=>add(type,label));parent.append(b);
  });
}
export function renderPreview(parent,field) {
  parent.replaceChildren();
  parent.setAttribute('aria-label',`${field.label||'新しい項目'}の入力欄の見本`);
  const description=()=>{const container=element('span');const pattern=/\[([^\]\r\n]+)\]\((\/(?!\/)[^\s)]+|https?:\/\/[^\s)]+)\)/g;let offset=0;let match;
    while((match=pattern.exec(field.description))!==null){container.append(document.createTextNode(field.description.slice(offset,match.index)),element('u',match[1]));offset=pattern.lastIndex;}
    container.append(document.createTextNode(field.description.slice(offset)));return container;};
  if(field.type==='acceptance') {
    if(field.policy_text) parent.append(element('div',field.policy_text,'omf-managed-policy'));
    const choice=element('span',null,'omf-builder-preview-option');choice.append(element('span','□'),field.description?description():element('span','同意する'));parent.append(choice);return;
  }
  if(field.description){const note=element('p');note.append(description());parent.append(note);}
  if(['radio','checkboxes'].includes(field.type)) {
    const choices=field.choices||[];
    choices.forEach(choice=>{const label=element('span',null,'omf-builder-preview-option');label.append(element('span',field.type==='radio'?'○':'□'),element('span',choice.label||'選択肢'));parent.append(label);});
  } else {
    const text=field.type==='file'?'＋ ファイルを選択':field.type==='select'?`${field.choices?.[0]?.label||'選択してください'} ▾`:field.placeholder||({email:'example@email.com',tel:'090-0000-0000',url:'https://example.com',textarea:'こちらに内容を入力'}[field.type]||'こちらに入力');
    const box=element('div',text,'omf-builder-preview-input');if(field.type==='textarea')box.classList.add('is-multiline');parent.append(box);
  }
}
