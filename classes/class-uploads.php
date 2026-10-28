<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 添付実体を公開領域外に保管する。旧方式のメディア情報は別途維持する。 */
class OMF_Uploads
{
  public function __construct()
  {
    add_action('delete_omf_old_temp_files', [self::class, 'cleanup']);
  }

  public static function save(string $source, string $name): string
  {
    return OMF_Upload_Lock::run(static fn() => self::store($source, $name, true));
  }

  private static function store(string $source, string $name, bool $uploaded): string
  {
    $base = OMF_Upload_Directory::resolve(true);
    self::cleanup();
    $pending = array_filter(glob($base . '/*', GLOB_ONLYDIR) ?: [], static fn($dir) => !OMF_Legacy_Uploads::retained(basename($dir)));
    if (count($pending) >= (int) apply_filters('omf_max_pending_uploads', 100)) {
      throw new \RuntimeException('一時保存件数の上限です。しばらく待ってからお試しください。');
    }
    $id = bin2hex(random_bytes(20));
    $dir = $base . '/' . $id;
    $target = $dir . '/' . sanitize_file_name($name);
    if (!mkdir($dir, 0700) || !($uploaded ? move_uploaded_file($source, $target) : copy($source, $target))) {
      @rmdir($dir);
      throw new \RuntimeException('添付の一時保存に失敗しました。');
    }
    chmod($target, 0600);
    return $id;
  }

  public static function path(string $id): string
  {
    if (!preg_match('/^[a-f0-9]{40}$/D', $id)) { return ''; }
    try { $base = OMF_Upload_Directory::resolve(); } catch (\RuntimeException $e) { return ''; }
    if ($base === '') { return ''; }
    $dir = $base . '/' . $id;
    if (is_link($dir)) { return ''; }
    $files = glob($dir . '/*') ?: [];
    return count($files) === 1 && is_file($files[0]) && !is_link($files[0]) ? $files[0] : '';
  }

  public static function remove(string $id): void
  {
    if (OMF_Legacy_Uploads::retained($id)) { return; }
    $path = self::path($id);
    if ($path !== '') { unlink($path); rmdir(dirname($path)); }
    OMF_Legacy_Uploads::discard($id);
  }

  /** 旧版の一時メディアを公開領域外へコピーする。呼び出し側で所有・実体を検証する。 */
  public static function import(string $source, string $name): string
  {
    return OMF_Upload_Lock::run(static fn() => self::store($source, $name, false));
  }

  public static function cleanup(): void
  {
    try { $base = OMF_Upload_Directory::resolve(); } catch (\Throwable $e) { return; }
    if ($base === '') { return; }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
      if (!preg_match('/^[a-f0-9]{40}$/D', basename($dir)) || is_link($dir) || filemtime($dir) > time() - HOUR_IN_SECONDS) { continue; }
      if (OMF_Legacy_Uploads::retained(basename($dir))) { continue; }
      if (get_option('omf_delivery_schema') === '1' && OMF_Delivery_Store::protected_upload(basename($dir))) { continue; }
      foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) { unlink($file); }
      }
      rmdir($dir);
      OMF_Legacy_Uploads::discard(basename($dir));
    }
  }
}
