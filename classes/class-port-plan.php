<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 検証済みの取り込み内容を、取り込み先の現在の状態と照合して実行計画にする。
 * 確認画面の表示と実行の両方で、その都度作り直す。
 */
class OMF_Port_Plan
{
  /**
   * 実行計画を作る。
   *
   * @param array $payload OMF_Port_Reader::read() の結果
   * @return array settings / forms / warnings
   */
  public static function build(array $payload): array
  {
    $plan = ['settings' => null, 'forms' => [], 'warnings' => $payload['warnings'], 'source' => $payload['source']];
    if (is_array($payload['settings'])) {
      $plan['settings'] = self::settings($payload['settings']);
    }
    foreach ((array) $payload['forms'] as $form) {
      $entry = self::form($form);
      array_push($plan['warnings'], ...$entry['warnings']);
      $plan['forms'][] = $entry;
    }
    return $plan;
  }

  /**
   * 設定の変更有無。
   *
   * @param array $settings
   * @return array
   */
  private static function settings(array $settings): array
  {
    $changes = [];
    foreach ($settings as $key => $value) {
      $current = get_option($key, null);
      if ($current !== null && (string) $current === $value) {
        continue;
      }
      $changes[$key] = ['old' => (string) $current, 'new' => $value];
    }
    return ['values' => $settings, 'changes' => $changes];
  }

  /**
   * フォーム1件の照合。
   *
   * @param array $form
   * @return array
   */
  private static function form(array $form): array
  {
    $existing = $form['slug'] !== '' ? self::find_form($form['slug']) : null;
    $label = '「' . ($form['slug'] !== '' ? $form['slug'] : $form['title']) . '」';
    $entry = [
      'form' => $form,
      'slug' => $form['slug'],
      'title' => $form['title'],
      'id' => $existing ? (int) $existing->ID : 0,
      'action' => $form['errors'] !== [] ? 'blocked' : ($existing ? 'update' : 'create'),
      'errors' => $form['errors'],
      'parts' => $form['parts'],
      'conditions' => null,
      'links' => null,
      'warnings' => [],
      'removals' => $existing && $form['errors'] === [] ? self::removals((int) $existing->ID, $form['meta'], $form['parts']) : [],
    ];
    if ($entry['action'] === 'blocked') {
      return $entry;
    }
    $missing_parts = array_diff(OMF_Port_Schema::part_keys(), $form['parts']);
    if (!$existing && $missing_parts !== []) {
      $entry['warnings'][] = $label . 'は新規作成で、含まれない項目（' . OMF_Port_Schema::part_labels($missing_parts) . '）は未設定のまま作成されます。';
    }
    if ($form['condition_targets'] !== null) {
      $entry['conditions'] = self::resolve($form['condition_targets']);
      foreach ($entry['conditions']['missing'] as $missing) {
        $entry['warnings'][] = $label . 'の表示条件のページ「' . self::target_text($missing) . '」が見つかりません。';
      }
    }
    if ($form['linked_pages'] !== null) {
      $entry['links'] = self::resolve($form['linked_pages']);
      foreach ($entry['links']['missing'] as $missing) {
        $entry['warnings'][] = $label . 'の連携ページ「' . self::target_text($missing) . '」が見つかりません。';
      }
      foreach ($entry['links']['ids'] as $page_id) {
        $current = (string) get_post_meta($page_id, OMF_Port_Schema::META_SELECT, true);
        if ($current !== '' && $current !== $form['slug']) {
          $entry['warnings'][] = $label . 'の連携ページ（ID ' . $page_id . '）は現在フォーム「' . $current . '」と連携しているため、付け替えます。';
        }
      }
    }
    return $entry;
  }

  /**
   * 上書きで削除する取り込み先のメタ。含まれる項目に属し、ファイルに無い cf_omf_ を削除して移行元と一致させる。
   * 他の項目のメタ、メールID・表示条件ID、メモ・秘密情報は残す。
   *
   * @param int $id
   * @param array $meta ファイルのメタ
   * @param string[] $parts 含まれる項目
   * @return string[]
   */
  private static function removals(int $id, array $meta, array $parts): array
  {
    $keys = [];
    foreach ((array) get_post_custom_keys($id) as $key) {
      if (OMF_Port_Schema::is_part_meta($key, $parts) && !array_key_exists($key, $meta)) {
        $keys[] = $key;
      }
    }
    sort($keys);
    return $keys;
  }

  /**
   * {post_type, path} の配列を取り込み先のIDにする。
   *
   * @param array $targets
   * @return array ids / missing
   */
  private static function resolve(array $targets): array
  {
    $ids = [];
    $missing = [];
    foreach ($targets as $target) {
      $id = OMF_Port_Paths::to_id($target);
      if ($id > 0) {
        $ids[] = $id;
      } else {
        $missing[] = $target;
      }
    }
    return ['ids' => array_values(array_unique($ids)), 'missing' => $missing];
  }

  /**
   * 同じスラッグのフォームを探す。
   *
   * @param string $slug
   * @return \WP_Post|null
   */
  private static function find_form(string $slug): ?\WP_Post
  {
    $posts = get_posts(['post_type' => OMF_Config::NAME, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1]);
    return $posts[0] ?? null;
  }

  /**
   * 警告用の表示文字列。
   *
   * @param array $target
   * @return string
   */
  private static function target_text(array $target): string
  {
    return $target['post_type'] . ':' . $target['path'];
  }
}
