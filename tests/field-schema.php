<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
class SchemaProbe {
  use Sharesl\Original\MailForm\OMF_Trait_Validation;
  public function input(array $data): array { return $this->restrict_array_values($data); }
}
$field = ['key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => true];
$schema = ['version' => 1, 'fields' => [$field,
  ['key' => 'topics', 'type' => 'checkboxes', 'label' => '分類', 'required' => true, 'choices' => [['value' => 'a,b', 'label' => '選択A'], ['value' => '0', 'label' => '選択B']], 'default' => ['0']],
  ['key' => 'agreement', 'type' => 'acceptance', 'label' => '同意'],
  ['key' => 'attachment', 'type' => 'file', 'label' => '添付', 'extensions' => ['txt'], 'max_bytes' => 1024],
]];
$normalized = Schema::normalize($schema);
check(is_array($normalized) && $normalized['fields'][2]['required'], '同意項目は必須・標準値を補完');
check(Schema::normalize($normalized) === $normalized, '保存・再読込で定義が変化しない');
$legacy = [['target' => 'old', 'required' => '1']];
$GLOBALS['meta'][10]['cf_omf_validation'] = $legacy;
check(Schema::mode(10) === 'code' && Schema::rules(10) === $legacy, '未設定はコード方式・旧ルールを無変更で返す');
$GLOBALS['meta'][10][Schema::META_KEY] = $normalized;
check(Schema::rules(10) === $legacy, '定義保存だけで既存フォームを切り替えない');
$GLOBALS['meta'][10][Schema::MODE_KEY] = 'builder';
$rules = Schema::rules(10);
check($rules[0]['email'] === '1' && $rules[3]['file_size'] === '1024', '型と添付上限を既存検証へ変換');
$GLOBALS['meta'][20]['cf_omf_select'] = 'test';
$p = new SchemaProbe();
$data = $p->input(['email' => 'user@example.test', 'topics' => ['a,b', '0'], 'agreement' => '1', 'unknown' => 'drop']);
check(!isset($data['unknown']) && $p->validate_mail_form_data($data) === [], '未定義項目を受理せずカンマ・0の選択値を保持');
$bad = $data; $bad['topics'] = ['a'];
check(isset($p->validate_mail_form_data($bad)['topics']), '選択肢はカンマで分割せず完全一致');
$bad = $data; $bad['agreement'] = 'yes';
check(isset($p->validate_mail_form_data($bad)['agreement']), '同意値の改ざんを拒否');
$bad = $data; unset($bad['agreement']);
check(isset($p->validate_mail_form_data($bad)['agreement']), '同意なしを拒否');
$bad = $p->input(['email' => ['user@example.test'], 'topics' => 'a,b', 'agreement' => '1']);
check(count($p->validate_mail_form_data($bad)) >= 2, '単一/複数選択の型不一致を拒否');
foreach (['omf_token', 'send', 'mail_id', 'email'] as $key) {
  $invalid = $schema; $invalid['fields'][] = array_replace($field, ['key' => $key]);
  check(is_wp_error(Schema::normalize($invalid)), '予約・重複キーを拒否: ' . $key);
}
$invalid = $schema; $invalid['fields'][1]['choices'][] = ['value' => '0', 'label' => '重複'];
check(is_wp_error(Schema::normalize($invalid)), '重複する選択値を拒否');
$invalid = $schema; $invalid['fields'][1]['default'] = ['unknown'];
check(is_wp_error(Schema::normalize($invalid)), '選択肢外の初期値を拒否');
$invalid = $schema; $invalid['fields'][3]['extensions'] = ['php'];
check(is_wp_error(Schema::normalize($invalid)), '危険な添付設定を拒否');
$reordered = $schema; $reordered['fields'] = array_reverse($schema['fields']);
check(array_column(Schema::normalize($reordered)['fields'], 'key') === ['attachment', 'agreement', 'topics', 'email'], '順序だけを変更して項目キーを維持');
$invalid = $schema; $invalid['version'] = 2; $GLOBALS['meta'][10][Schema::META_KEY] = $invalid;
check(is_wp_error(Schema::rules(10)) && isset($p->validate_mail_form_data($data)['undefined']), '未知の版で空の検証ルールへフォールバックしない');
check($GLOBALS['meta'][10]['cf_omf_validation'] === $legacy, '管理画面方式でも旧設定を書き換えない');
echo "項目定義テスト完了\n";
// 管理画面の検証設定が実際のPHP送信検証へ渡ることを確認する。

$configured = ['version' => 1, 'fields' => [
  ['key' => 'message', 'type' => 'text', 'label' => '郵便番号', 'min_length' => 7, 'max_length' => 8, 'validation_format' => 'postal_code']
]];
$GLOBALS['meta'][10][Schema::META_KEY] = Schema::normalize($configured);
check(isset($p->validate_mail_form_data(['message' => '12-34567'])['message']), '管理画面の形式条件をPHPでも拒否');
check(isset($p->validate_mail_form_data(['message' => '123'])['message']), '管理画面の最小文字数をPHPでも拒否');
check($p->validate_mail_form_data(['message' => '123-4567']) === [], '管理画面の同じ条件に合う値をPHPで受理');
$configured['fields'][0]['min_length'] = 9;
check(is_wp_error(Schema::normalize($configured)), '最小文字数が最大文字数を超える設定を拒否');
$configured['fields'][0]['min_length'] = 0; $configured['fields'][0]['validation_format'] = 'unknown';
check(is_wp_error(Schema::normalize($configured)), '未知の検証形式を拒否');
$address_schema = ['version' => 1, 'fields' => [
  ['key'=>'postal_code','type'=>'text','label'=>'郵便番号','validation_format'=>'postal_code','address_targets'=>['full'=>'address']],
  ['key'=>'address','type'=>'text','label'=>'住所']
]];
check(!is_wp_error(Schema::normalize($address_schema)), '住所自動入力の参照先を保存できる');
$invalid_address=$address_schema;$invalid_address['fields'][0]['address_targets']['full']='missing';
check(is_wp_error(Schema::normalize($invalid_address)), '削除・不在の住所入力先を拒否');
$invalid_address=$address_schema;$invalid_address['fields'][0]['address_targets']=['prefecture'=>'address','city'=>'address'];
check(is_wp_error(Schema::normalize($invalid_address)), '重複する住所入力先を拒否');

$GLOBALS['meta'][10][Schema::META_KEY] = Schema::normalize(['version'=>1,'fields'=>[['key'=>'message','type'=>'text','label'=>'任意の文字数','min_length'=>2,'max_length'=>5]]]);
check($p->validate_mail_form_data(['message'=>'']) === [], '任意の文字数検証は空欄を許可');
check(isset($p->validate_mail_form_data(['message'=>'あ'])['message']), '任意の文字数検証も入力時は最小文字数を適用');

$multiline=Schema::normalize(['version'=>1,'fields'=>[['key'=>'label_test','type'=>'text','label'=>"表示名\n補足<script>x</script>"]]]);
check($multiline['fields'][0]['label']==="表示名\n補足x", 'ラベル改行を保存し任意HTMLを除去');
check(Schema::normalize($multiline)===$multiline, '改行付きラベルの再保存で内容を維持');
