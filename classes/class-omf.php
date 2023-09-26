<?php
class OMF {
  /**
  * エラー取得関数
  * @return array エラーメッセージの配列
  */
  public static function get_errors() {
    return apply_filters('omf_get_errors', []);
  }

  /**
   * 送信データを取得（確認画面用）
   * @return Array 
   */
  public static function get_post_values() {
    return apply_filters('omf_get_post_values', []);
  }

  /**
   * nonceフィールド出力
   * @return String
   */
  public static function nonce_field() {
    return apply_filters('omf_nonce_field', []);
  }

  /**
   * reCAPTCHAフィールドを出力
   * @return String
   */
  public static function recaptcha_field() {
    return apply_filters('omf_recaptcha_field', []);
  }
}