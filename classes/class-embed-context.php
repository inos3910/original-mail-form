<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 本文の設置情報を描画前に解決し、フォーム単位で状態を共有する。 */
class OMF_Embed_Context
{
  private static array $cache = [];

  public static function resolve(int $page_id): array
  {
    if (isset(self::$cache[$page_id])) { return self::$cache[$page_id]; }
    $result = ['present' => false, 'form' => null, 'error' => ''];
    $post = get_post($page_id);
    if (!$post || !in_array($post->post_type, ['page', 'post'], true)) { return $result; }
    $found = []; $error = ''; $budget = 5000;
    self::scan(parse_blocks($post->post_content), [], false, $found, $error, $budget);
    $result['present'] = count($found) > 0;
    if (!$result['present']) { return self::$cache[$page_id] = $result; }
    if (count($found) !== 1) { $error = 'フォームは1ページに1つだけ設置してください。'; }
    $form = self::eligible((int) $found[0]);
    if (!$form && !$error) { $error = '公開済みの「画面でかんたんに作成」フォームを選び直してください。'; }
    $result['error'] = $error;
    $result['form'] = $error ? null : $form;
    return self::$cache[$page_id] = $result;
  }

  private static function scan(array $blocks, array $refs, bool $query, array &$found, string &$error, int &$budget): void
  {
    foreach ($blocks as $block) {
      if (--$budget < 0) { $error = '本文の入れ子が多すぎるためフォームを設置できません。'; $found[] = 0; return; }
      $name = $block['blockName'] ?? '';
      $in_query = $query || $name === 'core/query';
      if ($name === 'original-mail-form/form') {
        $value = $block['attrs']['formId'] ?? 0;
        $found[] = is_scalar($value) ? absint($value) : 0;
        if ($in_query) { $error = 'フォームをクエリーループの外へ移動してください。'; }
      }
      if ($name === 'core/block' && !empty($block['attrs']['ref'])) {
        $ref = absint($block['attrs']['ref']);
        if (in_array($ref, $refs, true) || count($refs) >= 32) { $error = '同期パターンの循環参照を解消してください。'; $found[] = 0; continue; }
        $pattern = get_post($ref);
        if ($pattern && $pattern->post_type === 'wp_block' && $pattern->post_status === 'publish') {
          self::scan(parse_blocks($pattern->post_content), [...$refs, $ref], $in_query, $found, $error, $budget);
        }
      }
      // 親のinnerHTMLは子ブロックの内容を含まないため二重集計しない。
      $html = $block['innerHTML'] ?? '';
      if ((strpos($html, '[original_mail_form') !== false || strpos($html, '[omf_render_form') !== false) && preg_match_all('/' . get_shortcode_regex(['original_mail_form', 'omf_render_form']) . '/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
          if ($match[1] === '[' && $match[6] === ']') { continue; }
          $attrs = shortcode_parse_atts($match[3]);
          $id = isset($attrs['id']) && ctype_digit((string) $attrs['id']) ? (int) $attrs['id'] : 0;
          if ($match[2] === 'omf_render_form' && !empty($attrs['slug']) && is_string($attrs['slug'])) {
            $form = get_page_by_path($attrs['slug'], OBJECT, OMF_Config::NAME);
            $id = $form && (!$id || $id === (int) $form->ID) ? (int) $form->ID : 0;
          }
          $found[] = $id;
          if ($in_query) { $error = 'フォームをクエリーループの外へ移動してください。'; }
        }
      }
      self::scan($block['innerBlocks'] ?? [], $refs, $in_query, $found, $error, $budget);
    }
  }

  public static function eligible(int $id): ?\WP_Post
  {
    $post = $id ? get_post($id) : null;
    return $post && $post->post_type === OMF_Config::NAME && $post->post_status === 'publish' && OMF_Managed_Form::enabled($id) ? $post : null;
  }

  public static function active(): array
  {
    // REST・管理画面・配送ワーカーには本文の状態を持ち込まない。
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || !is_singular(['post', 'page'])) { return []; }
    return self::resolve((int) get_queried_object_id());
  }

  public static function prefix(string $slug): string
  {
    return OMF_Config::PREFIX . $slug;
  }

  public static function token_key(): string
  {
    $form = self::active()['form'] ?? null;
    if (!$form && is_singular() && isset($GLOBALS['global_omf'])) {
      $form = $GLOBALS['global_omf']->get_instance('page')->get_form(get_queried_object_id());
    }
    return $form && OMF_Managed_Form::enabled($form->ID) ? self::prefix($form->post_name) . '_token' : 'omf_token';
  }
}
