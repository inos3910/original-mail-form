<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 環境ごとに変わる投稿IDと、{post_type, path} の相互変換。
 */
class OMF_Port_Paths
{
  /**
   * 投稿IDを {post_type, path} に変換する。見つからなければ null。
   *
   * @param int $id
   * @return array|null
   */
  public static function to_target(int $id): ?array
  {
    $post = $id > 0 ? get_post($id) : null;
    if (!$post) {
      return null;
    }
    return ['post_type' => (string) $post->post_type, 'path' => (string) get_page_uri($post)];
  }

  /**
   * カンマ区切りのIDを {post_type, path} の配列に変換する。見つからないIDは除く。
   *
   * @param mixed $raw
   * @return array
   */
  public static function ids_to_targets(mixed $raw): array
  {
    $targets = [];
    foreach (explode(',', (string) $raw) as $value) {
      $target = self::to_target((int) trim($value));
      if ($target !== null) {
        $targets[] = $target;
      }
    }
    return $targets;
  }

  /**
   * {post_type, path} から取り込み先の投稿IDを返す。見つからなければ 0。
   *
   * @param array $target
   * @return int
   */
  public static function to_id(array $target): int
  {
    $post_type = $target['post_type'] ?? '';
    $path = $target['path'] ?? '';
    if (!is_string($post_type) || !is_string($path) || $post_type === '' || $path === '' || !post_type_exists($post_type)) {
      return 0;
    }
    $post = get_page_by_path($path, OBJECT, $post_type);
    return $post ? (int) $post->ID : 0;
  }

  /**
   * フォームのスラッグに連携しているページを {post_type, path} で返す。
   *
   * @param string $slug
   * @return array
   */
  public static function linked_targets(string $slug): array
  {
    $targets = [];
    foreach (self::linked_posts($slug) as $post) {
      $targets[] = ['post_type' => (string) $post->post_type, 'path' => (string) get_page_uri($post)];
    }
    return $targets;
  }

  /**
   * cf_omf_select がフォームのスラッグになっている投稿。
   *
   * @param string $slug
   * @return array
   */
  private static function linked_posts(string $slug): array
  {
    return get_posts([
      'post_type' => 'any',
      'post_status' => 'any',
      'meta_key' => OMF_Port_Schema::META_SELECT,
      'meta_value' => $slug,
      'numberposts' => -1,
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);
  }
}
