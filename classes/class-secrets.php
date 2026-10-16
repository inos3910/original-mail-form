<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 秘密情報（シークレットキー・Webhook URL）の暗号化保存。
 * 読み書きはこのクラスだけで行う。形式は Google トークンと同じ 'v2:'（AES-256-GCM）。
 */
class OMF_Secrets
{
  use OMF_Trait_Cryptor;

  /** 暗号化して保存するオプション。 */
  const OPTIONS = ['omf_recaptcha_secret_key', 'omf_turnstile_secret_key', 'omf_google_client_secret'];

  /** 暗号化して保存するフォームのメタ。 */
  const META_KEYS = ['cf_omf_slack_webhook_url'];

  const PREFIX = 'v2:';

  /** 状態：未設定 / 設定済み / 保存済みだが復号できない。 */
  const STATE_UNSET = 'unset';
  const STATE_SET = 'set';
  const STATE_UNREADABLE = 'unreadable';

  /** 削除チェックボックスの name の末尾。 */
  const DELETE_SUFFIX = '_delete';

  // ---- オプション ----

  /** 復号した値を返す。旧来の平文は暗号化して保存し直す。復号できない時は空文字。 */
  public static function option(string $name): string
  {
    $raw = get_option($name, '');
    if (!is_string($raw) || $raw === '') { return ''; }
    if (self::is_encrypted($raw)) { return self::decrypt($raw, $name); }
    update_option($name, self::encrypt($raw, $name), false);
    return $raw;
  }

  /** 値を暗号化して保存する。空文字なら削除と同じ。 */
  public static function save_option(string $name, string $value): void
  {
    if ($value === '') { delete_option($name); return; }
    update_option($name, self::encrypt($value, $name), false);
  }

  public static function delete_option(string $name): void
  {
    delete_option($name);
  }

  /** 設定画面の保存値を決める（register_setting の sanitize_callback 用）。 */
  public static function sanitize_option(string $name, mixed $value): string
  {
    $input = is_string($value) ? trim($value) : '';
    // add_option 経由で再度サニタイズされた場合（既に暗号化済み）は、そのまま返す。
    if (self::is_readable($input, $name)) { return $input; }
    if ($input !== '') { return self::encrypt($input, $name); }
    if (self::delete_requested($name)) { return ''; }
    $raw = get_option($name, '');
    if (!is_string($raw) || $raw === '') { return ''; }
    return self::is_encrypted($raw) ? $raw : self::encrypt($raw, $name);
  }

  /** register_setting と、autoload を無効にするフックをまとめて登録する。 */
  public static function register_setting(string $group, string $name): void
  {
    register_setting($group, $name, ['sanitize_callback' => static fn($value) => self::sanitize_option($name, $value)]);
    $disable_autoload = static function () use ($name): void { wp_set_option_autoload($name, false); };
    add_action('add_option_' . $name, $disable_autoload);
    add_action('update_option_' . $name, $disable_autoload);
  }

  // ---- フォームのメタ ----

  public static function meta(int $post_id, string $key): string
  {
    $raw = get_post_meta($post_id, $key, true);
    if (!is_string($raw) || $raw === '') { return ''; }
    if (self::is_encrypted($raw)) { return self::decrypt($raw, $key); }
    update_post_meta($post_id, $key, self::encrypt($raw, $key));
    return $raw;
  }

  /** 入力が空かつ削除なしなら現在の値を維持。削除なら消す。入力があれば置き換える。 */
  public static function save_meta(int $post_id, string $key, string $input, bool $delete): void
  {
    if ($input !== '') { update_post_meta($post_id, $key, self::encrypt($input, $key)); return; }
    if ($delete) { delete_post_meta($post_id, $key); }
  }

  // ---- 表示用の状態 ----

  public static function option_status(string $name): array
  {
    return self::status((string) get_option($name, ''), $name, fn() => self::option($name));
  }

  public static function meta_status(int $post_id, string $key): array
  {
    $raw = get_post_meta($post_id, $key, true);
    return self::status(is_string($raw) ? $raw : '', $key, fn() => self::meta($post_id, $key));
  }

  /** @return array{state:string,last4:string} */
  private static function status(string $raw, string $name, callable $read): array
  {
    if ($raw === '') { return ['state' => self::STATE_UNSET, 'last4' => '']; }
    $value = $read();
    if ($value === '') { return ['state' => self::STATE_UNREADABLE, 'last4' => '']; }
    // 短い値は末尾4文字で全体が分かってしまうため出さない。
    return ['state' => self::STATE_SET, 'last4' => strlen($value) >= 8 ? substr($value, -4) : ''];
  }

  // ---- 内部 ----

  public static function delete_field_name(string $name): string
  {
    return $name . self::DELETE_SUFFIX;
  }

  private static function delete_requested(string $name): bool
  {
    return ($_POST[self::delete_field_name($name)] ?? '') === '1';
  }

  private static function is_encrypted(string $raw): bool
  {
    return str_starts_with($raw, self::PREFIX);
  }

  private static function is_readable(string $raw, string $name): bool
  {
    return self::is_encrypted($raw) && self::decrypt($raw, $name) !== '';
  }

  private static function encrypt(string $value, string $name): string
  {
    return (new self())->encrypt_secret($value, $name);
  }

  private static function decrypt(string $raw, string $name): string
  {
    return (new self())->decrypt_secret($raw, $name);
  }
}
