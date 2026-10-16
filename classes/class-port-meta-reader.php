<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポートファイルのフォームのメタを検証する。
 */
class OMF_Port_Meta_Reader
{
  /**
   * メタの検証。cf_omf_ 以外・取り込み先で決めるキー・メモや秘密情報・宣言された項目に属さないキーは捨てる。項目定義は既存の検証を通す。
   *
   * @param mixed $raw
   * @param string[] $parts 宣言された項目
   * @param string $label
   * @param array $warnings
   * @param array $errors
   * @return array
   */
  public static function read(mixed $raw, array $parts, string $label, array &$warnings, array &$errors): array
  {
    if (!is_array($raw) || ($raw !== [] && OMF_Utils::is_list($raw))) {
      $errors[] = $label . 'のメタの形式が不正です。';
      return [];
    }
    $meta = [];
    foreach ($raw as $key => $value) {
      $key = (string) $key;
      if (!preg_match('/^cf_omf_[a-z0-9_]{1,100}$/D', $key)) {
        $warnings[] = $label . 'の未対応のメタ「' . sanitize_text_field($key) . '」は無視しました。';
      } elseif (in_array($key, OMF_Port_Schema::managed_meta_keys(), true)) {
        $warnings[] = $label . 'のメタ「' . $key . '」は取り込み先の値を使うため無視しました。';
      } elseif (in_array($key, OMF_Port_Schema::secret_meta_keys(), true)) {
        $warnings[] = $label . 'の秘密情報「' . $key . '」は取り込まず、取り込み先の値を残しました。';
      } elseif (in_array($key, OMF_Port_Schema::excluded_meta_keys(), true)) {
        $warnings[] = $label . 'のメタ「' . $key . '」は取り込み対象外のため無視しました。';
      } elseif (!in_array(OMF_Port_Schema::part_of($key), $parts, true)) {
        $warnings[] = $label . 'のメタ「' . $key . '」は含まれる項目に属さないため無視しました。';
      } elseif (!self::is_plain_value($value, 0)) {
        $errors[] = $label . 'のメタ「' . $key . '」の値が不正です。';
      } else {
        $meta[$key] = $value;
      }
    }
    if (isset($meta[OMF_Field_Schema::META_KEY]) && $meta[OMF_Field_Schema::META_KEY] !== '') {
      $checked = OMF_Field_Schema::normalize($meta[OMF_Field_Schema::META_KEY]);
      if (is_wp_error($checked)) {
        $detail = $checked->get_error_data();
        $position = isset($detail['field_index']) ? '項目' . ($detail['field_index'] + 1) . '：' : '';
        $errors[] = $label . 'の項目定義が不正です（' . $position . $checked->get_error_message() . '）';
      }
    }
    return $meta;
  }

  /**
   * 文字列・数値・真偽値・null・それらの配列だけを許す。
   *
   * @param mixed $value
   * @param int $depth
   * @return bool
   */
  private static function is_plain_value(mixed $value, int $depth): bool
  {
    if (is_scalar($value) || $value === null) {
      return true;
    }
    if (!is_array($value) || $depth >= 8) {
      return false;
    }
    foreach ($value as $child) {
      if (!self::is_plain_value($child, $depth + 1)) {
        return false;
      }
    }
    return true;
  }
}
