<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポートファイルのフォーム部分を検証する。
 * 取り込めないフォームは errors に理由を持たせて残し、実行時に丸ごと中止する。
 */
class OMF_Port_Form_Reader
{
  /**
   * フォーム一覧の検証。
   *
   * @param mixed $raw
   * @param array $warnings
   * @return array
   */
  public static function read_all(mixed $raw, array &$warnings): array
  {
    if (!is_array($raw) || ($raw !== [] && !OMF_Utils::is_list($raw))) {
      $warnings[] = 'フォームの形式が不正なため、フォームは取り込みません。';
      return [];
    }
    $forms = [];
    $slugs = [];
    foreach ($raw as $index => $item) {
      $form = self::read_one($item, $index + 1, $warnings);
      if ($form['slug'] !== '' && in_array($form['slug'], $slugs, true)) {
        $form['errors'][] = '同じスラッグ「' . $form['slug'] . '」のフォームがファイル内に複数あります。';
      }
      $slugs[] = $form['slug'];
      $forms[] = $form;
    }
    return $forms;
  }

  /**
   * フォーム1件の検証。
   *
   * @param mixed $item
   * @param int $number
   * @param array $warnings
   * @return array
   */
  private static function read_one(mixed $item, int $number, array &$warnings): array
  {
    $form = ['slug' => '', 'title' => '', 'status' => '', 'meta' => [], 'condition_targets' => null, 'linked_pages' => null, 'errors' => []];
    if (!is_array($item)) {
      $form['errors'][] = $number . '件目のフォームの形式が不正です。';
      return $form;
    }
    $slug = $item['slug'] ?? null;
    if (is_string($slug) && $slug !== '' && sanitize_title($slug) === $slug) {
      $form['slug'] = $slug;
    } else {
      $form['errors'][] = $number . '件目のフォームのスラッグが不正です。';
    }
    $label = $form['slug'] !== '' ? '「' . $form['slug'] . '」' : $number . '件目';
    $title = $item['title'] ?? null;
    if (is_string($title) && trim($title) !== '') {
      $form['title'] = sanitize_text_field($title);
    } else {
      $form['errors'][] = $label . 'のフォーム名が不正です。';
    }
    $status = $item['status'] ?? null;
    if (is_string($status) && in_array($status, OMF_Port_Schema::FORM_STATUSES, true)) {
      $form['status'] = $status;
    } else {
      $form['errors'][] = $label . 'のステータスが不正です。';
    }
    $form['meta'] = self::meta($item['meta'] ?? [], $label, $warnings, $form['errors']);
    foreach (['condition_targets', 'linked_pages'] as $key) {
      if (array_key_exists($key, $item)) {
        $form[$key] = self::targets($item[$key], $label . 'の' . $key, $warnings);
      }
    }
    return $form;
  }

  /**
   * メタの検証。cf_omf_ 以外・取り込み先で決めるキーは捨てる。項目定義は既存の検証を通す。
   *
   * @param mixed $raw
   * @param string $label
   * @param array $warnings
   * @param array $errors
   * @return array
   */
  private static function meta(mixed $raw, string $label, array &$warnings, array &$errors): array
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

  /**
   * {post_type, path} の配列の検証。形式が不正な項目は警告して捨てる。
   *
   * @param mixed $raw
   * @param string $label
   * @param array $warnings
   * @return array
   */
  private static function targets(mixed $raw, string $label, array &$warnings): array
  {
    if (!is_array($raw) || ($raw !== [] && !OMF_Utils::is_list($raw))) {
      $warnings[] = $label . 'の形式が不正なため無視しました。';
      return [];
    }
    $targets = [];
    foreach ($raw as $item) {
      if (is_array($item) && is_string($item['post_type'] ?? null) && is_string($item['path'] ?? null) && $item['post_type'] !== '' && $item['path'] !== '') {
        $targets[] = ['post_type' => sanitize_key($item['post_type']), 'path' => $item['path']];
      } else {
        $warnings[] = $label . 'に形式が不正な項目があり、無視しました。';
      }
    }
    return $targets;
  }
}
