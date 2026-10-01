<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 旧テンプレートのメディア情報を維持し、ファイル実体は本人・管理者だけに公開する。 */
class OMF_Legacy_Uploads
{
  public function __construct()
  {
    add_filter('wp_get_attachment_url', [self::class, 'attachment_url'], 10, 2);
    add_action('template_redirect', [self::class, 'serve'], -1);
    add_action('delete_attachment', [self::class, 'delete_attachment']);
  }

  public static function decorate(array $file, int $form_id, int $attachment_id = 0): array
  {
    $path = OMF_Uploads::path($file['upload_id']);
    if (!$attachment_id) {
      $attachment_id = wp_insert_attachment(['post_title' => pathinfo($file['name'], PATHINFO_FILENAME), 'post_mime_type' => $file['type'], 'post_status' => 'private'], '', 0, true);
      if (is_wp_error($attachment_id) || !$attachment_id) { throw new \RuntimeException('添付情報を保存できません。'); }
    }
    update_post_meta($attachment_id, '_omf_private_upload_id', $file['upload_id']);
    wp_update_post(['ID' => $attachment_id, 'post_status' => 'private']);
    update_post_meta($attachment_id, '_omf_private_upload_form', $form_id);
    update_attached_file($attachment_id, $path);
    $file['attachment_id'] = (int) $attachment_id;
    $file['tmp_name'] = $path;
    $meta = ['file' => basename($path), 'sizes' => []];
    if (str_starts_with($file['type'], 'image/')) {
      $size = wp_getimagesize($path);
      if ($size) {
        $meta['width'] = $size[0]; $meta['height'] = $size[1];
        $file['image'] = ['src' => self::url($file['upload_id'], $attachment_id), 'width' => $size[0], 'height' => $size[1]];
      }
    }
    wp_update_attachment_metadata($attachment_id, $meta);
    if (taxonomy_exists('media_tag')) { wp_set_object_terms($attachment_id, 'temporary', 'media_tag'); }
    $image = wp_get_attachment_image_src($attachment_id, 'medium');
    if ($image) { $file['image'] = ['src' => $image[0], 'width' => $image[1], 'height' => $image[2]]; }
    return $file;
  }

  private static function url(string $id, int $attachment_id): string
  { return add_query_arg(['omf_upload' => $id, 'omf_attachment' => $attachment_id], home_url('/')); }

  public static function attachment_url(string $url, int $attachment_id): string
  {
    $id = get_post_meta($attachment_id, '_omf_private_upload_id', true);
    return is_string($id) && preg_match('/^[a-f0-9]{40}$/D', $id) ? self::url($id, $attachment_id) : $url;
  }

  public static function tag(array $file): array
  {
    $tag = ['id' => $file['attachment_id'], 'name' => $file['name'], 'url' => self::url($file['upload_id'], (int) $file['attachment_id'])];
    if (!empty($file['image'])) { $tag += $file['image']; }
    return $tag;
  }

  /** 更新前のPHPセッションにある一時メディアだけを、実体・DB情報を照合して移す。 */
  public static function import(array $file, int $form_id): array
  {
    $attachment_id = (int) ($file['attachment_id'] ?? 0);
    if (!$attachment_id || !has_term('temporary', 'media_tag', $attachment_id)) { return []; }
    $post = get_post($attachment_id);
    $path = get_attached_file($attachment_id);
    $base = realpath(wp_upload_dir()['basedir']);
    $real = is_string($path) ? realpath($path) : false;
    if (!$post || $post->post_type !== 'attachment' || !$base || !$real || is_link($path) || !str_starts_with($real, $base . '/') || !is_file($real)) { return []; }
    $type = wp_check_filetype_and_ext($real, $file['name'] ?? '');
    if (empty($type['ext']) || empty($type['type']) || in_array(strtolower($type['ext']), OMF_Config::BLOCKED_FILE_EXTENSIONS, true) || $post->post_mime_type !== $type['type'] || filesize($real) !== (int) ($file['size'] ?? -1)) { return []; }
    $id = OMF_Uploads::import($real, $file['name']);
    $meta = wp_get_attachment_metadata($attachment_id);
    $result = self::decorate(['upload_id' => $id, 'name' => sanitize_file_name($file['name']), 'type' => $type['type'], 'size' => filesize($real)], $form_id, $attachment_id);
    // 更新前の公開画像・サムネイルを残さない。
    foreach ($meta['sizes'] ?? [] as $size) {
      $thumbnail = dirname($real) . '/' . basename($size['file']);
      if (is_file($thumbnail) && !is_link($thumbnail)) { unlink($thumbnail); }
    }
    unlink($real);
    return $result;
  }

  public static function retain(array $ids): void
  {
    foreach ($ids as $id) {
      foreach (self::attachments($id) as $attachment_id) {
        update_post_meta($attachment_id, '_omf_retained_upload', '1');
        if (taxonomy_exists('media_tag')) { wp_remove_object_terms($attachment_id, 'temporary', 'media_tag'); }
      }
    }
  }

  private static function attachments(string $id): array
  { return get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_omf_private_upload_id', 'meta_value' => $id]); }

  public static function retained(string $id): bool
  {
    foreach (self::attachments($id) as $attachment_id) {
      if (get_post_meta($attachment_id, '_omf_retained_upload', true) === '1') { return true; }
    }
    return false;
  }

  public static function discard(string $id): void
  { foreach (self::attachments($id) as $attachment_id) { wp_delete_attachment($attachment_id, true); } }

  /** WordPress標準のメディア削除は公開領域外を消さないため、保管IDから実体を削除する。 */
  public static function delete_attachment(int $attachment_id): void
  {
    $id = get_post_meta($attachment_id, '_omf_private_upload_id', true);
    if (!is_string($id) || !preg_match('/^[a-f0-9]{40}$/D', $id)) { return; }
    $path = OMF_Uploads::path($id);
    if ($path !== '') { unlink($path); rmdir(dirname($path)); }
  }

  public static function serve(): void
  {
    if (!isset($_GET['omf_upload'])) { return; }
    $id = is_string($_GET['omf_upload']) ? $_GET['omf_upload'] : '';
    $attachment_id = isset($_GET['omf_attachment']) && is_scalar($_GET['omf_attachment']) ? absint($_GET['omf_attachment']) : 0;
    if (!preg_match('/^[a-f0-9]{40}$/D', $id) || !$attachment_id || get_post_meta($attachment_id, '_omf_private_upload_id', true) !== $id) { status_header(404); exit; }
    $owned = false;
    if (!empty($_COOKIE[session_name()])) {
      OMF_Utils::start_session();
      foreach ($_SESSION as $key => $files) {
        if (!str_ends_with((string) $key, '_uploaded_files') || !is_array($files)) { continue; }
        foreach ($files as $file) { if (is_array($file) && ($file['upload_id'] ?? '') === $id && ($file['attachment_id'] ?? 0) === $attachment_id) { $owned = true; } }
      }
      session_write_close();
    }
    if (!$owned && !current_user_can('edit_post', $attachment_id)) { status_header(404); exit; }
    $path = OMF_Uploads::path($id);
    if ($path === '') { status_header(404); exit; }
    $type = get_post_mime_type($attachment_id);
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Type: ' . $type);
    $inline = in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode(basename($path)));
    readfile($path);
    exit;
  }
}
