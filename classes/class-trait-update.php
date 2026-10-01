<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 原型のmaster更新操作を、権限・nonce確認とWordPressの展開処理で維持する。 */
trait OMF_Trait_Update
{
  private function update_plugin_from_github(): void
  {
    if (!current_user_can('update_plugins')) { wp_die('プラグインを更新する権限がありません。'); }
    check_admin_referer('omf_update_plugin');
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $plugin = plugin_basename(dirname(__DIR__) . '/original-mail-form.php');
    $provide_package = static function ($value) use ($plugin) {
      $value = is_object($value) ? clone $value : new \stdClass();
      $value->response = (array) ($value->response ?? []);
      $headers = get_file_data(dirname(__DIR__) . '/original-mail-form.php', ['version' => 'Version', 'requires' => 'Requires at least', 'requires_php' => 'Requires PHP']);
      $value->response[$plugin] = (object) ['slug' => 'original-mail-form', 'plugin' => $plugin, 'new_version' => $headers['version'], 'requires' => $headers['requires'], 'requires_php' => $headers['requires_php'], 'package' => 'https://github.com/inos3910/original-mail-form/archive/master.zip'];
      return $value;
    };
    $normalize_source = static function ($source, $remote_source, $upgrader, $extra) use ($plugin) {
      if (is_wp_error($source) || ($extra['plugin'] ?? '') !== $plugin) { return $source; }
      global $wp_filesystem;
      $header = $wp_filesystem->get_contents(trailingslashit($source) . 'original-mail-form.php');
      if (!is_string($header) || !preg_match('/Plugin Name:\s*Original Mail Form\s*$/m', $header) || !$wp_filesystem->is_file(trailingslashit($source) . 'autoload.php')) {
        return new \WP_Error('omf_update_package', 'Original Mail Formの更新ファイルを確認できません。');
      }
      // GitHubの「original-mail-form-master」展開名で、有効なプラグインのパスを変えない。
      $destination = trailingslashit($remote_source) . dirname($plugin);
      if (untrailingslashit($source) === untrailingslashit($destination)) { return $source; }
      return $wp_filesystem->move($source, $destination) ? trailingslashit($destination) : new \WP_Error('omf_update_directory', '更新ファイルを準備できません。');
    };
    add_filter('site_transient_update_plugins', $provide_package);
    add_filter('upgrader_source_selection', $normalize_source, 10, 4);
    try {
      $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
      // 一括更新の経路は、有効なプラグインを無効化せずメンテナンス中に交換する。
      $results = $upgrader->bulk_upgrade([$plugin]);
      $result = is_array($results) ? ($results[$plugin] ?? false) : $results;
      // WP_Upgraderは展開成功時に結果配列、更新不要時にtrueを返す。
      $success = $result === true || (is_array($result) && ($result['destination_name'] ?? '') === dirname($plugin));
      $message = $success ? 'プラグインが更新されました。' : '更新できませんでした。WordPressのプラグイン画面から再度お試しください。';
      echo '<div class="notice ' . ($success ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($message) . '</p></div>';
    } finally {
      remove_filter('site_transient_update_plugins', $provide_package);
      remove_filter('upgrader_source_selection', $normalize_source, 10);
    }
  }
}
