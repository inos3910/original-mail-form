<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** CAPTCHAの通過記録をフォーム・入力内容・ワンタイムトークンに結び付ける。 */
trait OMF_Trait_Captcha
{
  protected array $captcha_input = [];

  private function captcha_fingerprint(array $data, int $form_id): string
  {
    $files = $this->get_file_field_targets($form_id);
    // 保存前後で変化するファイル情報は除外。ファイル自体は別途検証する。
    $data = array_diff_key($data, array_flip(array_merge($files, ['mail_id', 'omf_reply_mail_sended'])));
    ksort($data);
    return hash('sha256', serialize([$data, $_SESSION[OMF_Embed_Context::token_key()] ?? '']));
  }

  private function validate_captcha(array $data, \WP_Post $form): array
  {
    $providers = [];
    if (get_post_meta($form->ID, 'cf_omf_recaptcha', true) === '1') { $providers[] = 'recaptcha'; }
    if (get_post_meta($form->ID, 'cf_omf_turnstile', true) === '1') { $providers[] = 'turnstile'; }
    if ($providers === []) { return []; }
    $key = OMF_Embed_Context::prefix($form->post_name) . '_captcha';
    $fingerprint = $this->captcha_fingerprint($data, $form->ID);
    $record = $_SESSION[$key] ?? [];
    if (($record['expires'] ?? 0) > time() && ($record['providers'] ?? []) === $providers && hash_equals((string) ($record['fingerprint'] ?? ''), $fingerprint)) {
      return [];
    }
    unset($_SESSION[$key]);
    foreach ($providers as $provider) {
      $valid = $provider === 'recaptcha' ? $this->verify_google_recaptcha() : $this->verify_cloudflare_turnstile();
      if (!$valid) {
        return [$provider => ['フォーム認証の有効期限が切れたか、認証に失敗しました。入力画面でもう一度お試しください。']];
      }
    }
    $_SESSION[$key] = ['fingerprint' => $fingerprint, 'providers' => $providers, 'expires' => time() + 15 * MINUTE_IN_SECONDS];
    return [];
  }

  private function verify_captcha_response(string $url, string $secret_option, string $field): array
  {
    $input = $this->captcha_input ?: wp_unslash($_POST);
    $token = $input[$field] ?? '';
    $secret = (string) get_option($secret_option, '');
    if (!is_string($token) || $token === '' || $secret === '') { return []; }
    $response = wp_remote_post($url, ['timeout' => 10, 'body' => ['secret' => $secret, 'response' => $token]]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return []; }
    $result = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($result)) { return []; }
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    // 公式テスト応答のホスト名は実サイトと一致しない。認証成功したダミー応答だけを区別する。
    $test_response = ($result['success'] ?? false) === true && $this->is_turnstile_test_response($secret_option, $secret, $token);
    if (!$test_response && (empty($result['hostname']) || !is_string($result['hostname']) || strcasecmp($result['hostname'], (string) $host) !== 0)) { return []; }
    return $result;
  }

  /** 公式のサイトキー・成功用秘密キー・ダミートークンが揃った場合だけ、テスト応答として扱う。 */
  private function is_turnstile_test_response(string $secret_option, string $secret, string $token): bool
  {
    if ($secret_option !== 'omf_turnstile_secret_key' || $secret !== '1x0000000000000000000000000000000AA' || $token !== 'XXXX.DUMMY.TOKEN.XXXX') {
      return false;
    }
    return in_array((string) get_option('omf_turnstile_site_key', ''), [
      '1x00000000000000000000AA',
      '1x00000000000000000000BB',
      '2x00000000000000000000AB',
      '2x00000000000000000000BB',
      '3x00000000000000000000FF',
    ], true);
  }
}
