<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 正規化済みの定義から、フォーム項目のHTMLを出力する。 */
class OMF_Field_Renderer
{
  /** エスケープ済みの項目HTMLを返す。外側の配置はテーマが担当する。 */
  public static function html(array $field, mixed $value, array $errors, bool $confirm, int $form_id): string
  {
    ob_start();
    try {
      self::render($field, $value, $errors, $confirm, $form_id);
      return (string) ob_get_contents();
    } finally {
      ob_end_clean();
    }
  }

  public static function render(array $field, mixed $value, array $errors, bool $confirm, int $form_id): void
  {
    $html = apply_filters('omf_field_html', null, $field, $value, $errors, $confirm, $form_id);
    if (is_string($html)) { echo $html; return; } // 開発者が生成するHTML。エスケープはコールバックの責任。
    do_action('omf_before_field', $field, $value, $confirm, $form_id);
    $key = $field['key']; $type = $field['type']; $id = 'omf-' . $form_id . '-' . $key;
    $group = !$confirm && in_array($type, ['radio', 'checkboxes'], true);
    $tag = 'div';
    echo '<' . $tag . ' class="omf-managed-field omf-managed-field--' . esc_attr($type) . '" data-omf-field="' . esc_attr($key) . '"' . ($group ? ' role="group" aria-labelledby="' . esc_attr($id . '-label') . '"' : '') . '>';
    $label_tag = ($group || $confirm) ? 'p' : 'label';
    echo '<' . $label_tag . ($group ? ' id="' . esc_attr($id . '-label') . '"' : ($confirm ? '' : ' for="' . esc_attr($id) . '"')) . '>' . nl2br(esc_html($field['label']));
    if (!$confirm && $field['required']) { echo ' <span class="omf-managed-required">必須</span>'; }
    elseif (!$confirm && !empty($field['required_if'])) { echo ' <span class="omf-managed-required omf-managed-required--conditional" hidden>必須</span>'; }
    echo '</' . $label_tag . '>';
    echo '<div class="omf-managed-control">';
    if ($confirm) {
      $display = self::display($field, $value);
      echo '<p class="omf-managed-value" id="' . esc_attr($id) . '">' . nl2br(esc_html($display === '' ? '—' : $display)) . '</p>';
    } else {
      $attrs = self::attributes($field, $value, $errors, $form_id);
      $text = is_scalar($value) ? (string) $value : '';
      if ($type === 'textarea') {
        echo '<textarea' . $attrs . ' placeholder="' . esc_attr($field['placeholder']) . '">' . esc_textarea($text) . '</textarea>';
      } elseif ($type === 'select') {
        echo '<span class="omf-managed-select"><select' . $attrs . '><option value="">選択してください</option>';
        foreach ($field['choices'] as $choice) { echo '<option value="' . esc_attr($choice['value']) . '"' . selected($text, $choice['value'], false) . '>' . esc_html($choice['label']) . '</option>'; }
        echo '</select></span>';
      } elseif ($group) {
        echo '<div class="omf-managed-choices">';
        foreach ($field['choices'] as $index => $choice) {
          $checked = is_array($value) ? in_array($choice['value'], $value, true) : $text === $choice['value'];
          $choice_attrs = self::attributes($field, $value, $errors, $form_id, $id . '-' . $index);
          if ($type === 'checkboxes') { $choice_attrs = str_replace('name="' . esc_attr('omf_fields[' . $key . ']') . '"', 'name="' . esc_attr('omf_fields[' . $key . '][]') . '"', $choice_attrs); }
          echo '<label class="omf-managed-choice"><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '"' . $choice_attrs . ' value="' . esc_attr($choice['value']) . '"' . ($checked ? ' checked' : '') . ($field['required'] && $type === 'radio' ? ' required' : '') . '><span>' . esc_html($choice['label']) . '</span></label>';
        }
        echo '</div>';
      } elseif ($type === 'acceptance') {
        if (!empty($field['policy_text'])) { echo '<div class="omf-managed-policy" tabindex="0" role="region" aria-label="' . esc_attr($field['label']) . '">' . self::description_html($field['policy_text']) . '</div>'; }
        echo '<label class="omf-managed-choice"><input type="checkbox"' . $attrs . ' value="1"' . ($text === '1' ? ' checked' : '') . '><span>' . ($field['description'] !== '' ? self::description_html($field['description']) : '同意する') . '</span></label>';
      } elseif ($type === 'file') {
        echo '<input type="file"' . $attrs . ' accept="' . esc_attr(implode(',', array_map(static fn($ext) => '.' . $ext, $field['extensions']))) . '">';
        echo '<p>上限 ' . esc_html((string) round($field['max_bytes'] / 1048576, 2)) . ' MiB</p>';
        if (!empty($value['upload_id'])) { echo '<p>' . esc_html((string) ($value['name'] ?? '添付ファイル')) . ' を保持しています。選び直すと差し替えます。</p>'; }
      } else {
        echo '<input type="' . esc_attr($type) . '"' . $attrs . ' value="' . esc_attr($text) . '" placeholder="' . esc_attr($field['placeholder']) . '">';
      }
      if ($errors) { echo '<p class="omf-managed-error" id="' . esc_attr($id . '-error') . '">' . esc_html(implode(' ', $errors)) . '</p>'; }
    }
    if (!$confirm && $field['description'] !== '' && $type !== 'acceptance') { echo '<p id="' . esc_attr($id . '-help') . '" class="omf-managed-help">' . self::description_html($field['description']) . '</p>'; }
    echo '</div>';
    echo '</' . $tag . '>';
  }

  /** テーマ独自のHTMLでも、管理設定と同じ検証・操作用属性を使う。 */
  public static function attributes(array $field, mixed $value, array $errors, int $form_id, ?string $id = null): string
  {
    $key = $field['key']; $type = $field['type'];
    $id ??= 'omf-' . $form_id . '-' . $key;
    $group = in_array($type, ['radio', 'checkboxes'], true);
      $base_id = 'omf-' . $form_id . '-' . $key;
      $described = array_filter([$field['description'] !== '' && $type !== 'acceptance' ? $base_id . '-help' : '', $errors ? $base_id . '-error' : '']);
      $attrs = ' id="' . esc_attr($id) . '" name="' . esc_attr('omf_fields[' . $key . ']') . '" data-omf-role="field" data-omf-field="' . esc_attr($key) . '" data-validate="' . esc_attr(self::validation_rules($field)) . '"';
      $attrs .= ' data-omf-validation="' . esc_attr(wp_json_encode(OMF_Field_Schema::checks($field))) . '"';
      if ($field['choices']) { $attrs .= ' data-omf-allowed-values="' . esc_attr(wp_json_encode(array_column($field['choices'], 'value'))) . '"'; }
      if ($type === 'acceptance') { $attrs .= ' data-omf-allowed-values="[&quot;1&quot;]"'; }
      if ($type === 'file' && !empty($value['upload_id'])) { $attrs .= ' data-omf-file-retained="1"'; }
      if ($type === 'file') { $attrs .= ' data-omf-max-bytes="' . esc_attr((string) $field['max_bytes']) . '" data-omf-extensions="' . esc_attr(implode(',', $field['extensions'])) . '"'; }
      if (($field['validation_format'] ?? '') === 'postal_code') { $attrs .= ' data-omf-postal="1" inputmode="numeric"'; }
      if (!empty($field['address_targets'])) { $attrs .= ' data-omf-address-targets="' . esc_attr(wp_json_encode($field['address_targets'])) . '" data-omf-postal-base="' . esc_url(OMF_Postal_Updater::current()['base_url']) . '"'; }
      if ($described) { $attrs .= ' aria-describedby="' . esc_attr(implode(' ', $described)) . '"'; }
      if ($errors) { $attrs .= ' aria-invalid="true"'; }
      if ($field['required'] && !$group && !($type === 'file' && !empty($value['upload_id']))) { $attrs .= ' required'; }
      if (!empty($field['required_if'])) { $attrs .= ' data-omf-required-if-key="' . esc_attr($field['required_if']['key']) . '" data-omf-required-if-value="' . esc_attr($field['required_if']['value']) . '"'; }
      if (!empty($field['max_length'])) { $attrs .= ' data-omf-max-length="' . esc_attr((string) $field['max_length']) . '"'; }
    return $attrs;
  }

  public static function display(array $field, mixed $value): string
  {
    if ($field['type'] === 'file') { return !empty($value['upload_id']) ? (string) ($value['name'] ?? '添付ファイルあり') : ''; }
    if ($field['type'] === 'acceptance') { return $value === '1' ? '同意する' : ''; }
    if ($field['choices']) {
      $values = is_array($value) ? $value : [$value];
      return implode('、', array_column(array_filter($field['choices'], static fn($c) => in_array($c['value'], $values, true)), 'label'));
    }
    return is_scalar($value) ? (string) $value : '';
  }

  /** フロントの補助検証用。PHP側の検証ルールは別途常に適用する。 */
  public static function validation_rules(array $field): string
  {
    $rules = [];
    if ($field['required']) { $rules[] = 'required'; }
    if (in_array($field['type'], ['email', 'tel', 'url'], true)) { $rules[] = $field['type']; }
    if (!empty($field['validation_format'])) { $rules[] = $field['validation_format']; }
    if (!empty($field['min_length'])) { $rules[] = 'minLength:' . $field['min_length']; }
    if (!empty($field['max_length'])) { $rules[] = 'maxLength:' . $field['max_length']; }
    return implode('|', $rules);
  }

  /** 説明文の [表示名](/path/) または [表示名](https://...) だけを安全なリンクにする。 */
  public static function description_html(string $description): string
  {
    $html = ''; $offset = 0;
    preg_match_all('~\[([^\]\r\n]+)\]\((/(?!/)[^\s)]+|https?://[^\s)]+)\)~u', $description, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $index => $match) {
      $html .= esc_html(substr($description, $offset, $match[1] - $offset));
      $url = $matches[2][$index][0];
      if (str_starts_with($url, '/')) { $url = home_url($url); }
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($matches[1][$index][0]) . '</a>';
      $offset = $match[1] + strlen($match[0]);
    }
    $html .= esc_html(substr($description, $offset));
    return nl2br($html);
  }
}
