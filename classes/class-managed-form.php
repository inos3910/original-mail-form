<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 管理画面方式の表示条件・画面状態。既存コード方式の経路は変更しない。 */
class OMF_Managed_Form
{
  private static array $completions = [];
  private static array $contexts = [];

  public static function complete_for_current_request(\WP_Post $form, array $data): void
  {
    unset(self::$contexts[OMF_Embed_Context::prefix($form->post_name)]);
    self::$completions[OMF_Embed_Context::prefix($form->post_name)] = [
      'form' => clone $form, 'data' => $data, 'schema' => OMF_Field_Schema::read($form->ID),
      'message' => self::complete_message($form->ID),
      'async' => in_array(OMF_Delivery::mode($form->ID), ['wp_async', 'server_cron'], true),
    ];
  }

  public static function completion(\WP_Post $form): ?array
  {
    return self::$completions[OMF_Embed_Context::prefix($form->post_name)] ?? null;
  }

  public static function enabled(int $id): bool
  {
    return OMF_Field_Schema::mode($id) === 'builder' && get_post_meta($id, 'cf_omf_render_enabled', true) === '1';
  }

  public static function step(\WP_Post $form): string
  {
    return self::completion($form) ? 'complete' : OMF_Form_Routes::step($form);
  }

  public static function current_step(int|array $selector = 0): string
  {
    $form = $GLOBALS['global_omf']->get_instance('page')->get_form(get_queried_object_id());
    if (!$form || !self::enabled($form->ID)) { return 'entry'; }
    $id = is_int($selector) ? $selector : ($selector['id'] ?? 0);
    $slug = is_array($selector) ? ($selector['slug'] ?? '') : '';
    if (($id && (int) $id !== (int) $form->ID) || ($slug !== '' && $slug !== $form->post_name)) { return 'entry'; }
    return self::step($form);
  }

  /** PHPの読込先を有効なテーマ内のファイルに限定する。 */
  private static function template_path(mixed $value): ?string
  {
    if ($value === '' || $value === null) { return null; }
    if (!is_string($value)) { return null; }
    $path = realpath($value);
    if (!$path || !is_file($path) || !str_ends_with($path, '.php')) { return null; }
    foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
      $base = realpath($dir);
      if ($base && str_starts_with($path, $base . DIRECTORY_SEPARATOR)) { return $path; }
    }
    return null;
  }

  /** 完了文面はHTMLとして解釈せず、表示側でエスケープする。 */
  public static function complete_message(int $form_id): string
  {
    $message = get_post_meta($form_id, 'cf_omf_complete_message', true);
    return is_string($message) && trim($message) !== '' ? $message : 'お問い合わせありがとうございます。';
  }

  /** テーマが各画面を構築するための動的データ。HTMLは出力しない。 */
  public static function context(int|array $selector = 0): array|\WP_Error
  {
    $page = $GLOBALS['global_omf']->get_instance('page');
    $form = $page->get_form(get_queried_object_id());
    $id = is_int($selector) ? $selector : ($selector['id'] ?? 0);
    $slug = is_array($selector) ? ($selector['slug'] ?? '') : '';
    if (!is_int($id) || !is_string($slug) || (is_array($selector) && !$id && $slug === '')) {
      return new \WP_Error('omf_selector', 'フォームの指定を確認してください。');
    }
    if (!$form || ($id && $id !== (int) $form->ID) || ($slug !== '' && $slug !== $form->post_name) || !self::enabled($form->ID)) {
      return new \WP_Error('omf_placement', 'フォームの設置設定を確認してください。');
    }
    $routes = OMF_Form_Routes::resolve($form);
    if ($routes['errors'] || !in_array((int) get_queried_object_id(), $routes['pages'], true)) {
      return new \WP_Error('omf_routes', 'フォームの専用ページ設定を確認してください。');
    }
    $prefix = OMF_Embed_Context::prefix($form->post_name);
    if (isset(self::$contexts[$prefix])) { return self::$contexts[$prefix]; }
    $completion = self::completion($form);
    $schema = $completion['schema'] ?? OMF_Field_Schema::read($form->ID);
    if (is_wp_error($schema)) { return new \WP_Error('omf_schema', '現在このフォームは利用できません。'); }
    $step = self::step($form);
    $data = $completion['data'] ?? $page->get_post_values();
    $initial = $step === 'entry' && !isset($_SESSION[$prefix . '_managed_edited']);
    $values = [];
    foreach ($schema['fields'] as $field) {
      $values[$field['key']] = $initial ? $field['default'] : ($data[$field['key']] ?? ($field['type'] === 'checkboxes' ? [] : ''));
    }
    return self::$contexts[$prefix] = [
      'form_id' => (int) $form->ID, 'slug' => $form->post_name, 'step' => $step,
      'fields' => $schema['fields'], 'values' => $step === 'complete' ? $data : $values,
      'errors' => $step === 'complete' ? [] : $page->get_errors(),
      'confirm' => $step === 'entry' && get_post_meta($form->ID, 'cf_omf_skip_confirm', true) !== '1',
      'action_url' => get_permalink(get_queried_object_id()),
      'complete_message' => $completion['message'] ?? self::complete_message($form->ID),
      'async' => $completion['async'] ?? in_array(OMF_Delivery::mode($form->ID), ['wp_async', 'server_cron'], true),
    ];
  }

  /** 管理設定の順序で入力・確認用HTMLを返し、完了では項目を生成しない。 */
  public static function fields(int|array $selector = 0): array|\WP_Error
  {
    $context = self::context($selector);
    if (is_wp_error($context)) { return $context; }
    if ($context['step'] === 'complete') { return []; }
    $fields = [];
    foreach ($context['fields'] as $field) {
      $key = $field['key'];
      $fields[$key] = OMF_Field_Renderer::html($field, $context['values'][$key],
        (array) ($context['errors'][$key] ?? []), $context['step'] === 'confirm', $context['form_id']);
    }
    return $fields;
  }

  public static function render(int|array $selector = 0): void
  {
    $context = self::context($selector);
    if (is_wp_error($context)) {
      echo '<p class="omf-render-notice">' . esc_html($context->get_error_message()) . '</p>';
      return;
    }
    $templates = [];
    foreach (['fields_template', 'actions_template', 'complete_template'] as $key) {
      $value = is_array($selector) ? ($selector[$key] ?? '') : '';
      $templates[$key] = self::template_path($value);
      if ($value !== '' && !$templates[$key]) {
        echo '<p class="omf-render-notice">テーマ内のテンプレートファイルを確認してください。</p>';
        return;
      }
    }
    $page = $GLOBALS['global_omf']->get_instance('page');
    $omf_step = $context['step']; $omf_form_id = $context['form_id'];
    $omf_fields = $context['fields']; $omf_values = $context['values']; $omf_errors = $context['errors'];
    $omf_confirm = $context['confirm']; $omf_complete_message = $context['complete_message'];
    if ($omf_step === 'complete') {
      if ($templates['complete_template']) { include $templates['complete_template']; }
      else { echo '<p class="omf-managed-complete-message" data-omf-step="complete">' . nl2br(esc_html($omf_complete_message)) . '</p>'; }
      return;
    }
    echo '<section class="omf-managed omf-managed--' . esc_attr($omf_step) . '" aria-label="お問い合わせフォーム" data-omf-step="' . esc_attr($omf_step) . '">';
    echo '<h2>' . ($omf_step === 'entry' ? 'お問い合わせ' : '入力内容の確認') . '</h2>';
    if ($omf_errors) {
      echo '<div class="omf-managed-errors" role="alert"><p>入力内容をご確認ください。</p><ul>';
      foreach ($omf_errors as $messages) {
        foreach ((array) $messages as $message) { echo '<li>' . esc_html((string) $message) . '</li>'; }
      }
      echo '</ul></div>';
    }
    echo '<form method="post" enctype="multipart/form-data" autocomplete="off" action="' . esc_url($context['action_url']) . '" data-omf-form="' . esc_attr($context['slug']) . '" data-omf-step="' . esc_attr($omf_step) . '">';
    $page->nonce_field();
    if ($templates['fields_template']) { include $templates['fields_template']; }
    else { foreach ($omf_fields as $field) { OMF_Field_Renderer::render($field, $omf_values[$field['key']], (array) ($omf_errors[$field['key']] ?? []), $omf_step === 'confirm', $omf_form_id); } }
    if ($omf_step === 'entry') { $page->recaptcha_field(); $page->turnstile_field(); }
    if ($templates['actions_template']) { include $templates['actions_template']; }
    else {
      echo '<div class="omf-managed-actions">' . OMF_Button_Renderer::html($context) . '</div>';
    }
    echo '</form></section>';
  }
}
