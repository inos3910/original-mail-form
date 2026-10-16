<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 実行計画を取り込み先に書き込む。権限・nonce の確認は呼び出し側の責務。
 */
class OMF_Port_Apply
{
  /**
   * 実行して結果を返す。
   *
   * @param array $plan OMF_Port_Plan::build() の結果
   * @return array created / updated / settings / blocked / warnings
   */
  public static function run(array $plan): array
  {
    $result = ['created' => 0, 'updated' => 0, 'settings' => 0, 'blocked' => [], 'warnings' => $plan['warnings']];
    if (is_array($plan['settings'])) {
      foreach ($plan['settings']['changes'] as $key => $change) {
        update_option($key, $plan['settings']['values'][$key]);
        $result['settings']++;
      }
    }
    foreach ($plan['forms'] as $entry) {
      if ($entry['action'] === 'blocked') {
        $result['blocked'][] = ['slug' => $entry['slug'] !== '' ? $entry['slug'] : $entry['title'], 'errors' => $entry['errors']];
        continue;
      }
      $id = self::save_form($entry);
      if (is_wp_error($id)) {
        $result['blocked'][] = ['slug' => $entry['slug'], 'errors' => [$id->get_error_message()]];
        continue;
      }
      $result[$entry['action'] === 'create' ? 'created' : 'updated']++;
    }
    return $result;
  }

  /**
   * フォーム1件の作成または上書き。IDと cf_omf_mail_id は取り込み先のものを維持し、含まれる項目のうちファイルに無いメタは削除する。
   *
   * @param array $entry
   * @return int|\WP_Error
   */
  private static function save_form(array $entry): int|\WP_Error
  {
    $form = $entry['form'];
    $post = [
      'post_type' => OMF_Config::NAME,
      'post_title' => wp_slash($form['title']),
      'post_name' => $form['slug'],
      'post_status' => $form['status'],
    ];
    if ($entry['action'] === 'create') {
      $id = wp_insert_post($post, true);
    } elseif (in_array('info', $form['parts'], true)) {
      // タイトル・公開状態の更新は「フォーム情報」がある時だけ
      $id = wp_update_post($post + ['ID' => $entry['id']], true);
    } else {
      $id = $entry['id'];
    }
    if (is_wp_error($id)) {
      return $id;
    }
    foreach ($form['meta'] as $key => $value) {
      update_post_meta($id, $key, wp_slash($value));
    }
    foreach ($entry['removals'] as $key) {
      delete_post_meta($id, $key);
    }
    if ($entry['conditions'] !== null) {
      update_post_meta($id, OMF_Port_Schema::META_CONDITION_ID, implode(',', $entry['conditions']['ids']));
    }
    if ($entry['links'] !== null) {
      foreach ($entry['links']['ids'] as $page_id) {
        update_post_meta($page_id, OMF_Port_Schema::META_SELECT, wp_slash($form['slug']));
      }
    }
    return (int) $id;
  }
}
