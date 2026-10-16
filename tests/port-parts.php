<?php
require __DIR__ . '/port-helpers.php';
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Apply;
use Sharesl\Original\MailForm\OMF_Port_Plan;
use Sharesl\Original\MailForm\OMF_Port_Reader;
use Sharesl\Original\MailForm\OMF_Port_Request;
use Sharesl\Original\MailForm\OMF_Port_Schema;

// 項目（parts）の振り分け
$expected = [
  'info' => ['cf_omf_form_mode', 'cf_omf_field_schema', 'cf_omf_render_enabled'],
  'validation' => ['cf_omf_validation', 'cf_omf_front_validation'],
  'screen' => ['cf_omf_screen_entry', 'cf_omf_screen_confirm', 'cf_omf_screen_complete', 'cf_omf_skip_confirm', 'cf_omf_complete_message', 'cf_omf_condition_post', 'cf_omf_condition_id'],
  'mail' => ['cf_omf_admin_to', 'cf_omf_admin_from', 'cf_omf_admin_from_name', 'cf_omf_admin_reply_to', 'cf_omf_admin_title', 'cf_omf_admin_mail', 'cf_omf_reply_to', 'cf_omf_reply_from', 'cf_omf_reply_from_name', 'cf_omf_reply_address', 'cf_omf_reply_title', 'cf_omf_reply_mail', 'cf_omf_disable_reply_mail'],
  'other' => ['cf_omf_recaptcha', 'cf_omf_turnstile', 'cf_omf_save_db', 'cf_omf_delivery_mode', 'cf_omf_is_slack_notify', 'cf_omf_slack_channel', 'cf_omf_is_google_sheets', 'cf_omf_google_sheets_id', 'cf_omf_google_sheets_name', 'cf_omf_custom_extra'],
];
$ok = true;
foreach ($expected as $part => $keys) { foreach ($keys as $key) { $ok = $ok && OMF_Port_Schema::part_of($key) === $part; } }
check($ok, 'メタキーを info / validation / screen / mail / other に振り分ける（未知の cf_omf_ は other）');
check(!OMF_Port_Schema::is_part_meta('cf_omf_mail_id', OMF_Port_Schema::part_keys()) && !OMF_Port_Schema::is_part_meta('cf_omf_data_memo', OMF_Port_Schema::part_keys()) && !OMF_Port_Schema::is_part_meta('cf_omf_slack_webhook_url', OMF_Port_Schema::part_keys()) && !OMF_Port_Schema::is_part_meta('other_meta', OMF_Port_Schema::part_keys()), 'メールID・メモ・秘密情報・cf_omf_以外は常に対象外');

// parts 指定の書き出し
build_source();
$mail_only = export_file(['settings' => false, 'forms' => 'all', 'parts' => ['mail']]);
$form = $mail_only['forms'][0];
check($form['parts'] === ['mail'] && array_keys($form['meta']) === ['cf_omf_admin_title', 'cf_omf_admin_to'], '選んだ項目のメタだけを meta に入れる');
check(!isset($form['condition_targets']) && !isset($form['linked_pages']) && $form['slug'] === 'contact-form' && $form['title'] === 'お問い合わせ' && $form['status'] === 'publish', 'screen が無ければ condition_targets・linked_pages を出さず、slug・title・status は常に出す');
$screen = export_file(['settings' => false, 'forms' => [10], 'parts' => ['screen', 'info', 'bogus']])['forms'][0];
check($screen['parts'] === ['info', 'screen'] && isset($screen['condition_targets']) && isset($screen['linked_pages']) && !isset($screen['meta']['cf_omf_admin_to']) && isset($screen['meta']['cf_omf_screen_complete']), '未知の項目は除き、screen がある時は表示条件と連携ページを出す');
$info_only = export_file(['settings' => false, 'forms' => 'all', 'parts' => ['info']]);
$screen_only = export_file(['settings' => false, 'forms' => 'all', 'parts' => ['screen']]);
$other = export_file(['settings' => false, 'forms' => 'all', 'parts' => ['other']])['forms'][0];
check(array_keys($other['meta']) === ['cf_omf_custom_extra', 'cf_omf_save_db'], 'other は未分類の cf_omf_ メタを含み、メモ・Webhook は出さない');

// 画面の入力から書き出し引数へ
$req = OMF_Port_Request::export_options(['scope' => 'all']);
check($req['forms'] === 'all' && $req['settings'] === true && $req['parts'] === OMF_Port_Schema::part_keys(), 'scope=all は全フォーム・全項目・共通設定');
$req = OMF_Port_Request::export_options(['scope' => 'settings', 'form_ids' => ['10']]);
check($req['forms'] === null && $req['settings'] === true, 'scope=settings は共通設定だけ（フォームは無視）');
$req = OMF_Port_Request::export_options(['scope' => 'forms', 'form_ids' => ['10', 'x', '10'], 'parts' => ['mail', 'zzz', 'info'], 'include_settings' => '1']);
check($req['forms'] === [10] && $req['parts'] === ['info', 'mail'] && $req['settings'] === true, 'scope=forms は選んだID・既知の項目・共通設定の有無を渡す');
check(OMF_Port_Request::export_options(['scope' => 'forms', 'form_ids' => ['10'], 'parts' => ['mail']])['settings'] === false, '共通設定は既定で含めない');
foreach ([['scope' => 'forms', 'parts' => ['mail']], ['scope' => 'forms', 'form_ids' => ['10']], ['scope' => 'forms', 'form_ids' => ['10'], 'parts' => ['bogus']], ['scope' => 'x'], []] as $i => $bad) {
  check(is_wp_error(OMF_Port_Request::export_options($bad)), 'フォーム0件・項目0件・範囲未選択はエラーにする(' . $i . ')');
}
$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['can_edit'] = true; $GLOBALS['admin_nonce'] = true; $_POST = ['scope' => 'forms']; $redirect = '';
try { OMF_Port_Admin::handle_export(); } catch (RuntimeException $e) { $redirect = $e->getMessage(); }
check(str_contains($redirect, 'redirect:') && OMF_Port_Admin::take('omf_port_notice_')['error'] === true, '範囲の選択が足りない書き出しはダウンロードせず notice を出す');
$_POST = [];

// parts の無い既存ファイルは全項目入りとして扱う
build_source();
$old = export_file(['settings' => true, 'forms' => 'all']);
unset($old['forms'][0]['parts']);
build_target();
$payload = OMF_Port_Reader::read(json_encode($old));
check($payload['forms'][0]['parts'] === OMF_Port_Schema::part_keys() && $payload['warnings'] === [], 'parts キーが無いフォームは全項目入りとして警告なしで読む');
import_json($old);
check($GLOBALS['meta'][900]['cf_omf_admin_to'] === 'admin@example.test' && $GLOBALS['meta'][900]['cf_omf_save_db'] === '1' && $GLOBALS['wp_posts'][900]->post_title === 'お問い合わせ', 'parts の無いファイルは従来どおり全項目を取り込む');

// 未知の parts と、宣言外のメタ
$odd = $mail_only; $odd['forms'][0]['parts'] = ['mail', 'zzz']; $odd['forms'][0]['meta']['cf_omf_save_db'] = '0'; $odd['forms'][0]['meta']['cf_omf_data_memo'] = 'x';
$payload = OMF_Port_Reader::read(json_encode($odd));
check($payload['forms'][0]['parts'] === ['mail'] && !isset($payload['forms'][0]['meta']['cf_omf_save_db']) && !isset($payload['forms'][0]['meta']['cf_omf_data_memo']) && count($payload['warnings']) === 3, '未知の parts・宣言された項目に属さないメタ・メモは警告して無視する');
$empty = $mail_only; $empty['forms'][0]['parts'] = ['zzz'];
check(OMF_Port_Reader::read(json_encode($empty))['forms'][0]['errors'] !== [], '取り込める項目が1つも無いフォームは中止する');

// 部分取り込みで、他の項目に触れない
function existing_form(): void {
  build_target();
  add_test_post(700, 'original_mail_forms', 'contact-form', '既存のタイトル', 'draft');
  $GLOBALS['meta'][700] = ['cf_omf_mail_id' => '5', 'cf_omf_admin_title' => '古い件名', 'cf_omf_admin_mail' => '古い本文', 'cf_omf_save_db' => '0', 'cf_omf_screen_complete' => '9', 'cf_omf_condition_id' => '777', 'cf_omf_slack_webhook_url' => 'https://keep.example.test', 'cf_omf_data_memo' => 'memo', 'unrelated' => 'keep'];
  $GLOBALS['meta'][501]['cf_omf_select'] = 'other-form';
}
existing_form();
$plan = OMF_Port_Plan::build(OMF_Port_Reader::read(json_encode($mail_only)));
check($plan['forms'][0]['removals'] === ['cf_omf_admin_mail'] && $plan['forms'][0]['parts'] === ['mail'], '上書きで削除されるのは、含まれる項目に属しファイルに無いメタだけ');
$result = import_json($mail_only);
$m = $GLOBALS['meta'][700];
check($result['updated'] === 1 && $m['cf_omf_admin_title'] === '件名\\テスト' && !isset($m['cf_omf_admin_mail']) && $m['cf_omf_admin_to'] === 'admin@example.test', 'mail だけのファイルで mail のメタが更新・削除される');
check($m['cf_omf_save_db'] === '0' && $m['cf_omf_screen_complete'] === '9' && $m['cf_omf_condition_id'] === '777' && $m['cf_omf_mail_id'] === '5' && $m['cf_omf_slack_webhook_url'] === 'https://keep.example.test' && $m['cf_omf_data_memo'] === 'memo' && $m['unrelated'] === 'keep', '他の項目・メールID・秘密情報・メモ・他プラグインのメタは変更されない');
check($GLOBALS['wp_posts'][700]->post_title === '既存のタイトル' && $GLOBALS['wp_posts'][700]->post_status === 'draft', 'info が無ければタイトルと公開状態は変わらない');
check($GLOBALS['meta'][501]['cf_omf_select'] === 'other-form', 'screen が無ければ連携ページは変わらない');
existing_form();
import_json($info_only);
check($GLOBALS['wp_posts'][700]->post_title === 'お問い合わせ' && $GLOBALS['wp_posts'][700]->post_status === 'publish' && $GLOBALS['meta'][700]['cf_omf_form_mode'] === 'builder' && $GLOBALS['meta'][700]['cf_omf_admin_title'] === '古い件名', 'info があればタイトル・公開状態を更新し、他の項目は残す');
existing_form();
import_json($screen_only);
check($GLOBALS['meta'][700]['cf_omf_condition_id'] === '501,502' && $GLOBALS['meta'][501]['cf_omf_select'] === 'contact-form' && $GLOBALS['wp_posts'][700]->post_title === '既存のタイトル' && $GLOBALS['meta'][700]['cf_omf_screen_complete'] === '55' && $GLOBALS['meta'][700]['cf_omf_admin_title'] === '古い件名', 'screen があれば表示条件と連携ページを更新する');
$stray = $mail_only; $stray['forms'][0]['condition_targets'] = [['post_type' => 'page', 'path' => 'contact']];
$payload = OMF_Port_Reader::read(json_encode($stray));
check($payload['forms'][0]['condition_targets'] === null && count($payload['warnings']) === 1, 'screen が無いファイルの condition_targets は警告して無視する');

// 部分的な新規作成
build_target();
$plan = OMF_Port_Plan::build(OMF_Port_Reader::read(json_encode($mail_only)));
check($plan['forms'][0]['action'] === 'create' && count($plan['warnings']) === 1 && str_contains($plan['warnings'][0], '含まれない項目') && str_contains($plan['warnings'][0], 'フォーム情報'), '全項目でない新規作成は、未設定のまま作成される旨を警告する');
$result = import_json($mail_only);
check($result['created'] === 1 && $GLOBALS['wp_posts'][900]->post_title === 'お問い合わせ' && isset($GLOBALS['meta'][900]['cf_omf_admin_to']) && !isset($GLOBALS['meta'][900]['cf_omf_form_mode']) && str_contains($result['warnings'][0], '未設定のまま作成'), '含まれる項目だけで作成し、タイトルと状態はファイルの値を使い、結果にも警告が残る');

// 古いファイルの秘密情報は取り込まない
$legacy = $old; $legacy['includes_secrets'] = true;
$legacy['settings'] += ['omf_recaptcha_site_key' => 'NEWSITE', 'omf_recaptcha_secret_key' => 'NEWSECRET', 'omf_google_client_id' => 'gid', 'omf_google_client_secret' => 'gsec'];
$legacy['forms'][0]['meta']['cf_omf_slack_webhook_url'] = 'https://hooks.example.test/NEW';
existing_form(); $GLOBALS['options']['omf_recaptcha_site_key'] = 'KEEP_SITE'; $GLOBALS['options']['omf_recaptcha_secret_key'] = 'KEEP_SECRET';
$payload = OMF_Port_Reader::read(json_encode($legacy));
check(count($payload['warnings']) === 5 && !isset($payload['settings']['omf_recaptcha_secret_key']) && !isset($payload['forms'][0]['meta']['cf_omf_slack_webhook_url']), 'ファイルの秘密情報（認証キー・Webhook URL）は読み込まず、警告する');
$plan = OMF_Port_Plan::build($payload);
$view = render_template('omf_port_confirm.php', ['omf_port_result' => null, 'omf_port_pending' => ['token' => 't', 'payload' => $payload]]);
check(str_contains($view, '秘密情報') && !str_contains($view, 'NEWSECRET') && !str_contains($view, 'hooks.example.test'), '確認画面に警告を出し、秘密情報の値は出さない');
OMF_Port_Apply::run($plan);
check($GLOBALS['options']['omf_recaptcha_site_key'] === 'KEEP_SITE' && $GLOBALS['options']['omf_recaptcha_secret_key'] === 'KEEP_SECRET' && !isset($GLOBALS['options']['omf_google_client_id']) && $GLOBALS['meta'][700]['cf_omf_slack_webhook_url'] === 'https://keep.example.test', '取り込み先の秘密情報は上書きも削除もされない');

echo "項目（parts）のテスト完了\n";
