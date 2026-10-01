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
    if (empty($result['hostname']) || strcasecmp($result['hostname'], (string) $host) !== 0) { return []; }
    return $result;
  }
}
