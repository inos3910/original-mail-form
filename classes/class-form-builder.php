<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 項目編集画面。保存は既存管理クラスの認証後にまとめて行う。 */
class OMF_Form_Builder
{
  public function __construct()
  {
    add_action('wp_ajax_omf_search_pages', [OMF_Post_Picker::class, 'search']);
    add_action('add_meta_boxes_' . OMF_Config::NAME, static function () {
      add_meta_box('omf-builder', 'フォームの項目', [self::class, 'render'], OMF_Config::NAME, 'normal', 'high');
    });
    add_action('admin_enqueue_scripts', static function () {
      $screen = get_current_screen();
      if (!$screen || $screen->post_type !== OMF_Config::NAME || $screen->base !== 'post') { return; }
      wp_enqueue_script('omf-builder', plugins_url('dist/js/form-builder.js', __DIR__), [], (string) filemtime(dirname(__DIR__) . '/dist/js/form-builder.js'), true);
      wp_localize_script('omf-builder', 'omfPostPicker', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('omf_search_pages'), 'titles' => OMF_Post_Picker::selected_titles()]);
    });
  }

  public static function render(\WP_Post $post): void
  {
    $schema = get_post_meta($post->ID, OMF_Field_Schema::META_KEY, true);
    $schema = is_array($schema) ? $schema : ['version' => 1, 'fields' => []];
    $mode = OMF_Field_Schema::mode($post->ID);
    echo '<div id="omf-field-builder" class="omf-builder" data-schema="' . esc_attr(wp_json_encode($schema)) . '">';
    echo '<div class="omf-builder-intro"><div><h3>フォームをつくる</h3><p>入力欄を追加して、質問する内容を編集しましょう。</p></div><span class="omf-builder-save-state" data-save-state>保存済みの設定</span></div>';
    echo '<label class="omf-builder-mode">作成方法<select name="omf_builder_mode"><option value="code" ' . selected($mode, 'code', false) . '>コードで自由に作成</option><option value="builder" ' . selected($mode, 'builder', false) . '>画面でかんたんに作成</option></select></label>';
    echo '<label class="omf-builder-mode">入力中のJS検証<select name="omf_front_validation"><option value="0">OFF（PHP検証のみ）</option><option value="1" ' . selected(get_post_meta($post->ID, 'cf_omf_front_validation', true), '1', false) . '>ON（入力中にもエラーを表示）</option></select></label><p class="omf-builder-helper">ONでも送信時のPHP検証は常に実行します。コード方式では入力要素の data-validate 属性を読み取ります。</p>';
    echo '<p data-code-note>現在はテーマのコードで入力欄を作成しています。画面で編集したい場合は、作成方法を切り替えてください。</p>';
    echo '<div data-builder-workspace hidden><label>送信前の確認<select name="omf_skip_confirm"><option value="0">確認画面を表示</option><option value="1" ' . selected(get_post_meta($post->ID, 'cf_omf_skip_confirm', true), '1', false) . '>確認を省略して送信</option></select></label><div class="omf-builder-section-head"><h4>入力欄を追加</h4><span>種類を選んで追加できます</span></div><div class="omf-builder-palette" aria-label="追加する入力欄の種類"></div>';
    echo '<div class="omf-builder-section-head"><h4>フォームの内容</h4><span>ドラッグ、または上下ボタンで並べ替え</span></div><p class="omf-builder-helper">入力欄の見本です。サイトの表示デザインはテーマによって変わります。</p><div class="omf-builder-fields"></div>';
    echo '<section class="omf-builder-completion" aria-label="完了画面の設定"><label for="omf-complete-message">完了画面のメッセージ</label><textarea id="omf-complete-message" name="omf_complete_message" rows="4" maxlength="10000" aria-describedby="omf-complete-message-help">' . esc_textarea(get_post_meta($post->ID, 'cf_omf_complete_message', true)) . '</textarea><p id="omf-complete-message-help" class="omf-builder-helper">送信後の画面に表示します。改行できます。空欄なら「お問い合わせありがとうございます。」を表示します。HTMLやメール用タグは使用できません。</p></section>';
    echo '<details class="omf-builder-setup"><summary>サイトへの設置について</summary><p>フォームを公開し、投稿・固定ページ本文に「お問い合わせフォーム」ブロックを追加して、このフォームを選びます。PHP編集は不要です。入力・確認・完了の各ページの「メールフォーム連携」で、設置したフォームとの連携を有効にして保存してください。「連携しない」のページでは表示・送信できません。「ページに設置する」欄のショートコードも使えます。既存のPHP関数による設置も引き続き利用できます。</p></details></div>';
    echo '<input type="hidden" name="omf_builder_schema" disabled><div class="omf-builder-status" role="status" aria-live="polite"></div>';
    echo '<div class="omf-builder-footer"><span>この画面の変更をまとめて保存します</span><button type="button" class="omf-builder-button omf-builder-button--primary" data-builder-save disabled>変更を保存</button></div>';
    echo '<noscript><p>項目編集にはJavaScriptが必要です。既存の項目設定は変更されません。</p></noscript></div>';
  }

  /** メール設定も含め、更新前の全体検証。失敗時は一切のフォーム設定を更新しない。 */
  public static function prepare(int $post_id, array $input): array|\WP_Error|null
  {
    if (!isset($input['omf_builder_schema'])) { return null; }
    $mode = $input['omf_builder_mode'] ?? '';
    if (!in_array($mode, ['code', 'builder'], true) || !is_string($input['omf_builder_schema'])) {
      return new \WP_Error('omf_builder_invalid', 'フォーム方式または項目設定が不正です。');
    }
    $raw = json_decode($input['omf_builder_schema'], true);
    // コード方式だけで使用するフォームには空の定義を許容する。
    if ($mode === 'code' && $raw === ['version' => 1, 'fields' => []]) {
      $schema = $raw;
    } else {
      $schema = OMF_Field_Schema::normalize($raw);
      if (is_wp_error($schema)) { return $schema; }
    }
    $old = get_post_meta($post_id, OMF_Field_Schema::META_KEY, true);
    $old_keys = is_array($old) ? array_column($old['fields'] ?? [], 'key') : [];
    foreach ($raw['fields'] as $field) {
      $original = $field['original_key'] ?? '';
      if ($original !== '' && (!in_array($original, $old_keys, true) || $original !== $field['key'])) {
        return new \WP_Error('omf_builder_key', '保存済みの項目キーは変更できません。');
      }
    }
    $new_keys = array_column($schema['fields'], 'key');
    $removed = array_diff($old_keys, $new_keys);
    // 旧コード方式のメールタグも切り替え時に消失させない。
    if ($mode === 'builder' && OMF_Field_Schema::mode($post_id) === 'code') {
      foreach ((array) get_post_meta($post_id, 'cf_omf_validation', true) as $rule) {
        if (is_array($rule) && isset($rule['target']) && !in_array($rule['target'], $new_keys, true)) { $removed[] = $rule['target']; }
      }
    }
    foreach (['reply' => '自動返信', 'admin' => '通知メール'] as $prefix => $label) {
      foreach (['to', 'title', 'mail', 'from', 'from_name', 'address', 'reply_to'] as $part) {
        $meta_key = 'cf_omf_' . $prefix . '_' . $part;
        $text = $input[$meta_key] ?? get_post_meta($post_id, $meta_key, true);
        if (!is_string($text)) { return new \WP_Error('omf_builder_mail', $label . 'の設定値が不正です。'); }
        foreach ($removed as $key) {
          if (str_contains($text, '{' . $key . '}')) {
            return new \WP_Error('omf_builder_reference', $label . '（' . $part . '）で {' . $key . '} を使用しています。メール設定を変更するか項目の削除を取り消してください。');
          }
        }
      }
    }
    $skip = $input['omf_skip_confirm'] ?? (get_post_meta($post_id, 'cf_omf_skip_confirm', true) === '1' ? '1' : '0');
    if (!in_array($skip, ['0', '1'], true)) { return new \WP_Error('omf_builder_confirm', '確認画面の設定が不正です。'); }
    $front = $input['omf_front_validation'] ?? (get_post_meta($post_id, 'cf_omf_front_validation', true) === '1' ? '1' : '0');
    if (!in_array($front, ['0', '1'], true)) { return new \WP_Error('omf_builder_front_validation', 'JS検証の設定が不正です。'); }
    $message = $input['omf_complete_message'] ?? get_post_meta($post_id, 'cf_omf_complete_message', true);
    if (!is_string($message) || strlen($message) > 40000) { return new \WP_Error('omf_builder_complete_message', '完了メッセージを確認してください。'); }
    return ['complete_message' => sanitize_textarea_field($message), 'mode' => $mode, 'schema' => $schema, 'skip_confirm' => $skip, 'front_validation' => $front];
  }

  public static function persist(int $post_id, array $prepared): void
  {
    update_post_meta($post_id, 'cf_omf_complete_message', $prepared['complete_message'] ?? '');
    update_post_meta($post_id, OMF_Field_Schema::META_KEY, $prepared['schema']);
    update_post_meta($post_id, OMF_Field_Schema::MODE_KEY, $prepared['mode']);
    update_post_meta($post_id, 'cf_omf_render_enabled', '1');
    update_post_meta($post_id, 'cf_omf_skip_confirm', $prepared['skip_confirm'] ?? '0');
    update_post_meta($post_id, 'cf_omf_front_validation', $prepared['front_validation'] ?? '0');
  }
}
