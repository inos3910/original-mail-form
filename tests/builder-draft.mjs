// 保存済みの設定を未保存として復元せず、保存失敗の編集内容だけを残す。
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../src/js/builder/draft.js',import.meta.url),'utf8');
const {createDraft}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
const memory=new Map();
globalThis.location={pathname:'/wp-admin/post.php'};
globalThis.sessionStorage={getItem:key=>memory.get(key)||null,setItem:(key,value)=>memory.set(key,value),removeItem:key=>memory.delete(key)};
const root={closest:()=>({querySelector:()=>({value:'10'})})};
const schema={version:1,fields:[{key:'email',type:'email',label:'メール',required:true}]};
const fields=()=>schema.fields.map(field=>({...field,original_key:field.key,open:true}));
function test(label,run){memory.clear();run();console.log('PASS: '+label);}
test('項目を変えずに保存した退避データは復元しない',()=>{const draft=createDraft(root,schema);draft.save(fields(),'builder');assert.equal(draft.restore('builder'),null);assert.equal(memory.size,0);});
test('完了メッセージだけの保存で項目を未保存に戻さない',()=>{const draft=createDraft(root,schema);draft.save(fields(),'builder');assert.equal(createDraft(root,schema).restore('builder'),null);});
test('保存失敗のラベル変更は復元する',()=>{const changed=fields();changed[0].label='変更したメール';const draft=createDraft(root,schema);draft.save(changed,'builder');assert.equal(draft.restore('builder').fields[0].label,'変更したメール');});
test('保存成功でサーバー定義が変わったら古い編集内容を復元しない',()=>{const changed=fields();changed[0].label='変更したメール';createDraft(root,schema).save(changed,'builder');assert.equal(createDraft(root,{version:1,fields:[{...schema.fields[0],label:'変更したメール'}]}).restore('builder'),null);});
test('方式変更の保存失敗は復元し、成功後は復元しない',()=>{const draft=createDraft(root,schema);draft.save(fields(),'builder');assert.equal(draft.restore('code').mode,'builder');draft.save(fields(),'builder');assert.equal(draft.restore('builder'),null);});
test('削除予定の保存失敗は復元する',()=>{const changed=fields();changed[0].deleted=true;const draft=createDraft(root,schema);draft.save(changed,'builder');assert.equal(draft.restore('builder').fields[0].deleted,true);});
