<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 添付は公開メディアに登録せず、公開領域外に一時保管する。 */
class OMF_Uploads
{
  public function __construct()
  {
    add_action('delete_omf_old_temp_files', [self::class, 'cleanup']);
  }

  private static function directory(): string
  {
    $base = defined('OMF_PRIVATE_UPLOAD_DIR') ? OMF_PRIVATE_UPLOAD_DIR : get_temp_dir() . 'omf-' . substr(hash('sha256', ABSPATH), 0, 16);
    if (!is_dir($base) && !wp_mkdir_p($base)) { throw new \RuntimeException('添付の一時保存先を作成できません。'); }
    $base = realpath($base);
    foreach ([ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH] as $public) {
      $public = realpath($public);
      if ($public && ($base === $public || str_starts_with($base . '/', rtrim($public, '/') . '/'))) {
        throw new \RuntimeException('添付の保存先には公開領域外のディレクトリを指定してください。');
      }
    }
    chmod($base, 0700);
    return $base;
  }

  public static function save(string $source, string $name): string
  {
    $base = self::directory();
    self::cleanup();
    if (count(glob($base . '/*') ?: []) >= (int) apply_filters('omf_max_pending_uploads', 100)) {
      throw new \RuntimeException('一時保存件数の上限です。しばらく待ってからお試しください。');
    }
    $id = bin2hex(random_bytes(20));
    $dir = $base . '/' . $id;
    if (!mkdir($dir, 0700) || !move_uploaded_file($source, $dir . '/' . sanitize_file_name($name))) {
      @rmdir($dir);
      throw new \RuntimeException('添付の一時保存に失敗しました。');
    }
    chmod($dir . '/' . sanitize_file_name($name), 0600);
    return $id;
  }

  public static function path(string $id): string
  {
    if (!preg_match('/^[a-f0-9]{40}$/D', $id)) { return ''; }
    $files = glob(self::directory() . '/' . $id . '/*') ?: [];
    return count($files) === 1 && is_file($files[0]) && !is_link($files[0]) ? $files[0] : '';
  }

  public static function remove(string $id): void
  {
    $path = self::path($id);
    if ($path !== '') { unlink($path); rmdir(dirname($path)); }
  }

  public static function cleanup(): void
  {
    try { $base = self::directory(); } catch (\Throwable $e) { return; }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
      if (!preg_match('/^[a-f0-9]{40}$/D', basename($dir)) || is_link($dir) || filemtime($dir) > time() - HOUR_IN_SECONDS) { continue; }
      if (get_option('omf_delivery_schema') === '1' && OMF_Delivery_Store::protected_upload(basename($dir))) { continue; }
      foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) { unlink($file); }
      }
      rmdir($dir);
    }
  }
}
