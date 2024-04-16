<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Error;

class OMF_Utils
{
  /**
   * 本番環境判定
   *
   * @return boolean
   */
  public static function is_production(): bool
  {
    return (defined('WP_ENV') && WP_ENV === 'production');
  }

  /**
   * curlでデータ取得する関数
   * @param  string  $url
   * @param array $header
   * @param  int $timeout タイムアウト（秒）
   * @return mixed
   */
  public static function curl_get(string $url, array $header = [], int $timeout = 60): mixed
  {
    $ch = curl_init();

    if (!empty($header) && is_array($header)) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, self::is_production());
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $result = curl_exec($ch);

    //エラー
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    if (CURLE_OK !== $errno) {
      return new WP_Error('curl_error', __($error), ['status' => $errno]);
    }

    curl_close($ch);
    return $result;
  }


  /**
   * curlでPOST送信
   * @param string $url
   * @param array $post_data
   * @param array $header
   * @param string $method
   * @param int $timeout
   * @return mixed
   **/
  public static function curl_post(string $url, array $post_data, array $header = [], string $method = 'POST', int $timeout = 60): mixed
  {
    // 送信データをURLエンコード
    $data = wp_json_encode($post_data);

    $ch = curl_init();

    $header = !empty($header) && is_array($header) ? $header : [
      "Content-Type: application/json",
      'Cache-Control: no-cache',
      'Pragma: no-cache'
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, self::is_production());
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $result = curl_exec($ch);

    //エラー
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    if (CURLE_OK !== $errno) {
      return new WP_Error('curl_error', __($error), ['status' => $errno]);
    }

    curl_close($ch);
    return $result;
  }

  /**
   * エスケープ処理
   * @param  string $input
   * @param  boolean $is_text_field 改行を含むテキストフィールドの場合
   * @return string
   */
  public static function custom_escape(string $input, bool $is_text_field = false): string
  {
    //空の場合は空文字を返す
    if (empty($input)) {
      return '';
    }

    //テキストフィールドフラグがある場合
    if ($is_text_field) {
      $sanitized = sanitize_textarea_field(wp_unslash($input));
    }
    //フラグがない場合
    else {
      //改行を含む場合
      if (preg_match("/\n|\r\n/", $input)) {
        $sanitized = sanitize_textarea_field(wp_unslash($input));
      }
      //含まない場合
      else {
        $sanitized = sanitize_text_field(wp_unslash($input));
      }
    }

    return $sanitized;
  }

  /**
   * 日時取得
   * @return string
   */
  public static function get_current_datetime(): string
  {
    $w              = wp_date("w");
    $week_name      = ["日", "月", "火", "水", "木", "金", "土"];
    $send_datetime  = wp_date("Y/m/d ({$week_name[$w]}) H:i");
    return $send_datetime;
  }

  /**
   * 指定したキーの次に追加する
   *
   * @param array $array
   * @param string $key
   * @param mixed $value
   * @param string $new_key
   * @return array
   */
  public static function add_after_key(array $array, string $key, mixed $value, string $new_key): array
  {
    $keys = array_keys($array);
    $key_index = array_search($key, $keys);
    $new_keys = array_merge(
      array_slice($keys, 0, $key_index + 1),
      array($new_key),
      array_slice($keys, $key_index + 1)
    );

    $new_values = array_merge(
      array_slice($array, 0, $key_index + 1),
      array($value),
      array_slice($array, $key_index + 1)
    );

    return array_combine($new_keys, $new_values);
  }
}
