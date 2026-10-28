<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 管理者だけが添付保存先を確認・変更する。変更時にフォルダは作らない。 */
class OMF_Upload_Settings
{
  public function __construct()
  {
    add_action('admin_post_omf_save_upload_directory', [self::class, 'handle']);
    add_action('admin_enqueue_scripts', static function () {
      if (($_GET['page'] ?? '') === 'omf_settings' && ($_GET['tab'] ?? 'general') === 'general') {
        wp_enqueue_style('omf-upload-settings', plugins_url('assets/upload-settings.css', __DIR__), [], filemtime(dirname(__DIR__) . '/assets/upload-settings.css'));
      }
    });
  }

  public static function handle(): void
  {
    if (!current_user_can('manage_options')) { wp_die('保存先を変更する権限がありません。', '', ['response' => 403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('保存ボタンから実行してください。', '', ['response' => 405]); }
    check_admin_referer('omf_save_upload_directory');
    $value = $_POST['omf_upload_directory'] ?? null;
    $result = is_string($value) ? self::save(wp_unslash($value)) : new \WP_Error('path', '保存先を文字列で指定してください。');
    OMF_Admin_Notices::remember('settings', is_wp_error($result) ? $result->get_error_message() : $result, is_wp_error($result));
    wp_safe_redirect(admin_url('edit.php?post_type=' . OMF_Config::NAME . '&page=omf_settings&tab=general'));
    exit;
  }

  private static function assert_empty(string $path): void
  {
    if (is_link($path)) { throw new \RuntimeException('シンボリックリンクの保存先は切り替えできません。管理者が実体を確認してください。'); }
    if (!file_exists($path)) { return; }
    $entries = is_dir($path) && is_readable($path) ? scandir($path) : false;
    if ($entries === false || array_diff($entries, ['.', '..', '.htaccess', 'web.config', 'README.txt']) !== []) {
      throw new \RuntimeException('保存先に添付または別のファイルが残っています。添付がある間は切り替えできません。ファイルは自動移動・削除しません。');
    }
  }

  public static function save(string $value): string|\WP_Error
  {
    if (defined('OMF_PRIVATE_UPLOAD_DIR')) { return new \WP_Error('fixed', '保存先はサーバー設定で固定されています。管理画面から変更できません。'); }
    try {
      return OMF_Upload_Lock::run(static function () use ($value) {
        $value = trim($value);
        $target = OMF_Upload_Directory::validate($value === '' ? OMF_Upload_Directory::suggested() : $value);
        if (!is_writable(is_dir($target) ? $target : dirname($target))) { throw new \RuntimeException('保存先に書き込めません。フォルダの権限を確認してください。'); }
        try { $current = OMF_Upload_Directory::selected(); }
        catch (\RuntimeException $e) {
          // 自動選定できない環境でも、初回の手動指定を受け付ける。
          if (get_option(OMF_Upload_Directory::OPTION, '') !== '') { throw $e; }
          $current = '';
        }
        if ((realpath($current) ?: $current) !== $target) {
          self::assert_empty($current);
          self::assert_empty($target);
        }
        update_option(OMF_Upload_Directory::OPTION, $target, false);
        if (get_option(OMF_Upload_Directory::OPTION) !== $target) { throw new \RuntimeException('保存先の設定を保存できませんでした。'); }
        return '添付ファイルの保存先を設定しました。フォルダは添付の初回保存時に作成します。既存フォルダは移動・削除していません。';
      });
    } catch (\RuntimeException $e) { return new \WP_Error('directory', $e->getMessage()); }
  }
}
