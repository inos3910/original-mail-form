<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

trait OMF_Trait_Output_Csv
{
  /**
   *  CSV出力
   *
   * @return void
   */
  public function output_csv()
  {
    $error_slug    = 'omf_output_csv_notices';
    $error_code    = 'omf_notice';
    $error_message = 'エラーのため出力失敗しました';
    $error_type    = 'error';


    if (!isset($_POST['output_csv'])) {
      return;
    }

    // CSRF対策
    check_admin_referer('omf_output_csv_action', 'omf_output_csv_nonce');

    // 権限チェック
    if (!current_user_can('edit_others_posts')) {
      wp_die('権限がありません。');
    }

    $data_id = sanitize_text_field($_POST['omf_data_id'] ?? '');
    $period  = sanitize_text_field($_POST['omf_output_period'] ?? '');
    if ($period === 'option') {
      $start_date  = sanitize_text_field($_POST['omf_output_start'] ?? '');
      $end_date    = sanitize_text_field($_POST['omf_output_end'] ?? '');
    } else {
      $start_date = $period;
      $end_date   = wp_date('Y-m-d');
    }

    if (!$data_id || !$start_date || !$end_date) {
      $error_message = '必要なデータが不足しています。';
      add_settings_error($error_slug, $error_code, $error_message, $error_type);
      settings_errors($error_slug);
      return;
    }

    $fields_to_include = !empty($_POST['omf_data_key']) ? OMF_Utils::custom_escape($_POST['omf_data_key']) : [];

    $csv_data = $this->get_csv_data($data_id, $start_date, $end_date, $fields_to_include);
    if (empty($csv_data)) {
      $error_message = 'データがありません。';
      add_settings_error($error_slug, $error_code, $error_message, $error_type);
      settings_errors($error_slug);
      return;
    }

    $form_id    = $this->get_form_id_by_data_post_type($data_id);
    $form       = get_post($form_id);
    $form_title = !empty($form) ? $form->post_title : 'form_data';

    $file_name = "{$form_title}_export_{$start_date}_to_{$end_date}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file_name . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // UTF-8 BOMを追加（Excel用の対策）
    fwrite($output, "\xEF\xBB\xBF");

    foreach ($csv_data as $row) {
      fputcsv($output, $row);
    }

    fclose($output);
    exit;
  }

  /**
   * CSV出力するフォームの送信データを期間指定して取得
   *
   * @param string $post_type
   * @param string $start_date
   * @param string $end_date
   * @param array $fields_to_include 出力するカスタムフィールドのキー
   * @return array
   */
  private function get_csv_data(string $post_type, string $start_date, string $end_date, array $fields_to_include = []): array
  {
    $csv_data = [];
    $args = [
      'post_type'      => $post_type,
      'posts_per_page' => -1, // 全件取得
      'post_status'    => 'publish',
      'date_query'     => [
        [
          'after'     => $start_date,
          'before'    => $end_date,
          'inclusive' => true,
        ],
      ],
    ];
    $posts = get_posts($args);
    if (empty($posts)) {
      return $csv_data;
    }

    // カスタムフィールドキーの収集（$fields_to_includeが指定されていなければすべてを含む）
    $all_field_keys = [];
    foreach ($posts as $post) {
      $all_fields = get_post_custom($post->ID);
      $form_slug  = $this->get_form_slug_by_data_post_id($post->ID);
      $fields     = $this->filter_hidden_custom_field($all_fields);
      $fields     = $this->filter_omf_field($fields);
      foreach (array_keys($fields) as $key) {
        if ($key === 'cf_omf_data_memo') {
          continue;
        }

        // ここでキーをフィルタ適用前に絞り込む
        if (!empty($fields_to_include) && !in_array($key, $fields_to_include, true)) {
          continue;
        }

        $field_key = apply_filters('omf_data_custom_field_key_' . $form_slug, $key);
        $field_key = $this->replace_custom_field_default_key($field_key);
        $all_field_keys[$field_key] = true;
      }
    }

    $header = array_keys($all_field_keys);
    $csv_data[] = $header;

    foreach ($posts as $post) {
      $all_fields = get_post_custom($post->ID);
      $form_slug  = $this->get_form_slug_by_data_post_id($post->ID);
      $fields     = $this->filter_hidden_custom_field($all_fields);
      $fields     = $this->filter_omf_field($fields);
      if (empty($fields)) {
        continue;
      }

      $data = array_fill(0, count($header), ''); // 初期化
      foreach ((array)$fields as $key => $value) {
        if ($key === 'cf_omf_data_memo') {
          continue;
        }

        // ここでキーをフィルタ適用前に絞り込む
        if (!empty($fields_to_include) && !in_array($key, $fields_to_include, true)) {
          continue;
        }

        $field_key = apply_filters('omf_data_custom_field_key_' . $form_slug, $key);
        $field_key = $this->replace_custom_field_default_key($field_key);

        if (isset($all_field_keys[$field_key])) {
          $index = array_search($field_key, $header);
          foreach ((array)$value as $val) {
            $unserialized = maybe_unserialize($val);
            $formatted_value = is_array($unserialized) && !empty($unserialized['name']) ? $unserialized['name'] : $unserialized;

            // 電話番号の形式（10桁または11桁の数字のみ）なら="09000000000"形式に変換
            if (preg_match('/^\d{10,11}$/', $formatted_value)) {
              $formatted_value = '="' . $formatted_value . '"';
            }

            $data[$index] = $formatted_value;
          }
        }
      }

      $csv_data[] = $data;
    }

    return $csv_data;
  }


  /**
   * フォームのDB保存データのすべてのキーを取得
   *
   * @param string $post_type
   * @return array
   */
  private function get_all_saved_data_keys($post_type): array
  {
    $keys = [];
    $args = [
      'post_type'      => $post_type,
      'posts_per_page' => -1,
      'post_status'    => 'publish',
    ];
    $posts = get_posts($args);
    if (empty($posts)) {
      return $keys;
    }

    // すべての投稿のカスタムフィールドキーを集める
    foreach ($posts as $post) {
      $all_fields = get_post_custom($post->ID);
      $form_slug  = $this->get_form_slug_by_data_post_id($post->ID);
      $fields     = $this->filter_hidden_custom_field($all_fields);
      $fields     = $this->filter_omf_field($fields);

      foreach (array_keys($fields) as $key) {
        // メモはスキップ
        if ($key === 'cf_omf_data_memo') {
          continue;
        }

        $key_named  = $this->replace_custom_field_default_key($key);
        $key_named  = apply_filters('omf_data_custom_field_key_' . $form_slug, $key_named);

        $keys[$key] = $key_named;
      }
    }

    return $keys;
  }
}
