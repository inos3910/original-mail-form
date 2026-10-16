<?php
namespace Sharesl\Original\MailForm {
  // 外部接続・メール送信・DBを使わず、操作結果の分岐だけを検証する。
  class OMF_Delivery_Runner {
    public static bool $ok = true;
    public static function diagnose(): bool { return self::$ok; }
    public static function ready(): bool { return self::$ok; }
    public static function schedule(): void {}
    public static function parallel(int $id): void {}
  }
  class OMF_Delivery_Store {
    public static bool $retry = true;
    public static array $rows = [];
    public static function rows(...$args): array { return self::$rows; }
    public static function get(int $id): ?array { return self::$rows[$id] ?? null; }
    public static function retry(...$args): bool { return self::$retry; }
  }
  class OMF_Delivery { public static function run(string $mode): void {} }
}
namespace {
  require __DIR__ . '/bootstrap.php';
  use Sharesl\Original\MailForm\OMF_Admin_Notices as Notices;
  use Sharesl\Original\MailForm\OMF_Delivery_Admin as Delivery;
  use Sharesl\Original\MailForm\OMF_Delivery_Runner as Runner;
  use Sharesl\Original\MailForm\OMF_Delivery_Store as Store;
  function get_settings_errors(): array { return $GLOBALS['settings_errors'] ?? []; }
  function remove_query_arg($key, $url): string { return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '$1', $url); }
  $location = '/edit.php?page=omf_settings&tab=google&settings-updated=true';
  foreach (Notices::GROUPS as $group) {
    $notices = new Notices(); $_POST = ['option_page' => $group];
    $notices->capture_option('保存値', 'omf_notice_test', '');
    $GLOBALS['options']['omf_notice_test'] = '保存値';
    $redirect = $notices->settings_result($location, 302);
    ob_start(); Notices::show('settings'); $html = ob_get_clean();
    check(substr_count($html, 'notice-success') === 1 && str_contains($html, '設定を保存しました。') && !str_contains($redirect, 'settings-updated'), $group . 'の保存・変更なしの保存は成功通知が一件');
    ob_start(); Notices::show('settings'); check(ob_get_clean() === '', '再表示で同じ通知を出さない');
  }
  $notices->capture_option('未保存値', 'omf_notice_test', '保存値');
  $notices->settings_result($location, 302);
  ob_start(); Notices::show('settings'); $html = ob_get_clean();
  check(str_contains($html, 'notice-error') && !str_contains($html, 'notice-success'), '保存値不一致は失敗だけを表示');
  $GLOBALS['settings_errors'] = [['message'=>'入力を確認してください。', 'type'=>'error'], ['message'=>'入力を確認してください。', 'type'=>'error'], ['message'=>'保存しました。', 'type'=>'success']];
  $notices->settings_result($location, 302);
  ob_start(); Notices::show('settings'); $html = ob_get_clean();
  check(substr_count($html, 'notice-error') === 1 && substr_count($html, '入力を確認してください。') === 1 && !str_contains($html, '保存しました。'), '複数の設定エラーを一件へ集約して成功通知を除く');
  $_POST = ['omf_meta_nonce'=>'valid'];
  $notices->capture_meta(null, 10, 'cf_omf_reply_mail', '保存されない本文', '');
  $redirect = $notices->editor_result('/post.php?post=10&message=1', 10);
  ob_start(); Notices::show('editor_10'); $html = ob_get_clean();
  check(str_contains($html, 'notice-error') && !str_contains($redirect, 'message='), 'フォーム設定の保存失敗では標準の更新成功通知を出さない');
  $GLOBALS['meta'][10]['cf_omf_reply_mail'] = '保存されない本文';
  check($notices->editor_result('/post.php?post=10&message=1', 10) === '/post.php?post=10&message=1', '保存成功時はWordPressの標準通知だけを維持');
  Notices::remember('settings', '<script>危険</script>');
  ob_start(); Notices::show('settings'); $html = ob_get_clean();
  check(!str_contains($html, '<script>') && str_contains($html, 'role="status"'), '通知をエスケープし支援技術へ結果を伝える');
  check(!Delivery::perform('diagnose')['error'], '環境確認成功は成功通知');
  Runner::$ok = false;
  check(Delivery::perform('diagnose')['error'], '環境確認失敗は失敗通知');
  Store::$retry = false;
  check(Delivery::perform('retry', 1, 'admin')['error'], '再試行の受付失敗を成功にしない');
  Store::$retry = true; Store::$rows = [1=>['id'=>1, 'mode'=>'wp_async', 'reply'=>'sent', 'admin'=>'pending', 'effects'=>'pending']];
  check(!Delivery::perform('retry', 1, 'admin')['error'], '非同期の再試行は送信完了ではなく受付成功を通知');
  Store::$rows[1]['admin'] = 'failed';
  check(Delivery::perform('run')['error'], '送信待ち処理に配送失敗があれば失敗通知');
  Store::$rows = [];
  check(!Delivery::perform('run')['error'], '処理対象なしは確認完了を通知');
  check(Delivery::perform('invalid')['error'], '未知の操作を成功にしない');
  echo "管理画面通知テスト完了\n";
}
