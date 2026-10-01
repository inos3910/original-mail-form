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

  private static function directory(): string
  {
    $name = '/omf-' . substr(hash('sha256', ABSPATH), 0, 16);
    $saved = get_option('omf_private_upload_directory', '');
    $temporary = array_map(static fn($dir) => rtrim($dir, '/\\') . $name, array_filter([get_temp_dir(), ini_get('upload_tmp_dir'), sys_get_temp_dir()]));
    // 既存の一時添付を引き継ぎ、新規設置ではWordPressの隣の非公開領域を優先する。
    $existing = array_filter($temporary, static fn($dir) => is_dir($dir) && (glob($dir . '/*', GLOB_ONLYDIR) ?: []) !== []);
    $bases = defined('OMF_PRIVATE_UPLOAD_DIR') ? [OMF_PRIVATE_UPLOAD_DIR] : ($saved !== '' ? [$saved] : array_merge($existing, [dirname(rtrim(ABSPATH, '/\\')) . $name], $temporary));
    $base = '';
    foreach (array_unique($bases) as $candidate) {
      $parent = realpath(dirname($candidate));
      if (!$parent || !is_writable($parent)) { continue; }
      $candidate = $parent . '/' . basename($candidate);
      if (is_link($candidate)) { continue; }
      if (is_dir($candidate)) { $candidate = realpath($candidate); }
      $private = true;
      foreach ([ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH] as $public) {
        $public = realpath($public);
        if ($public && ($candidate === $public || str_starts_with($candidate . '/', rtrim($public, '/') . '/'))) { $private = false; }
      }
      if ($private) { $base = $candidate; break; }
    }
    if ($base === '' || (!is_dir($base) && !wp_mkdir_p($base))) { throw new \RuntimeException('公開領域外の添付保存先を作成できません。'); }
    chmod($base, 0700);
    if (!defined('OMF_PRIVATE_UPLOAD_DIR') && $saved === '') { update_option('omf_private_upload_directory', $base, false); }
    return $base;
  }

  public static function save(string $source, string $name): string
  {
    $base = self::directory();
    self::cleanup();
    $pending = array_filter(glob($base . '/*', GLOB_ONLYDIR) ?: [], static fn($dir) => !OMF_Legacy_Uploads::retained(basename($dir)));
    if (count($pending) >= (int) apply_filters('omf_max_pending_uploads', 100)) {
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
    $dir = self::directory() . '/' . $id;
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
    $base = self::directory();
    $id = bin2hex(random_bytes(20));
    $dir = $base . '/' . $id;
    if (!mkdir($dir, 0700) || !copy($source, $dir . '/' . sanitize_file_name($name))) {
      @rmdir($dir);
      throw new \RuntimeException('添付の一時保存に失敗しました。');
    }
    chmod($dir . '/' . sanitize_file_name($name), 0600);
    return $id;
  }

  public static function cleanup(): void
  {
    try { $base = self::directory(); } catch (\Throwable $e) { return; }
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
