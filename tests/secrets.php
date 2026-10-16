<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Port_Schema;
use Sharesl\Original\MailForm\OMF_Secret_Field;
use Sharesl\Original\MailForm\OMF_Secrets;

// このテスト専用の最小スタブ。
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_url_raw($s){return filter_var($s,FILTER_VALIDATE_URL)?$s:'';}
function wp_kses_post($s){return $s;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function settings_fields($g){}
function do_settings_sections($g){}
function submit_button(){}
function get_the_title($id){return 'テスト';}
function get_site_icon_url(){return '';}
function admin_url($p=''){return 'https://example.test/wp-admin/'.$p;}

class SlackProbe { use Sharesl\Original\MailForm\OMF_Trait_Slack; }
class CaptchaProbe { use Sharesl\Original\MailForm\OMF_Trait_Captcha; public function verify($o,$f){return $this->verify_captcha_response('https://captcha.example.test/verify',$o,$f);} }
class TemplateProbe {
  public function render(string $template): string {
    ob_start(); include dirname(__DIR__) . '/templates/' . $template; return ob_get_clean();
  }
  private function get_google_settings_values(): array {
    return ['client_id'=>'cid','client_secret'=>OMF_Secrets::option('omf_google_client_secret'),'redirect_uri'=>'https://example.test/cb','access_token'=>''];
  }
  private function get_google_auth_url($a,$b){return 'https://accounts.example.test/auth';}
}
function reset_state(){$GLOBALS['options']=[];$GLOBALS['meta']=[];$_POST=[];}
$plain = 'SECRET-VALUE-1234567890';
$name = 'omf_recaptcha_secret_key';

// 暗号化して保存
reset_state();
OMF_Secrets::save_option($name, $plain);
$stored = $GLOBALS['options'][$name];
check(str_starts_with($stored, 'v2:') && !str_contains($stored, $plain), 'オプションは v2: 形式で保存し、平文を含まない');
check(OMF_Secrets::option($name) === $plain, 'オプションを取得時に復号する');
OMF_Secrets::save_option($name, '');
check(!isset($GLOBALS['options'][$name]) && OMF_Secrets::option($name) === '', '空文字の保存は削除と同じ');

// 平文の既存値は取得時に暗号化して保存し直す
reset_state();
$GLOBALS['options'][$name] = $plain;
check(OMF_Secrets::option($name) === $plain, '旧来の平文はそのまま使える');
check(str_starts_with($GLOBALS['options'][$name], 'v2:') && !str_contains($GLOBALS['options'][$name], $plain), '旧来の平文を取得時に暗号化して保存し直す');
check(OMF_Secrets::option($name) === $plain, '移行後も同じ値を取得できる');

// 復号できない値
reset_state();
$GLOBALS['options'][$name] = 'v2:' . base64_encode(str_repeat('x', 40));
check(OMF_Secrets::option($name) === '' && OMF_Secrets::option_status($name)['state'] === 'unreadable', '復号できない値は空として扱い、状態を判定できる');
reset_state();
check(OMF_Secrets::option_status($name)['state'] === 'unset', '未保存は未設定');
OMF_Secrets::save_option($name, $plain);
$status = OMF_Secrets::option_status($name);
check($status['state'] === 'set' && $status['last4'] === '7890', '設定済みと末尾4文字を返す');
OMF_Secrets::save_option($name, 'short');
check(OMF_Secrets::option_status($name)['last4'] === '', '短い値は末尾4文字を出さない');

// 保存時のサニタイズ（空で維持・削除・置換・二重暗号化なし）
reset_state();
$enc = OMF_Secrets::sanitize_option($name, "  $plain  ");
check(str_starts_with($enc, 'v2:') && OMF_Secrets::sanitize_option($name, $enc) === $enc, '入力を暗号化し、2回目のサニタイズでは再暗号化しない');
$GLOBALS['options'][$name] = $enc;
check(OMF_Secrets::sanitize_option($name, '') === $enc, '入力が空なら現在の値を維持する');
check(OMF_Secrets::sanitize_option($name, null) === $enc, '項目が送られない場合も現在の値を維持する');
$_POST[$name . '_delete'] = '1';
check(OMF_Secrets::sanitize_option($name, '') === '', '削除チェックで削除する');
$new = OMF_Secrets::sanitize_option($name, 'NEW-SECRET-ABCDEFGH');
$GLOBALS['options'][$name] = $new;
check(OMF_Secrets::option($name) === 'NEW-SECRET-ABCDEFGH', '入力があれば置き換える（削除チェックと同時でも入力を優先）');
$_POST = [];
$GLOBALS['options'][$name] = $plain;
$kept = OMF_Secrets::sanitize_option($name, '');
check(str_starts_with($kept, 'v2:') && !str_contains($kept, $plain), '平文のまま残る値を維持する時も暗号化する');

// フォームのメタ
reset_state();
$mkey = 'cf_omf_slack_webhook_url'; $url = 'https://hooks.example.test/services/T000/B000/XXXXXXXX';
OMF_Secrets::save_meta(10, $mkey, $url, false);
check(str_starts_with($GLOBALS['meta'][10][$mkey], 'v2:') && !str_contains($GLOBALS['meta'][10][$mkey], 'hooks.example') && OMF_Secrets::meta(10, $mkey) === $url, 'メタを暗号化して保存し、取得で復号する');
OMF_Secrets::save_meta(10, $mkey, '', false);
check(OMF_Secrets::meta(10, $mkey) === $url, 'メタは入力が空なら維持する');
OMF_Secrets::save_meta(10, $mkey, 'https://hooks.example.test/NEW', false);
check(OMF_Secrets::meta(10, $mkey) === 'https://hooks.example.test/NEW', 'メタは入力があれば置き換える');
OMF_Secrets::save_meta(10, $mkey, '', true);
check(!isset($GLOBALS['meta'][10][$mkey]), 'メタは削除チェックで削除する');
$GLOBALS['meta'][11][$mkey] = $url;
check(OMF_Secrets::meta(11, $mkey) === $url && str_starts_with($GLOBALS['meta'][11][$mkey], 'v2:'), 'メタの平文は取得時に暗号化して保存し直す');
$GLOBALS['meta'][12][$mkey] = 'v2:' . base64_encode(str_repeat('y', 40));
check(OMF_Secrets::meta(12, $mkey) === '' && OMF_Secrets::meta_status(12, $mkey)['state'] === 'unreadable', 'メタの復号できない値は空＋状態判定');

// フォームの保存処理（Webhook URL）
reset_state();
$admin = new Sharesl\Original\MailForm\OMF_Admin();
$_POST = [$mkey => 'https://hooks.example.test/A'];
call_private($admin, 'save_secret_custom_fields', 10);
check(OMF_Secrets::meta(10, $mkey) === 'https://hooks.example.test/A' && !str_contains($GLOBALS['meta'][10][$mkey], 'hooks.example'), 'フォーム保存で Webhook URL を暗号化して保存する');
$_POST = [$mkey => ''];
call_private($admin, 'save_secret_custom_fields', 10);
check(OMF_Secrets::meta(10, $mkey) === 'https://hooks.example.test/A', 'フォーム保存で入力が空なら Webhook URL を維持する');
$_POST = [$mkey => '', $mkey . '_delete' => '1'];
call_private($admin, 'save_secret_custom_fields', 10);
check(!isset($GLOBALS['meta'][10][$mkey]), 'フォーム保存で削除チェックがあれば Webhook URL を削除する');
$_POST = [$mkey => 'https://hooks.example.test/B', $mkey . '_delete' => '1'];
call_private($admin, 'save_secret_custom_fields', 10);
check(OMF_Secrets::meta(10, $mkey) === 'https://hooks.example.test/B', 'フォーム保存で入力と削除チェックが両方あれば入力で置き換える');

// 画面に値を出さない
reset_state();
$probe = new TemplateProbe();
foreach ([['omf_recaptcha_secret_key', 'omf_recaptcha_settings.php'], ['omf_turnstile_secret_key', 'omf_turnstile_settings.php'], ['omf_google_client_secret', 'omf_google_settings.php']] as [$opt, $tpl]) {
  reset_state();
  $html = $probe->render($tpl);
  check(str_contains($html, '未設定') && !str_contains($html, $opt . '_delete'), "$tpl 未設定の表示と削除チェックなし");
  OMF_Secrets::save_option($opt, $plain);
  $html = $probe->render($tpl);
  check(!str_contains($html, $plain) && !str_contains($html, $GLOBALS['options'][$opt]) && str_contains($html, '設定済み（末尾4文字：7890）') && str_contains($html, 'name="' . $opt . '_delete"') && str_contains($html, 'value=""') && str_contains($html, 'aria-describedby="' . $opt . '_status"'), "$tpl 設定済みでも値を出さない");
  $GLOBALS['options'][$opt] = 'v2:' . base64_encode(str_repeat('z', 40));
  check(str_contains($probe->render($tpl), '読み取れません'), "$tpl 復号できない状態を表示する");
}
reset_state();
OMF_Secrets::save_option('omf_google_client_secret', $plain);
check(str_contains($probe->render('omf_google_settings.php'), 'OAuth接続'), 'Google連携画面は入力欄が空でも認証情報ありと判定する');
OMF_Secrets::save_meta(10, $mkey, $url, false);
ob_start(); OMF_Secret_Field::render_meta_box('Webhook URL', $mkey, OMF_Secrets::meta_status(10, $mkey)); $html = ob_get_clean();
check(!str_contains($html, 'hooks.example') && str_contains($html, '設定済み（末尾4文字：XXXX）') && str_contains($html, 'for="' . $mkey . '"'), 'Slack Webhook URL 欄にも値を出さない');

// 利用側が復号後の値を使う
reset_state();
OMF_Secrets::save_meta(10, $mkey, $url, false);
$GLOBALS['meta'][10]['cf_omf_is_slack_notify'] = '1'; $GLOBALS['meta'][10]['cf_omf_slack_channel'] = 'general';
$form = new WP_Post(); $form->ID = 10;
$req = call_private(new SlackProbe(), 'get_send_slack_params', $form, ['tag_to_text' => ['site_name' => 'S']], ['subject' => '件名', 'message' => '本文'], true);
check(is_array($req) && $req['url'] === $url, 'Slack 通知は復号後の Webhook URL を使う');
reset_state();
$GLOBALS['options']['omf_turnstile_site_key'] = 'site';
OMF_Secrets::save_option('omf_turnstile_secret_key', 'captcha-secret-xyz');
$_POST = ['cf-turnstile-response' => 'token'];
$GLOBALS['captcha_response'] = ['code' => 200, 'body' => json_encode(['success' => true, 'hostname' => 'example.test'])];
$result = (new CaptchaProbe())->verify('omf_turnstile_secret_key', 'cf-turnstile-response');
check(!empty($result['success']) && $GLOBALS['captcha_secret'] === 'captcha-secret-xyz', 'CAPTCHA 検証は復号後のシークレットを送る');

// 書き出し・取り込みの秘密情報リストとの整合
check(OMF_Secrets::META_KEYS === OMF_Port_Schema::secret_meta_keys() && array_diff(OMF_Secrets::OPTIONS, OMF_Port_Schema::secret_options()) === [], '暗号化対象が書き出し・取り込みの秘密情報リストに含まれる');
