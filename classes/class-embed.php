<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** ブロックとショートコードの入口。送信処理は既存の共通描画へ渡す。 */
class OMF_Embed
{
  private static bool $rendered = false;

  public function __construct()
  {
    add_action('init', [self::class, 'register']);
    // 循環する同期パターンはWordPressの再帰描画へ渡さない。
    add_filter('pre_render_block', static function ($content, $block) {
      $context = OMF_Embed_Context::active();
      if (!empty($context['error']) && in_array($block['blockName'] ?? '', ['core/block', 'original-mail-form/form'], true)) {
        return self::notice($context['error']);
      }
      return $content;
    }, 10, 2);
    add_action('rest_api_init', [OMF_Embed_Editor::class, 'routes']);
    add_action('add_meta_boxes_' . OMF_Config::NAME, static function () {
      add_meta_box('omf-placement', 'ページに設置する', [OMF_Embed_Editor::class, 'placement'], OMF_Config::NAME, 'side');
    });
  }

  public static function register(): void
  {
    $root = dirname(__DIR__);
    wp_register_script('omf-form-block', plugins_url('dist/js/form-block.js', __DIR__), ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-api-fetch'], (string) filemtime($root . '/dist/js/form-block.js'), true);
    wp_register_style('omf-form-block-editor', plugins_url('assets/form-block-editor.css', __DIR__), [], (string) filemtime($root . '/assets/form-block-editor.css'));
    register_block_type($root . '/blocks/form', ['render_callback' => [self::class, 'block']]);
    add_shortcode('original_mail_form', [self::class, 'shortcode']);
    add_shortcode('omf_render_form', [self::class, 'shortcode']);
  }

  public static function block(array $attributes, string $content = '', ?\WP_Block $block = null): string
  {
    $id = isset($attributes['formId']) && is_numeric($attributes['formId']) ? absint($attributes['formId']) : 0;
    if ($block && (int) ($block->context['postId'] ?? 0) !== (int) get_queried_object_id()) {
      return self::notice('投稿・固定ページの本文にフォームを設置してください。');
    }
    $html = self::render($id);
    return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
  }

  public static function shortcode($attributes, string $content = '', string $tag = 'original_mail_form'): string
  {
    $attributes = shortcode_atts(['id' => '', 'slug' => ''], $attributes, $tag);
    $id = ctype_digit((string) $attributes['id']) ? (int) $attributes['id'] : 0;
    $slug = is_string($attributes['slug']) ? $attributes['slug'] : '';
    if ($slug !== '') {
      $form = get_page_by_path($slug, OBJECT, OMF_Config::NAME);
      if (!$form || ($id && $id !== (int) $form->ID)) { return self::notice('指定したフォームが見つかりません。'); }
      $id = (int) $form->ID;
    }
    if ($tag === 'omf_render_form' && !doing_filter('the_content')) {
      if (!$id || self::$rendered) { return self::notice('フォームの指定または設置数を確認してください。'); }
      self::$rendered = true;
      ob_start();
      OMF_Managed_Form::render($slug !== '' ? ['slug' => $slug] : $id);
      return ob_get_clean();
    }
    return self::render($id);
  }

  private static function render(int $id): string
  {
    if (!doing_filter('the_content') || (int) get_the_ID() !== (int) get_queried_object_id()) {
      return self::notice('投稿・固定ページの本文にフォームを設置してください。');
    }
    $context = OMF_Embed_Context::active();
    if (!empty($context['error'])) { return self::notice($context['error']); }
    if (!$id || empty($context['form']) || $context['form']->ID !== $id) {
      return self::notice('公開済みの「画面でかんたんに作成」フォームを選んでください。');
    }
    if (self::$rendered) { return self::notice('フォームは1ページに1つだけ設置してください。'); }
    self::$rendered = true;
    ob_start();
    OMF_Managed_Form::render($id);
    return ob_get_clean();
  }

  private static function notice(string $message): string
  {
    return '<p class="omf-render-notice" role="status">' . esc_html($message) . '</p>';
  }
}
