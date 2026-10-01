// PHPから出力した共通条件で、境界値を両側が同じように判定するか確認する。
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const source=readFileSync(new URL('../src/js/frontend-validator.js',import.meta.url),'utf8');
const { validateValue }=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
const cases=JSON.parse(execFileSync('php',[new URL('./validation-parity.php',import.meta.url).pathname],{encoding:'utf8'}));
for(const item of cases){const valid=validateValue(item.value,item.checks)==='';if(valid!==item.valid)throw Error(`PHPとJSの判定不一致: ${item.checks.format} ${JSON.stringify(item.value)}`);}
const presetSource=readFileSync(new URL('../src/js/builder/choice-presets.js',import.meta.url),'utf8');
const {prefectures,parseChoiceLines,appendChoices}=await import('data:text/javascript;base64,'+Buffer.from(presetSource).toString('base64'));
if(prefectures.length!==47||new Set(prefectures).size!==47)throw Error('都道府県の件数が不正');
const added=appendChoices([{label:'北海道',value:'北海道'}],parseChoiceLines('北海道\r\n青森県\n\n 岩手県 '));
if(added.added!==2||added.choices.length!==3)throw Error('一括追加の重複除去が不正');
const capped=appendChoices([],Array.from({length:101},(_,i)=>({label:String(i),value:String(i)})));
if(capped.choices.length!==100||!capped.overflow)throw Error('選択肢の上限が不正');
console.log(`PASS: PHP/JS共通バリデーション ${cases.length}件、一括追加・47都道府県・上限`);
