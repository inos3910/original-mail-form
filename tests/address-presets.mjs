import {readFileSync} from 'node:fs';
const moduleUrl=source=>'data:text/javascript;base64,'+Buffer.from(source).toString('base64');
const choices=moduleUrl(readFileSync(new URL('../src/js/builder/choice-presets.js',import.meta.url),'utf8'));
const source=readFileSync(new URL('../src/js/builder/address-presets.js',import.meta.url),'utf8').replace("'./choice-presets'",JSON.stringify(choices));
const {makeAddressPreset}=await import(moduleUrl(source));
const {normalizePostalCode,addressValues}=await import(moduleUrl(readFileSync(new URL('../src/js/postal-address.js',import.meta.url),'utf8')));
for(const [kind,size] of [['preset-postal',1],['preset-address-line',2],['preset-address-split',4]]) {
 const preset=makeAddressPreset(kind,['postal_code','address','prefecture']);
 if(preset.length!==size||preset[0].key!=='postal_code_2'||preset[0].validation_format!=='postal_code')throw Error('プリセットまたは重複防止が不正');
 for(const target of Object.values(preset[0].address_targets||{}))if(!preset.some(field=>field.key===target))throw Error('自動入力の参照先が不正');
}
for(const value of ['１００-０００１','１００－０００１','１００−０００１','１００ー０００１']) {
 if(normalizePostalCode(value)!=='1000001')throw Error('全角数字・ハイフンの正規化が不正');
}
const data=JSON.parse(readFileSync(new URL('../assets/postal/100.json',import.meta.url),'utf8'));
if(addressValues(data['1000001'][0]).full!=='東京都千代田区千代田')throw Error('日本郵便の同梱データが不正');
console.log('PASS: 3種類の住所プリセット、重複キー、参照先、全角郵便番号、同梱住所データ');
