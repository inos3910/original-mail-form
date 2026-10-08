<?php
require __DIR__ . '/bootstrap.php';

class TurnstileProbe
{
  use Sharesl\Original\MailForm\OMF_Trait_Captcha;

  public function verify(string $option = 'omf_turnstile_secret_key'): bool
  {
    $response = $this->verify_captcha_response('https://challenges.cloudflare.com/turnstile/v0/siteverify', $option, 'cf-turnstile-response');
    return !empty($response['success']);
  }
}

$probe = new TurnstileProbe();
$site_key = '1x00000000000000000000AA';
$secret_key = '1x0000000000000000000000000000000AA';
$reset = static function (array $result = ['success' => true, 'hostname' => 'example.com']) use ($site_key, $secret_key): void {
  $GLOBALS['options'] = ['omf_turnstile_site_key' => $site_key, 'omf_turnstile_secret_key' => $secret_key];
  $_POST = ['cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX'];
  $GLOBALS['captcha_response'] = ['code' => 200, 'body' => json_encode($result)];
};

$reset();
$calls = $GLOBALS['captcha_calls'] ?? 0;
check($probe->verify() && $GLOBALS['captcha_calls'] === $calls + 1, '公式テストキーもAPI検証を通して認証成功を判定する');
$reset(['success' => true, 'hostname' => 'localhost']);
check($probe->verify(), '公式ドキュメントのテスト応答を受け付ける');
$reset(['success' => false, 'error-codes' => ['invalid-input-response']]);
check(!$probe->verify(), 'テスト用の設定でもAPIの認証失敗を受け付けない');
$reset(); $GLOBALS['options']['omf_turnstile_secret_key'] = 'production-secret';
check(!$probe->verify(), 'サイトキーだけがテスト用ならホスト名を検証する');
$reset(); $GLOBALS['options']['omf_turnstile_site_key'] = 'production-site';
check(!$probe->verify(), '秘密キーだけがテスト用ならホスト名を検証する');
$reset(); $_POST['cf-turnstile-response'] = 'real-token';
check(!$probe->verify(), 'テスト用のキーでも通常トークンのホスト名を検証する');
$reset(['success' => true, 'hostname' => 'example.test']);
$GLOBALS['options'] = ['omf_turnstile_site_key' => 'production-site', 'omf_turnstile_secret_key' => 'production-secret'];
$_POST['cf-turnstile-response'] = 'real-token';
check($probe->verify(), '通常キーはサイトと一致するホスト名で通過する');
$GLOBALS['captcha_response']['body'] = json_encode(['success' => true, 'hostname' => 'EXAMPLE.TEST']);
check($probe->verify(), '通常のホスト名比較は大文字小文字を区別しない');
$GLOBALS['captcha_response']['body'] = json_encode(['success' => true, 'hostname' => 'different.test']);
check(!$probe->verify(), '通常キーの別ドメイン応答は拒否する');
$GLOBALS['captcha_response']['body'] = json_encode(['success' => true]);
check(!$probe->verify(), '通常キーのホスト名欠落は拒否する');
$GLOBALS['captcha_response']['body'] = json_encode(['success' => true, 'hostname' => ['example.test']]);
check(!$probe->verify(), '不正な型のホスト名は拒否する');
$reset(); $GLOBALS['options']['omf_recaptcha_secret_key'] = $secret_key;
check(!$probe->verify('omf_recaptcha_secret_key'), 'reCAPTCHAへテスト用ホスト名の例外を適用しない');
$reset(); $GLOBALS['captcha_response']['code'] = 500;
check(!$probe->verify(), 'テスト用のキーでもHTTPエラーを拒否する');
$reset(); $GLOBALS['captcha_response']['body'] = 'broken-json';
check(!$probe->verify(), 'テスト用のキーでも不正な応答を拒否する');
$reset(); $GLOBALS['captcha_response'] = new WP_Error('network');
check(!$probe->verify(), 'テスト用のキーでも通信エラーを拒否する');
$reset(); $_POST = [];
check(!$probe->verify(), 'テスト用のキーでもトークン未送信を拒否する');
$reset(['success' => false, 'error-codes' => ['invalid-input-response']]);
$GLOBALS['options']['omf_turnstile_secret_key'] = '2x0000000000000000000000000000000AA';
check(!$probe->verify(), '失敗用の公式秘密キーでは失敗を維持する');
$reset(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
$GLOBALS['options']['omf_turnstile_secret_key'] = '3x0000000000000000000000000000000AA';
check(!$probe->verify(), '使用済みトークン用の公式秘密キーでは失敗を維持する');
