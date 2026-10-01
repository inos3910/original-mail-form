<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 管理画面方式の項目定義と、既存バリデーションへの変換を扱う。 */
class OMF_Field_Schema
{
  const VERSION = 1;
  const META_KEY = 'cf_omf_field_schema';
  const MODE_KEY = 'cf_omf_form_mode';
  const TYPES = ['text', 'textarea', 'email', 'tel', 'url', 'select', 'radio', 'checkboxes', 'acceptance', 'file'];
  const FORMATS = ['numeric', 'alpha', 'alphanumeric', 'katakana', 'hiragana', 'kana', 'postal_code', 'date'];
  const RESERVED = ['form_data', 'confirm', 'send', 'submit_back', 'mail_id', '_wp_http_referer', 'g-recaptcha-response', 'cf-turnstile-response'];

  public static function mode(int $form_id): string
  {
    return get_post_meta($form_id, self::MODE_KEY, true) === 'builder' ? 'builder' : 'code';
  }

  /** 読み取りで既存メタを移行・変更しない。未知の版は受付を止める。 */
  public static function read(int $form_id): array|\WP_Error
  {
    $schema = apply_filters('omf_field_schema', get_post_meta($form_id, self::META_KEY, true), $form_id);
    if (is_array($schema) && isset($schema['fields']) && is_array($schema['fields'])) {
      foreach ($schema['fields'] as &$field) {
        if (!is_array($field)) { continue; }
        $field['choices'] = apply_filters('omf_field_choices', $field['choices'] ?? [], $field, $form_id);
        $field['default'] = apply_filters('omf_field_default', $field['default'] ?? (in_array($field['type'] ?? '', ['checkboxes']) ? [] : ''), $field, $form_id);
      }
      unset($field);
    }
    return self::normalize($schema);
  }

  /** 呼び出し元でwp_unslash済みの定義を渡す。保存・権限確認は管理画面側の責務。 */
  public static function normalize(mixed $schema): array|\WP_Error
  {
    if (!is_array($schema) || ($schema['version'] ?? null) !== self::VERSION || !isset($schema['fields']) || !is_array($schema['fields']) || !OMF_Utils::is_list($schema['fields']) || count($schema['fields']) > 100 || $schema['fields'] === []) {
      return self::error('フォームの項目定義または版番号が不正です。');
    }
    $fields = []; $keys = [];
    foreach ($schema['fields'] as $index => $field) {
      if (!is_array($field)) { return self::error('項目の形式が不正です。', $index); }
      $key = $field['key'] ?? null; $type = $field['type'] ?? null;
      $captcha_field = (string) get_option('omf_recaptcha_field_name', 'g-recaptcha-response');
      if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || str_starts_with($key, 'omf_') || in_array($key, array_merge(self::RESERVED, [$captcha_field]), true) || in_array($key, $keys, true)) {
        return self::error('項目キーが不正、予約済み、または重複しています。', $index);
      }
      if (!in_array($type, self::TYPES, true) || !is_bool($field['required'] ?? false)) { return self::error('項目型または必須設定が不正です。', $index); }
      foreach (['label', 'description', 'placeholder', 'policy_text'] as $name) {
        if (!is_string($field[$name] ?? '') || mb_strlen($field[$name] ?? '') > 2000) { return self::error('表示文言は2000文字以内の文字列にしてください。', $index); }
      }
      $label = sanitize_textarea_field($field['label'] ?? '');
      if ($label === '') { return self::error('ラベルを入力してください。', $index); }
      $choices = self::choices($field['choices'] ?? [], $type);
      if (is_wp_error($choices)) { return self::error('選択肢の値・ラベルが不正、空欄、または重複しています。', $index); }
      $default = $field['default'] ?? ($type === 'checkboxes' ? [] : '');
      if ($type === 'checkboxes') {
        if (!is_array($default) || !OMF_Utils::is_list($default) || count($default) > 100) { return self::error('複数選択の初期値が不正です。', $index); }
        foreach ($default as $value) {
          if (!is_string($value) || !in_array($value, array_column($choices, 'value'), true)) { return self::error('初期値が選択肢にありません。', $index); }
        }
        $default = array_values(array_unique($default));
      } elseif (!is_string($default) || mb_strlen($default) > 10000) {
        return self::error('初期値は10000文字以内の文字列にしてください。', $index);
      }
      if (in_array($type, ['select', 'radio'], true) && $default !== '' && !in_array($default, array_column($choices, 'value'), true)) { return self::error('初期値が選択肢にありません。', $index); }
      if (in_array($type, ['file', 'acceptance'], true) && $default !== '') { return self::error('添付・同意項目に初期値は設定できません。', $index); }
      $extensions = $field['extensions'] ?? []; $max_bytes = $field['max_bytes'] ?? 10 * MB_IN_BYTES;
      if (!is_array($extensions) || !OMF_Utils::is_list($extensions) || !is_int($max_bytes) || $max_bytes < 1 || $max_bytes > 10 * MB_IN_BYTES) { return self::error('添付の許可拡張子または上限が不正です。', $index); }
      foreach ($extensions as $ext) {
        if (!is_string($ext) || !isset(OMF_Config::ALLOWED_TYPES[$ext]) || in_array($ext, OMF_Config::BLOCKED_FILE_EXTENSIONS, true)) { return self::error('許可できない拡張子です。', $index); }
      }
      if ($type === 'file' && $extensions === []) { return self::error('添付の許可拡張子を指定してください。', $index); }
      $max_length = $field['max_length'] ?? 0;
      if (!is_int($max_length) || $max_length < 0 || $max_length > 100000 || ($max_length && !in_array($type, ['text', 'textarea', 'email', 'tel', 'url'], true))) {
        return self::error('最大文字数の設定が不正です。', $index);
      }
      $min_length = $field['min_length'] ?? 0;
      $format = $field['validation_format'] ?? '';
      if (!is_int($min_length) || $min_length < 0 || $min_length > 100000 || ($max_length && $min_length > $max_length) || !is_string($format) || ($format !== '' && !in_array($format, self::FORMATS, true)) || ($min_length && !in_array($type, ['text', 'textarea', 'email', 'tel', 'url'], true)) || ($format !== '' && !in_array($type, ['text', 'textarea'], true))) {
        return self::error('最小文字数または入力形式の設定が不正です。', $index);
      }
      $address_targets = $field['address_targets'] ?? [];
      if (!is_array($address_targets) || ($address_targets && ($type !== 'text' || $format !== 'postal_code'))) { return self::error('住所自動入力の設定が不正です。', $index); }
      foreach ($address_targets as $role => $target) {
        if (!in_array($role, ['full', 'prefecture', 'city'], true) || !is_string($target) || $target === $key) { return self::error('住所の入力先が不正です。', $index); }
      }
      if (count(array_unique(array_values($address_targets))) !== count($address_targets)) { return self::error('住所の入力先が重複しています。', $index); }
      if (isset($address_targets['full']) && count($address_targets) !== 1) { return self::error('住所1行と分割入力は同時に指定できません。', $index); }
      $required_if = $field['required_if'] ?? null;
      if ($required_if !== null && $required_if !== []) {
        if (!in_array($type, ['text', 'textarea', 'email', 'tel', 'url', 'select'], true) || ($field['required'] ?? false) || !is_array($required_if) || !is_string($required_if['key'] ?? null) || !is_string($required_if['value'] ?? null)) {
          return self::error('条件付き必須の設定が不正です。', $index);
        }
        $required_if = ['key' => $required_if['key'], 'value' => $required_if['value']];
      } else { $required_if = null; }
      $fields[] = [
        'key' => $key, 'type' => $type, 'label' => $label,
        'description' => sanitize_textarea_field($field['description'] ?? ''),
        'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
        'policy_text' => $type === 'acceptance' ? sanitize_textarea_field($field['policy_text'] ?? '') : '',
        'required' => $type === 'acceptance' || ($field['required'] ?? false),
        'choices' => $choices, 'default' => $default,
        'extensions' => $type === 'file' ? array_values(array_unique($extensions)) : [],
        'max_bytes' => $type === 'file' ? $max_bytes : null,
        'max_length' => $max_length, 'min_length' => $min_length, 'validation_format' => $format,
        'required_if' => $required_if, 'address_targets' => $address_targets,
      ];
      $keys[] = $key;
    }
    foreach ($fields as $index => $field) {
      if ($field['required_if'] === null) { continue; }
      $source = null;
      foreach ($fields as $candidate) {
        if ($candidate['key'] === $field['required_if']['key']) { $source = $candidate; break; }
      }
      if (!$source || !in_array($source['type'], ['select', 'radio'], true) || !in_array($field['required_if']['value'], array_column($source['choices'], 'value'), true)) {
        return self::error('条件付き必須の選択肢が見つかりません。', $index);
      }
    }
    foreach ($fields as $index => $field) {
      foreach ($field['address_targets'] as $role => $key) {
        $targets = array_values(array_filter($fields, static fn($candidate) => $candidate['key'] === $key));
        if (!$targets || !in_array($targets[0]['type'], $role === 'prefecture' ? ['text', 'select'] : ['text'], true)) { return self::error('住所自動入力の対象項目が見つからないか、入力形式が不正です。', $index); }
      }
    }
    return ['version' => self::VERSION, 'fields' => $fields];
  }

  private static function choices(mixed $choices, string $type): array|\WP_Error
  {
    if (!in_array($type, ['select', 'radio', 'checkboxes'], true)) { return []; }
    if (!is_array($choices) || !OMF_Utils::is_list($choices) || $choices === [] || count($choices) > 100) { return self::error('選択肢が不正です。'); }
    $result = []; $values = [];
    foreach ($choices as $choice) {
      if (!is_array($choice) || !is_string($choice['value'] ?? null) || !is_string($choice['label'] ?? null)) { return self::error('選択肢が不正です。'); }
      $value = $choice['value']; $label = sanitize_text_field($choice['label']);
      if ($value === '' || $value !== sanitize_text_field($value) || mb_strlen($value) > 200 || $label === '' || mb_strlen($label) > 200 || in_array($value, $values, true)) { return self::error('選択肢が不正です。'); }
      $result[] = ['value' => $value, 'label' => $label]; $values[] = $value;
    }
    return $result;
  }

  /** コード方式のルールは従来のまま。管理画面方式だけ共通定義から変換する。 */
  public static function rules(int $form_id): array|\WP_Error
  {
    if (self::mode($form_id) === 'code') {
      $rules = get_post_meta($form_id, 'cf_omf_validation', true);
      return is_array($rules) ? $rules : [];
    }
    $schema = self::read($form_id);
    if (is_wp_error($schema)) { return $schema; }
    $rules = [];
    foreach ($schema['fields'] as $field) {
      $type = $field['type'];
      $rule = ['target' => $field['key'], 'type' => $type === 'checkboxes' ? 'multiple' : ($type === 'file' ? 'file' : 'text'), 'required' => $field['required'] ? '1' : '0'];
      if (in_array($type, ['email', 'tel', 'url'], true)) { $rule[$type] = '1'; }
      if ($field['choices'] !== []) { $rule['allowed_values'] = array_column($field['choices'], 'value'); }
      if ($type === 'acceptance') { $rule['allowed_values'] = ['1']; }
      if ($type === 'file') { $rule['extension'] = $field['extensions']; $rule['file_size'] = (string) $field['max_bytes']; }
      if ($field['max_length'] > 0) { $rule['max'] = (string) $field['max_length']; }
      $rule['builder_checks'] = self::checks($field);
      $rules[] = $rule;
    }
    return $rules;
  }

  /** PHPとJSが同じ検証定義を使う。正規表現は両エンジンで使える範囲に限定する。 */
  public static function checks(array $field): array
  {
    $patterns = [
      'numeric' => ['[0-9]+', '半角数字で入力してください'],
      'alpha' => ['[a-zA-Z]+', '半角英字で入力してください'],
      'alphanumeric' => ['[a-zA-Z0-9]+', '半角英数字で入力してください'],
      'katakana' => ['[ァ-ヶー \t\r\n　]+', '全角カタカナで入力してください'],
      'hiragana' => ['[ぁ-んー \t\r\n　]+', 'ひらがなで入力してください'],
      'kana' => ['[ァ-ヾぁ-んー]+', '全角カタカナもしくはひらがなで入力してください'],
      'postal_code' => ['[0-9]{3}-?[0-9]{4}', '郵便番号は「123-4567」または「1234567」の形式で入力してください'],
      'date' => ['(?:[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}|[0-9]{4}/[0-9]{1,2}/[0-9]{1,2}|[0-9]{4}年[0-9]{1,2}月[0-9]{1,2}日)(?:（[月火水木金土日]）)?', '実在する日付を入力してください'],
      'tel' => ['0[0-9]{9,10}', '電話番号の形式で入力してください'],
      'email' => ["[a-zA-Z0-9!#$%&'*+/=?^_`{|}~-]+(?:\\.[a-zA-Z0-9!#$%&'*+/=?^_`{|}~-]+)*@[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?)+", '正しいメールアドレスを入力してください'],
      'url' => ['https?://[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?(?::[0-9]{1,5})?(?:[/\\?#][^ \t\r\n]*)?', 'httpまたはhttpsのURLを入力してください'],
    ];
    $format = in_array($field['type'], ['email', 'tel', 'url'], true) ? $field['type'] : ($field['validation_format'] ?? '');
    $checks = ['min' => $field['min_length'] ?? 0, 'max' => $field['max_length'] ?? 0, 'format' => $format];
    if (isset($patterns[$format])) { [$checks['pattern'], $checks['message']] = $patterns[$format]; }
    return $checks;
  }

  public static function validate_checks(string $value, array $checks): array
  {
    $errors = []; $length = mb_strlen($value, 'UTF-8');
    if ($checks['min'] && $length < $checks['min']) { $errors[] = $checks['min'] . '文字以上入力してください'; }
    if ($checks['max'] && $length > $checks['max']) { $errors[] = $checks['max'] . '文字以内で入力してください'; }
    if ($value === '' || empty($checks['pattern'])) { return $errors; }
    $normalized = $checks['format'] === 'tel' ? str_replace('-', '', mb_convert_kana($value, 'n', 'UTF-8')) : $value;
    $valid = preg_match('~^(?:' . str_replace('~', '\\~', $checks['pattern']) . ')$~uD', $normalized) === 1;
    if ($valid && $checks['format'] === 'date') { preg_match_all('/[0-9]+/', $value, $parts); [$year, $month, $day] = array_map('intval', $parts[0]); $valid = checkdate($month, $day, $year); }
    if (!$valid) { $errors[] = $checks['message']; }
    return $errors;
  }

  private static function error(string $message, ?int $index = null): \WP_Error
  {
    return new \WP_Error('omf_invalid_schema', $message, ['field_index' => $index]);
  }
}
