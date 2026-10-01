<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Mail_Defaults as Defaults;
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
class DefaultPost extends WP_Post { public $post_status = 'auto-draft'; }
class DefaultMailProbe { use Sharesl\Original\MailForm\OMF_Trait_Send; }
$post = new DefaultPost();
check(str_contains(Defaults::value($post, 'cf_omf_reply_mail', ''), '{form_data}'), '新規フォームに全項目タグを含む返信文面を表示');
check(Defaults::value($post, 'cf_omf_reply_mail', '既存本文') === '既存本文', '入力済み本文を上書きしない');
$post->post_status = 'publish';
check(Defaults::value($post, 'cf_omf_reply_mail', '') === '', '保存済みフォームの空欄は自動変更しない');
$GLOBALS['meta'][10][Schema::MODE_KEY] = 'builder';
$GLOBALS['meta'][10][Schema::META_KEY] = Schema::normalize(['version'=>1,'fields'=>[
  ['key'=>'topic','type'=>'select','label'=>'ご相談','choices'=>[['value'=>'a','label'=>'選択肢A']]],
  ['key'=>'agreement','type'=>'acceptance','label'=>'個人情報保護方針への同意'],
  ['key'=>'message','type'=>'textarea','label'=>'本文'],
]]);
$tags = call_private(new DefaultMailProbe(), 'mail_display_values', ['topic'=>'a','agreement'=>'1','message'=>'{site_name}'], 10);
check($tags['form_data'] === "ご相談：選択肢A\n\n個人情報保護方針への同意：同意する\n\n本文：{site_name}", '全項目タグは順序・ラベル・同意・選択値を表示用に整形');
$text = call_private(new DefaultMailProbe(), 'replace_form_mail_tags', '{form_data}', $tags + ['site_name'=>'置換しない']);
check(str_contains($text, '本文：{site_name}'), '入力値内のメールタグを再帰置換しない');
check(is_wp_error(Schema::normalize(['version'=>1,'fields'=>[['key'=>'form_data','type'=>'text','label'=>'予約タグ']]])), '全項目タグと入力項目名の衝突を拒否');

$GLOBALS['meta'][10][Schema::META_KEY]['fields'][0]['label']="ご相談\nの種類";
$tags=call_private(new DefaultMailProbe(),'mail_display_values',['topic'=>'a'],10);
check(str_starts_with($tags['form_data'],'ご相談の種類：選択肢A'), 'メールのラベルは画面用の改行を除き1行で表示');
