// よく使う選択肢。保存時には通常の選択肢として展開する。
export const prefectures = [
  '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県','茨城県','栃木県','群馬県',
  '埼玉県','千葉県','東京都','神奈川県','新潟県','富山県','石川県','福井県','山梨県','長野県',
  '岐阜県','静岡県','愛知県','三重県','滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県',
  '鳥取県','島根県','岡山県','広島県','山口県','徳島県','香川県','愛媛県','高知県','福岡県',
  '佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県',
];

export function parseChoiceLines(text) {
  return text.split(/\r?\n/).map(line=>line.trim()).filter(Boolean).map(label=>({label,value:label}));
}

export function appendChoices(current, incoming, limit=100) {
  const result=[...current];let added=0;
  const values=new Set(current.map(choice=>choice.value));
  incoming.forEach(choice=>{
    if(result.length>=limit || values.has(choice.value)) return;
    result.push(choice);values.add(choice.value);added++;
  });
  return {choices:result,added,overflow:incoming.length>added && result.length>=limit};
}
