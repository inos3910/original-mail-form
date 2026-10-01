<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
use Sharesl\Original\MailForm\OMF_Form_Builder as Builder;
use Sharesl\Original\MailForm\OMF_Field_Renderer as Field;
function esc_attr($s){return htmlspecialchars($s, ENT_QUOTES);}
function esc_textarea($s){return htmlspecialchars($s, ENT_QUOTES);}
function selected($a,$b,$echo=true){$s=$a===$b?' selected':'';if($echo)echo $s;return $s;}
function checked($a,$b=true,$echo=true){$s=$a==$b?' checked':'';if($echo)echo $s;return $s;}
$template=$argv[1] ?? '';
if (!is_file($template)) { throw new RuntimeException('検証するテーマのfields.phpを指定してください。'); }
function render_theme($definitions,$template){
  $fields=[];
  foreach($definitions as $field){$fields[$field['key']]=Field::html($field,$field['default'],[],false,10);}
  ob_start(); include $template; return ob_get_clean();
}
function outer_order($html){
  preg_match_all('/<div class="(?:contact-form__row[^"\n]*|js-form-item|omf-managed-field[^"\n]*)" data-omf-field="([^"]+)"/',$html,$matches);
  return $matches[1];
}
$fields=[
  ['key'=>'consent_b','type'=>'acceptance','label'=>'利用規約','description'=>'[利用規約](https://example.org/terms/)に同意します。'],
  ['key'=>'person','type'=>'text','label'=>"お名前\n（匿名可）",'placeholder'=>'変更した案内','required'=>true],
  ['key'=>'channel','type'=>'radio','label'=>"希望する\n連絡方法",'choices'=>[['value'=>'mail','label'=>'メールを希望'],['value'=>'phone','label'=>'電話を希望']]],
  ['key'=>'consent_a','type'=>'acceptance','label'=>'別の同意'],
  ['key'=>'topic','type'=>'select','label'=>'お問い合わせ種類','choices'=>[['value'=>'other','label'=>'その他']]],
  ['key'=>'detail','type'=>'textarea','label'=>'追加した本文','description'=>'変更した説明'],
  ['key'=>'attachment','type'=>'file','label'=>'資料','extensions'=>['pdf'],'max_bytes'=>1024],
];
$prepared=Builder::prepare(10,['omf_builder_mode'=>'builder','omf_builder_schema'=>json_encode(['version'=>1,'fields'=>$fields])]);
Builder::persist(10,$prepared); $saved=Schema::read(10)['fields']; $html=render_theme($saved,$template);
check(outer_order($html)===['consent_b','person','channel','consent_a','topic','detail','attachment'], '項目HTMLは同意を含め管理設定の全体順序を保持し、配置はCSSに任せる');
check(str_contains($html,'お名前<br') && str_contains($html,'希望する<br') && !str_contains($html,'当事務所からの'), '項目キーによらず管理設定の改行を表示');
check(str_contains($html,'変更した案内') && str_contains($html,'変更した説明') && str_contains($html,'メールを希望'), '名前・説明・placeholder・選択肢の変更が反映');
check(str_contains($html,'href="https://example.org/terms/"') && str_contains($html,'rel="noopener noreferrer"') && str_contains($html,'別の同意') && str_contains($html,'同意する'), '外部リンクと説明未設定の追加同意を描画');
check(str_contains($html,'omf-managed-field--radio') && str_contains($html,'omf-managed-field--select') && substr_count($html,'class="contact-form__row"') === count($saved), 'テーマの外枠とOMFの入力形式クラスだけで配置できる');
$fields=array_reverse($fields); $fields=array_values(array_filter($fields,fn($f)=>$f['key']!=='person'));
$fields[]=['key'=>'added_url','type'=>'url','label'=>'新しいURL'];
Builder::persist(10,Builder::prepare(10,['omf_builder_mode'=>'builder','omf_builder_schema'=>json_encode(['version'=>1,'fields'=>$fields])]));
$html=render_theme(Schema::read(10)['fields'],$template);
check(outer_order($html)===['attachment','detail','topic','consent_a','channel','consent_b','added_url'] && !str_contains($html,'omf_fields[person]'), '追加・削除・並べ替えを保存後の描画へ反映');
echo "テーマの管理設定変更テスト完了\n";
