<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 管理者が編集できる投稿・固定ページだけを候補として返す。 */
class OMF_Post_Picker
{
  public static function selected_titles(): array
  {
    $id = isset($_GET['post']) && is_scalar($_GET['post']) ? absint($_GET['post']) : 0;
    if (!$id || !current_user_can('edit_post', $id)) { return []; }
    $titles = [];
    foreach (explode(',', (string) get_post_meta($id, 'cf_omf_condition_id', true)) as $value) {
      $post = get_post((int) trim($value));
      if ($post && in_array($post->post_type, ['post', 'page'], true) && current_user_can('edit_post', $post->ID)) {
        $titles[(string) $post->ID] = get_the_title($post) ?: '（タイトルなし）';
      }
    }
    return $titles;
  }

  public static function search(): void
  {
    check_ajax_referer('omf_search_pages', 'nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error([], 403); }
    $raw = $_GET['q'] ?? '';
    if (!is_string($raw)) { wp_send_json_error([], 400); }
    $term = sanitize_text_field(wp_unslash($raw));
    if ($term === '' || strlen($term) > 100) { wp_send_json_success([]); }
    $args = ['post_type' => ['post', 'page'], 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 20, 'no_found_rows' => true, 'orderby' => 'title', 'order' => 'ASC'];
    if (ctype_digit($term)) { $args['p'] = (int) $term; }
    else { $args['s'] = $term; $args['search_columns'] = ['post_title']; }
    $items = [];
    foreach (get_posts($args) as $post) {
      if (!current_user_can('edit_post', $post->ID)) { continue; }
      $status = get_post_status_object($post->post_status);
      $items[] = ['id' => $post->ID, 'title' => get_the_title($post) ?: '（タイトルなし）', 'type' => $post->post_type === 'page' ? '固定ページ' : '投稿', 'path' => get_page_uri($post), 'status' => $status ? $status->label : $post->post_status, 'published' => $post->post_status === 'publish'];
    }
    wp_send_json_success($items);
  }
}
