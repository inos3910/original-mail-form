<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** エディターには公開フォームの見本だけを渡し、送信状態を生成しない。 */
class OMF_Embed_Editor
{
  /** フォーム編集画面の設置欄だけにスタイルを読み込む。 */
  public static function enqueue_style(): void
  {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== OMF_Config::NAME || $screen->base !== 'post') { return; }
    wp_enqueue_style('omf-placement-editor', plugins_url('assets/embed-editor.css', __DIR__), [], (string) filemtime(dirname(__DIR__) . '/assets/embed-editor.css'));
  }

  public static function routes(): void
  {
    register_rest_route('original-mail-form/v1', '/forms', [
      'methods' => 'GET', 'callback' => [self::class, 'search'],
      'permission_callback' => static fn() => current_user_can('edit_posts') || current_user_can('edit_pages'),
      'args' => [
        'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
        'id' => ['type' => 'integer', 'minimum' => 1],
      ],
    ]);
  }

  public static function search(\WP_REST_Request $request): array
  {
    $args = ['post_type' => OMF_Config::NAME, 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC',
      'meta_query' => [['key' => OMF_Field_Schema::MODE_KEY, 'value' => 'builder'], ['key' => 'cf_omf_render_enabled', 'value' => '1']]];
    if ($request['id']) { $args['p'] = (int) $request['id']; } else { $args['s'] = $request['search']; }
    $result = [];
    foreach (get_posts($args) as $post) {
      if (!OMF_Embed_Context::eligible($post->ID)) { continue; }
      $schema = OMF_Field_Schema::read($post->ID);
      if (is_wp_error($schema)) { continue; }
      $fields = array_map(static fn($field) => array_intersect_key($field, array_flip(['label', 'type', 'required', 'choices'])), $schema['fields']);
      $result[] = ['id' => $post->ID, 'title' => $post->post_title ?: '名称未設定のフォーム', 'fields' => $fields,
        'editUrl' => current_user_can('edit_post', $post->ID) ? get_edit_post_link($post->ID, 'raw') : ''];
    }
    return $result;
  }

  public static function placement(\WP_Post $post): void
  {
    if (!OMF_Embed_Context::eligible($post->ID)) {
      echo '<p>「画面でかんたんに作成」で項目を保存し、フォームを公開すると設置できます。</p>';
      return;
    }
    $routes = OMF_Form_Routes::resolve($post);
    if ($routes['errors']) {
      echo '<div class="notice notice-error inline"><p>専用ページの設定を修正するまで送信できません。</p><ul>';
      foreach ($routes['errors'] as $error) { echo '<li>' . esc_html($error) . '</li>'; }
      echo '</ul></div>';
    }
    echo '<p>投稿・固定ページで「お問い合わせフォーム」ブロックを追加し、このフォームを選んでください。</p>';
    echo '<p>ショートコードでも設置できます。入力・確認・完了は同じ記述で表示します。</p><div class="omf-placement-shortcode"><label for="omf-shortcode">設置用ショートコード</label>';
    echo '<input type="text" id="omf-shortcode" class="omf-shortcode-value" readonly value="' . esc_attr('[omf_render_form slug="' . $post->post_name . '"]') . '">';
    echo '<p><button type="button" class="button" data-omf-copy-shortcode>コピーする</button> <span role="status" data-omf-copy-status></span></p></div>';
    echo '<p>画面設定で入力・確認・完了の専用ページを指定し、各ページに同じフォームを設置してください。確認を省略する場合は入力・完了の2ページが必要です。設置方法にかかわらず、各ページの「メールフォーム連携」で同じフォームとの連携を有効にして保存してください。「連携しない」のページでは表示・送信できません。ブロック・ショートコードならPHP編集は不要です。1ページに1フォームを設置できます。</p>';
  }
}
