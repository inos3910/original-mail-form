<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 書き出し画面のフォーム入力を、OMF_Port_Export::build() の引数にする。
 * サーバー側の検証が正で、JS の表示状態には依存しない。
 */
class OMF_Port_Request
{
  /**
   * 書き出す範囲（scope）に応じて引数を作る。選択が足りなければ WP_Error。
   *
   * @param array $post $_POST 相当（スラッシュ付き）
   * @return array|\WP_Error settings / forms / parts
   */
  public static function export_options(array $post): array|\WP_Error
  {
    $raw_scope = $post['scope'] ?? '';
    $scope = is_string($raw_scope) ? sanitize_key(wp_unslash($raw_scope)) : '';
    if ($scope === 'all') {
      return ['settings' => true, 'forms' => 'all', 'parts' => OMF_Port_Schema::part_keys()];
    }
    if ($scope === 'settings') {
      return ['settings' => true, 'forms' => null, 'parts' => []];
    }
    if ($scope !== 'forms') {
      return new \WP_Error('omf_port_scope', '書き出す範囲を選んでください。');
    }
    $ids = self::scalars($post['form_ids'] ?? []);
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if ($ids === []) {
      return new \WP_Error('omf_port_forms', '書き出すフォームを選んでください。');
    }
    $parts = OMF_Port_Schema::normalize_parts(array_map('sanitize_key', array_map('strval', self::scalars($post['parts'] ?? []))));
    if ($parts === []) {
      return new \WP_Error('omf_port_parts', '書き出す項目を1つ以上選んでください。');
    }
    return ['settings' => !empty($post['include_settings']), 'forms' => $ids, 'parts' => $parts];
  }

  /**
   * 配列のうちスカラー値だけを取り出す。配列でなければ空配列。
   *
   * @param mixed $value
   * @return array
   */
  private static function scalars(mixed $value): array
  {
    return is_array($value) ? array_values(array_filter(wp_unslash($value), 'is_scalar')) : [];
  }
}
