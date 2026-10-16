<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 操作結果を画面単位で一度だけ表示する。 */
class OMF_Admin_Notices
{
  const GROUPS = ['omf-settings-group', 'recaptcha-settings-group', 'turnstile-settings-group', 'omf-google-settings-group'];
  private array $options = [];
  private array $meta = [];

  public function __construct()
  {
    add_filter('pre_update_option', [$this, 'capture_option'], PHP_INT_MAX, 3);
    add_filter('update_post_metadata', [$this, 'capture_meta'], PHP_INT_MAX, 5);
    add_filter('wp_redirect', [$this, 'settings_result'], 10, 2);
    add_filter('redirect_post_location', [$this, 'editor_result'], 20, 2);
    add_action('admin_notices', [$this, 'editor_notice']);
  }

  private static function key(string $screen): string
  {
    return 'omf_action_notice_' . get_current_user_id() . '_' . $screen;
  }

  public static function remember(string $screen, string $message, bool $error = false): void
  {
    set_transient(self::key($screen), ['message' => $message, 'error' => $error], 120);
  }

  public static function show(string $screen): bool
  {
    $notice = get_transient(self::key($screen));
    if (!is_array($notice)) { return false; }
    delete_transient(self::key($screen));
    self::render($notice['message'], !empty($notice['error']));
    return true;
  }

  public static function render(string $message, bool $error = false): void
  {
    echo '<div class="notice ' . ($error ? 'notice-error' : 'notice-success') . ' is-dismissible" data-omf-action-notice="true" role="' . ($error ? 'alert' : 'status') . '"><p>' . esc_html($message) . '</p></div>';
  }

  public function capture_option($value, $option, $old_value)
  {
    // サニタイズ後の値を記録。新規オプションと変更なしの保存にも対応する。
    if (in_array($_POST['option_page'] ?? '', self::GROUPS, true) && str_starts_with($option, 'omf_')) {
      $this->options[$option] = $value;
    }
    return $value;
  }

  private static function same($actual, $expected): bool
  {
    if (is_scalar($expected) || $expected === null) {
      return (is_scalar($actual) || $actual === null) && (string) $actual === (string) $expected;
    }
    return $actual === $expected;
  }

  public function settings_result(string $location, int $status): string
  {
    if (!in_array($_POST['option_page'] ?? '', self::GROUPS, true) || !str_contains($location, 'settings-updated=')) { return $location; }
    $errors = array_filter(get_settings_errors(), static fn($notice) => ($notice['type'] ?? '') === 'error');
    foreach ($this->options as $option => $value) {
      if (!self::same(get_option($option), $value)) { $errors[] = ['message' => '設定を保存できませんでした。時間をおいて再度お試しください。']; break; }
    }
    $message = $errors ? implode(' ', array_unique(array_column($errors, 'message'))) : '設定を保存しました。';
    self::remember('settings', $message, (bool) $errors);
    // WordPressの汎用成功通知との重複を防ぐ。
    delete_transient('settings_errors');
    return remove_query_arg('settings-updated', $location);
  }

  public function capture_meta($check, $id, $key, $value, $previous)
  {
    if (isset($_POST['omf_meta_nonce']) && str_starts_with($key, 'cf_omf_')) {
      $this->meta[$id][$key] = $value;
    }
    return $check;
  }

  public function editor_result(string $location, int $id): string
  {
    foreach ($this->meta[$id] ?? [] as $key => $value) {
      if (!self::same(get_post_meta($id, $key, true), $value)) {
        self::remember('editor_' . $id, 'メールフォームの設定を保存できませんでした。保存内容を確認して再度お試しください。', true);
        return remove_query_arg('message', $location);
      }
    }
    // 成功時はWordPress標準の更新・公開・下書き保存通知を使用する。
    return $location;
  }

  public function editor_notice(): void
  {
    $screen = get_current_screen();
    if ($screen && $screen->base === 'post') {
      self::show('editor_' . absint($_GET['post'] ?? 0));
    }
  }
}
