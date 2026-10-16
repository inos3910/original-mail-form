<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポート・インポートの管理画面処理（admin-post）。
 * すべて manage_options 権限・POST・nonce を必須にする。
 */
class OMF_Port_Admin
{
  const PENDING = 'omf_port_pending_';
  const NOTICE = 'omf_port_notice_';
  const RESULT = 'omf_port_result_';

  public function __construct()
  {
    add_action('admin_post_omf_port_export', [self::class, 'handle_export']);
    add_action('admin_post_omf_port_upload', [self::class, 'handle_upload']);
    add_action('admin_post_omf_port_run', [self::class, 'handle_run']);
    add_action('admin_post_omf_port_cancel', [self::class, 'handle_cancel']);
  }

  /**
   * 設定・フォームをJSONでダウンロードする。
   *
   * @return void
   */
  public static function handle_export(): void
  {
    self::guard('omf_port_export');
    $options = OMF_Port_Request::export_options($_POST);
    if (is_wp_error($options)) {
      self::finish('notice', ['error' => true, 'message' => $options->get_error_message()]);
    }
    $json = OMF_Port_Export::to_json(OMF_Port_Export::build($options));
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . OMF_Port_Export::file_name() . '"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
  }

  /**
   * アップロードされたJSONを検証し、確認画面用に一時保存する。
   *
   * @return void
   */
  public static function handle_upload(): void
  {
    self::guard('omf_port_upload');
    $json = self::uploaded_json();
    if (is_wp_error($json)) {
      self::finish('notice', ['error' => true, 'message' => $json->get_error_message()]);
    }
    $payload = OMF_Port_Reader::read($json);
    if (is_wp_error($payload)) {
      self::finish('notice', ['error' => true, 'message' => $payload->get_error_message()]);
    }
    set_transient(self::PENDING . get_current_user_id(), ['token' => bin2hex(random_bytes(16)), 'payload' => $payload], HOUR_IN_SECONDS);
    self::finish('confirm');
  }

  /**
   * 確認済みの内容を取り込み、一時データを削除する。
   *
   * @return void
   */
  public static function handle_run(): void
  {
    self::guard('omf_port_run');
    $pending = self::pending();
    $token = $_POST['omf_port_token'] ?? '';
    if ($pending === null || !is_string($token) || !hash_equals($pending['token'], $token)) {
      self::finish('notice', ['error' => true, 'message' => '取り込み内容の有効期限が切れました。もう一度ファイルをアップロードしてください。']);
    }
    $result = OMF_Port_Apply::run(OMF_Port_Plan::build($pending['payload']));
    delete_transient(self::PENDING . get_current_user_id());
    set_transient(self::RESULT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
    self::finish('result');
  }

  /**
   * 取り込みを取りやめ、一時データを削除する。
   *
   * @return void
   */
  public static function handle_cancel(): void
  {
    self::guard('omf_port_cancel');
    delete_transient(self::PENDING . get_current_user_id());
    self::finish('notice', ['error' => false, 'message' => '取り込みを取りやめました。']);
  }

  /**
   * 利用者ごとの一時保存データ。
   *
   * @return array|null token / payload
   */
  public static function pending(): ?array
  {
    $pending = get_transient(self::PENDING . get_current_user_id());
    return is_array($pending) && isset($pending['token'], $pending['payload']) ? $pending : null;
  }

  /**
   * 表示後に消す一度きりのデータ（notice / result）。
   *
   * @param string $prefix
   * @return array|null
   */
  public static function take(string $prefix): ?array
  {
    $key = $prefix . get_current_user_id();
    $value = get_transient($key);
    delete_transient($key);
    return is_array($value) ? $value : null;
  }

  /**
   * このタブのURL。
   *
   * @param string $step
   * @return string
   */
  public static function tab_url(string $step = ''): string
  {
    $url = admin_url('edit.php?post_type=' . OMF_Config::NAME . '&page=omf_settings&tab=port');
    return $step === '' ? $url : $url . '&step=' . $step;
  }

  /**
   * 権限・メソッド・nonce の確認。
   *
   * @param string $action
   * @return void
   */
  private static function guard(string $action): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('この操作を行う権限がありません。', '', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
      wp_die('画面のボタンから実行してください。', '', ['response' => 405]);
    }
    check_admin_referer($action);
  }

  /**
   * アップロードされたファイルの検査と読み込み。
   *
   * @return string|\WP_Error
   */
  private static function uploaded_json(): string|\WP_Error
  {
    $file = $_FILES['omf_port_file'] ?? null;
    if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      return new \WP_Error('omf_port_upload', 'ファイルをアップロードできませんでした。もう一度お試しください。');
    }
    if (strtolower(pathinfo(sanitize_file_name((string) ($file['name'] ?? '')), PATHINFO_EXTENSION)) !== 'json') {
      return new \WP_Error('omf_port_ext', 'JSONファイル（.json）を選んでください。');
    }
    if ((int) ($file['size'] ?? 0) > OMF_Port_Schema::MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
      return new \WP_Error('omf_port_size', 'ファイルが大きすぎるか、アップロードが不正です（上限2MB）。');
    }
    $json = file_get_contents($file['tmp_name']);
    return $json === false ? new \WP_Error('omf_port_read', 'ファイルを読み込めませんでした。') : $json;
  }

  /**
   * 結果を一時保存して画面へ戻る。
   *
   * @param string $step notice / confirm / result
   * @param array $notice
   * @return void
   */
  private static function finish(string $step, array $notice = []): void
  {
    if ($step === 'notice') {
      set_transient(self::NOTICE . get_current_user_id(), $notice, 120);
    }
    wp_safe_redirect(self::tab_url($step === 'notice' ? '' : $step));
    exit;
  }
}
