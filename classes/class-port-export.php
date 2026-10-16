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
   * 書き出し内容の配列を作る。秘密情報は一切含めない。
   *
   * @param array $options settings(bool) / forms(int[]|'all'|null) / parts(string[]。省略時は全項目)
   * @return array
   */
  public static function build(array $options): array
  {
    $data = [
      'format' => OMF_Port_Schema::FORMAT,
      'version' => OMF_Port_Schema::VERSION,
      'plugin_version' => self::plugin_version(),
      'exported_at' => wp_date('c'),
      'source_url' => home_url(),
    ];
    if (!empty($options['settings'])) {
      $data['settings'] = self::settings();
    }
    $forms = $options['forms'] ?? null;
    if ($forms === 'all' || (is_array($forms) && $forms !== [])) {
      $parts = OMF_Port_Schema::normalize_parts($options['parts'] ?? OMF_Port_Schema::part_keys());
      $data['forms'] = [];
      foreach (self::form_posts($forms) as $post) {
        $data['forms'][] = self::form($post, $parts);
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
   * 設定（オプション）。認証キーなどの秘密情報は書き出さない。
   *
   * @return array
   */
  private static function settings(): array
  {
    $settings = [];
    foreach (OMF_Port_Schema::GENERAL_OPTIONS as $key) {
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
   * フォーム1件。選ばれた項目（parts）のメタだけを出す。照合と新規作成のため slug・title・status は常に出す。
   *
   * @param \WP_Post $post
   * @param string[] $parts
   * @return array
   */
  private static function form(\WP_Post $post, array $parts): array
  {
    $meta = [];
    foreach ((array) get_post_custom_keys($post->ID) as $key) {
      if (OMF_Port_Schema::is_part_meta($key, $parts)) {
        $meta[$key] = get_post_meta($post->ID, $key, true);
      }
    }
    ksort($meta);
    $form = [
      'slug' => $post->post_name,
      'title' => $post->post_title,
      'status' => $post->post_status,
      'parts' => $parts,
      'meta' => $meta,
    ];
    if (in_array('screen', $parts, true)) {
      $form['condition_targets'] = OMF_Port_Paths::ids_to_targets(get_post_meta($post->ID, OMF_Port_Schema::META_CONDITION_ID, true));
      $form['linked_pages'] = OMF_Port_Paths::linked_targets($post->post_name);
    }
    return $form;
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
