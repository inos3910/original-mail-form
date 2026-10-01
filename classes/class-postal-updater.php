<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 管理者による郵便番号データ更新。公開先は完全な生成後に切り替える。 */
class OMF_Postal_Updater
{
  const SOURCE = 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip';
  const OPTION = 'omf_postal_dataset';
  public function __construct()
  {
    add_action('admin_post_omf_update_postal', [self::class, 'handle']);
    add_action('admin_enqueue_scripts', static function () {
      if (($_GET['page'] ?? '') === 'omf_settings') {
        wp_enqueue_style('omf-postal-settings', plugins_url('assets/postal-settings.css', __DIR__), [], filemtime(dirname(__DIR__) . '/assets/postal-settings.css'));
        wp_enqueue_script('omf-postal-settings', plugins_url('assets/postal-settings.js', __DIR__), [], filemtime(dirname(__DIR__) . '/assets/postal-settings.js'), true);
      }
    });
  }

  public static function current(): array
  {
    $saved = get_option(self::OPTION, []);
    $uploads = wp_upload_dir();
    if (is_array($saved) && preg_match('/^[a-f0-9]{32}$/D', $saved['generation'] ?? '') && is_file($uploads['basedir'] . '/omf-postal/' . $saved['generation'] . '/manifest.json')) {
      return $saved + ['base_url' => trailingslashit($uploads['baseurl']) . 'omf-postal/' . $saved['generation'] . '/'];
    }
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/assets/postal/manifest.json'), true);
    return (is_array($manifest) ? $manifest : []) + ['base_url' => plugins_url('assets/postal/', __DIR__)];
  }

  public static function handle(): void
  {
    if (!current_user_can('manage_options')) { wp_die('更新する権限がありません。', '', ['response' => 403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('更新ボタンから実行してください。', '', ['response' => 405]); }
    check_admin_referer('omf_update_postal');
    $result = self::update();
    set_transient('omf_postal_notice_' . get_current_user_id(), ['error' => is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : $result], 120);
    wp_safe_redirect(admin_url('edit.php?post_type=' . OMF_Config::NAME . '&page=omf_settings&tab=general'));
    exit;
  }

  public static function update(): string|\WP_Error
  {
    if (!class_exists('ZipArchive')) { return new \WP_Error('zip', 'このサーバーではZIPを展開できません。PHPのZipArchiveを有効にしてください。'); }
    $lock = time();
    if (!add_option('omf_postal_update_lock', $lock, '', false)) {
      // 異常終了したロックだけを比較付きで解除する。
      global $wpdb;
      $old = (int) get_option('omf_postal_update_lock');
      if ($old < time() - 600) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'omf_postal_update_lock', (string) $old));
        wp_cache_delete('omf_postal_update_lock', 'options');
      }
      if ($old >= time() - 600 || !add_option('omf_postal_update_lock', $lock, '', false)) { return new \WP_Error('busy', '別の更新を実行中です。少し待ってからお試しください。'); }
    }
    $temporary = ''; $directory = ''; $published = false;
    try {
      $uploads = wp_upload_dir();
      if ($uploads['error']) { throw new \RuntimeException('住所データの保存先を作成できません。アップロード先の書き込み権限を確認してください。'); }
      $temporary = wp_tempnam('omf-postal.zip');
      if (!$temporary) { throw new \RuntimeException('一時ファイルを作成できません。'); }
      $response = wp_safe_remote_get(self::SOURCE, ['timeout' => 30, 'redirection' => 0, 'stream' => true, 'filename' => $temporary, 'limit_response_size' => 6 * MB_IN_BYTES]);
      if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('日本郵便のデータを取得できませんでした。時間をおいて再度お試しください。'); }
      $hash = hash_file('sha256', $temporary);
      $current = self::current();
      if ($hash === ($current['sha256'] ?? '')) {
        update_option('omf_postal_checked_at', current_time('mysql'), false);
        return '確認しました。郵便番号データはすでに最新です。';
      }
      $generation = str_replace('-', '', wp_generate_uuid4());
      $directory = $uploads['basedir'] . '/omf-postal/' . $generation;
      if (!wp_mkdir_p($directory)) { throw new \RuntimeException('住所データの保存先を作成できません。アップロード先の書き込み権限を確認してください。'); }
      $counts = OMF_Postal_Data::build($temporary, $directory);
      $manifest = $counts + ['generation' => $generation, 'source' => self::SOURCE, 'sha256' => $hash, 'downloaded_at' => current_time('mysql')];
      $json = wp_json_encode($manifest, JSON_UNESCAPED_UNICODE);
      if (!$json || @file_put_contents($directory . '/manifest.json', $json) !== strlen($json)) { throw new \RuntimeException('住所データの記録を保存できません。'); }
      if (!update_option(self::OPTION, $manifest, false)) { throw new \RuntimeException('住所データの切り替えに失敗しました。'); }
      $published = true;
      update_option('omf_postal_checked_at', current_time('mysql'), false);
      self::cleanup($uploads['basedir'] . '/omf-postal', $generation);
      return '郵便番号データを更新しました。';
    } catch (\Throwable $error) {
      return new \WP_Error('update', $error instanceof \RuntimeException ? $error->getMessage() . ' 現在のデータは引き続き利用できます。' : '住所データの処理に失敗しました。現在のデータは引き続き利用できます。');
    } finally {
      if ($temporary && is_file($temporary)) { @unlink($temporary); }
      if (!$published && $directory) { self::remove_directory($directory); }
      delete_option('omf_postal_update_lock');
    }
  }

  private static function cleanup(string $root, string $current): void
  {
    foreach (glob($root . '/*', GLOB_ONLYDIR) as $directory) {
      if (basename($directory) !== $current && preg_match('/^[a-f0-9]{32}$/D', basename($directory)) && filemtime($directory) < time() - 7 * DAY_IN_SECONDS) { self::remove_directory($directory); }
    }
  }

  private static function remove_directory(string $directory): void
  {
    foreach (glob($directory . '/*') as $file) { if (is_file($file) && !is_link($file)) { @unlink($file); } }
    if (is_dir($directory)) { @rmdir($directory); }
  }
}
