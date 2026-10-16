<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 設定とフォームをエクスポート用の配列・JSONにする。送信データは含めない。
 */
class OMF_Port_Export
{
  /**
   * 書き出し内容の配列を作る。
   *
   * @param array $options settings(bool) / forms(int[]|'all'|null) / secrets(bool)
   * @return array
   */
  public static function build(array $options): array
  {
    $secrets = !empty($options['secrets']);
    $data = [
      'format' => OMF_Port_Schema::FORMAT,
      'version' => OMF_Port_Schema::VERSION,
      'plugin_version' => self::plugin_version(),
      'exported_at' => wp_date('c'),
      'source_url' => home_url(),
      'includes_secrets' => $secrets,
    ];
    if (!empty($options['settings'])) {
      $data['settings'] = self::settings($secrets);
    }
    $forms = $options['forms'] ?? null;
    if ($forms === 'all' || (is_array($forms) && $forms !== [])) {
      $data['forms'] = [];
      foreach (self::form_posts($forms) as $post) {
        $data['forms'][] = self::form($post, $secrets);
      }
    }
    return $data;
  }

  /**
   * JSON文字列にする。
   *
   * @param array $data
   * @return string
   */
  public static function to_json(array $data): string
  {
    return (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * ダウンロードするファイル名。
   *
   * @return string
   */
  public static function file_name(): string
  {
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $site = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');
    return 'omf-export-' . ($site !== '' ? $site . '-' : '') . wp_date('Ymd-His') . '.json';
  }

  /**
   * 設定（オプション）。認証キーは秘密情報を含める時だけ、組で書き出す。
   *
   * @param bool $secrets
   * @return array
   */
  private static function settings(bool $secrets): array
  {
    $keys = OMF_Port_Schema::GENERAL_OPTIONS;
    if ($secrets) {
      foreach (OMF_Port_Schema::SECRET_OPTION_PAIRS as $pair) {
        if (get_option($pair[0], null) !== null && get_option($pair[1], null) !== null) {
          array_push($keys, ...$pair);
        }
      }
    }
    $settings = [];
    foreach ($keys as $key) {
      $value = get_option($key, null);
      if ($value !== null) {
        $settings[$key] = (string) $value;
      }
    }
    return $settings;
  }

  /**
   * 書き出すフォーム投稿。
   *
   * @param mixed $selection 'all' または投稿IDの配列
   * @return array
   */
  private static function form_posts(mixed $selection): array
  {
    $args = [
      'post_type' => OMF_Config::NAME,
      'post_status' => OMF_Port_Schema::FORM_STATUSES,
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
    ];
    if (is_array($selection)) {
      $args['post__in'] = array_map('intval', $selection);
    }
    return get_posts($args);
  }

  /**
   * フォーム1件。
   *
   * @param \WP_Post $post
   * @param bool $secrets
   * @return array
   */
  private static function form(\WP_Post $post, bool $secrets): array
  {
    $meta = [];
    foreach ((array) get_post_custom_keys($post->ID) as $key) {
      if (!str_starts_with($key, OMF_Port_Schema::META_PREFIX) || in_array($key, OMF_Port_Schema::managed_meta_keys(), true)) {
        continue;
      }
      if (!$secrets && in_array($key, OMF_Port_Schema::secret_meta_keys(), true)) {
        continue;
      }
      $meta[$key] = get_post_meta($post->ID, $key, true);
    }
    ksort($meta);
    return [
      'slug' => $post->post_name,
      'title' => $post->post_title,
      'status' => $post->post_status,
      'meta' => $meta,
      'condition_targets' => OMF_Port_Paths::ids_to_targets(get_post_meta($post->ID, OMF_Port_Schema::META_CONDITION_ID, true)),
      'linked_pages' => OMF_Port_Paths::linked_targets($post->post_name),
    ];
  }

  /**
   * プラグインのバージョン。
   *
   * @return string
   */
  private static function plugin_version(): string
  {
    $header = get_file_data(plugin_dir_path(__DIR__) . 'original-mail-form.php', ['version' => 'Version']);
    return (string) ($header['version'] ?? '');
  }
}
