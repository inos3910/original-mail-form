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
    $form = ['slug' => '', 'title' => '', 'status' => '', 'parts' => [], 'meta' => [], 'condition_targets' => null, 'linked_pages' => null, 'errors' => []];
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
    $form['parts'] = self::parts($item, $label, $warnings, $form['errors']);
    $form['meta'] = OMF_Port_Meta_Reader::read($item['meta'] ?? [], $form['parts'], $label, $warnings, $form['errors']);
    foreach (['condition_targets', 'linked_pages'] as $key) {
      if (!array_key_exists($key, $item)) {
        continue;
      }
      if (in_array('screen', $form['parts'], true)) {
        $form[$key] = self::targets($item[$key], $label . 'の' . $key, $warnings);
      } else {
        $warnings[] = $label . 'の' . $key . 'は「画面設定」が含まれていないため無視しました。';
      }
    }
    return $form;
  }

  /**
   * 取り込む項目（parts）の検証。parts キーが無いファイルは全項目入りとして扱う。未知の値は警告して無視する。
   *
   * @param array $item
   * @param string $label
   * @param array $warnings
   * @param array $errors
   * @return string[]
   */
  private static function parts(array $item, string $label, array &$warnings, array &$errors): array
  {
    if (!array_key_exists('parts', $item)) {
      return OMF_Port_Schema::part_keys();
    }
    if (!is_array($item['parts']) || ($item['parts'] !== [] && !OMF_Utils::is_list($item['parts']))) {
      $errors[] = $label . 'の parts の形式が不正です。';
      return [];
    }
    foreach ($item['parts'] as $part) {
      if (!is_string($part) || !isset(OMF_Port_Schema::PARTS[$part])) {
        $warnings[] = $label . 'の未対応の項目「' . sanitize_text_field(is_scalar($part) ? (string) $part : gettype($part)) . '」は無視しました。';
      }
    }
    $parts = OMF_Port_Schema::normalize_parts($item['parts']);
    if ($parts === [] && $errors === []) {
      $errors[] = $label . 'に取り込める項目がありません。';
    }
    return $parts;
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
