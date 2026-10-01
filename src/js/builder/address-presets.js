import { prefectures } from './choice-presets';
// セット内の参照先は、既存の項目キーと重ならないキーで作る。
export function makeAddressPreset(kind, existingKeys) {
  const used=new Set(existingKeys);
  const field=(key,type,label)=>{let candidate=key,n=2;while(used.has(candidate))candidate=`${key}_${n++}`;used.add(candidate);return {key:candidate,type,label,required:false,default:'',choices:[],extensions:[],max_bytes:10485760,description:'',placeholder:'',min_length:0,max_length:0,validation_format:'',open:false};};
  const postal=field('postal_code','text','郵便番号');postal.validation_format='postal_code';postal.placeholder='123-4567';
  const result=[postal];
  if(kind==='preset-address-line') {
    const address=field('address','text','住所');postal.address_targets={full:address.key};result.push(address);
  } else if(kind==='preset-address-split') {
    const prefecture=field('prefecture','select','都道府県');prefecture.choices=prefectures.map(label=>({label,value:label}));
    const city=field('address1','text','住所1（市区町村・町域）');
    const street=field('address2','text','住所2（番地・マンション名等）');
    postal.address_targets={prefecture:prefecture.key,city:city.key};result.push(prefecture,city,street);
  }
  postal.open=true;return result;
}
