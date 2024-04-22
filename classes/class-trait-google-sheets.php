<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Trait_Google_Sheets
{
  use OMF_Trait_Cryptor, OMF_Trait_Google_Auth;


  /**
   *
   *
   * @param integer|string|null $form_id
   * @return boolean
   */
  private function is_google_sheets(int|string|null $form_id): bool
  {
    return get_post_meta($form_id, 'cf_omf_is_google_sheets', true) === '1';
  }

  /**
   * スプレッドシートに送信内容書き込み
   *
   * @param WP_Post $form
   * @param array $data
   * @return array
   */
  private function get_google_sheets_params(WP_Post $form, array $data_to_save): array
  {
    if (empty($form) || empty($data_to_save)) {
      return [];
    }

    $is_google_sheets = $this->is_google_sheets($form->ID);
    if (!$is_google_sheets) {
      return [];
    }

    $access_token = $this->get_google_access_token();
    if (empty($access_token)) {
      return [];
    }

    $sheet_id = get_post_meta($form->ID, 'cf_omf_google_sheets_id', true);
    $sheet_name = get_post_meta($form->ID, 'cf_omf_google_sheets_name', true);
    if (empty($sheet_id) || empty($sheet_name)) {
      return [];
    }

    //書き込み処理情報を取得
    return $this->get_write_to_google_sheets_params($access_token, $sheet_id, $sheet_name, $data_to_save);
  }

  /**
   * Google連携アクセストークンの取得
   *
   * @return string
   */
  private function get_google_access_token(): string
  {
    $access_token  = $this->decrypt_secret(get_option('_omf_google_access_token'), 'access_token');
    $client_id = get_option('omf_google_client_id');
    $is_access_token = $this->is_valid_access_token($access_token, $client_id);
    //アクセストークンが期限切れの場合
    if (!$is_access_token) {
      //リフレッシュトークンで再取得
      $client_secret = get_option('omf_google_client_secret');
      $refresh_token = $this->decrypt_secret(get_option('_omf_google_refresh_token'), 'refresh_token');
      $this->refresh_google_access_token($client_id, $client_secret, $refresh_token);
      $access_token  = $this->decrypt_secret(get_option('_omf_google_access_token'), 'access_token');
    }

    return $access_token;
  }

  /**
   * Google Sheets APIでスプレッドシートに書き込む情報を取得する　
   *
   * @param string $access_token
   * @param string $sheet_id
   * @param string $sheet_name
   * @param array $values
   * @return void
   */
  private function get_write_to_google_sheets_params(string $access_token, string $sheet_id, string $sheet_name, array $values)
  {
    if (
      empty($access_token) ||
      empty($sheet_id) ||
      empty($sheet_name) ||
      empty($values)
    ) {
      return [];
    }

    //シートのデータを取得
    $response = $this->get_google_sheets($access_token, $sheet_id, $sheet_name);
    if (empty($response) || is_wp_error($response)) {
      return [];
    }

    //取得したシートの最終行の次の行に書き込む
    $response_data = json_decode($response, true);
    $row_count = !empty($response_data['values']) ? count($response_data['values']) + 1 : 1;
    return $this->get_update_google_sheets_request_params($access_token, $sheet_id, $sheet_name, $values, $row_count);
  }

  /**
   * スプレッドシートにデータを書き込む情報を取得
   *
   * @param string $access_token
   * @param string $sheet_id
   * @param string $sheet_name
   * @param array $data
   * @param integer $row_count 書き込む行数
   * @return mixed
   */
  private function get_update_google_sheets_request_params(string $access_token, string $sheet_id, string $sheet_name, array $data, int $row_count): mixed
  {
    if (
      empty($access_token) ||
      empty($sheet_id) ||
      empty($sheet_name) ||
      empty($data) ||
      empty($row_count)
    ) {
      return [];
    }

    $range        = "{$sheet_name}!A{$row_count}";
    $endpoint     = sprintf('https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?valueInputOption=RAW', $sheet_id, $range);
    $request_body = ['values' => [array_values($data)]];
    $header       = [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $access_token,
    ];

    // $response = OMF_Utils::curl_post($endpoint, $request_body, $header, 'PUT');
    // return $response;

    return [
      'url'       => $endpoint,
      'post_data' => $request_body,
      'header'    => $header,
      'method'    => 'PUT'
    ];
  }

  /**
   * スプレッドシートのデータを取得
   *
   * @param string $access_token
   * @param string $sheet_id
   * @param string $sheet_name
   * @return mixed
   */
  private function get_google_sheets(string $access_token, string $sheet_id, string $sheet_name): mixed
  {
    if (
      empty($access_token) ||
      empty($sheet_id) ||
      empty($sheet_name)
    ) {
      return [];
    }

    //データが入っている範囲を取得
    $range    = "{$sheet_name}!A:B";
    $endpoint = sprintf('https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s', $sheet_id, $range);
    $response = OMF_Utils::curl_get($endpoint, [
      'Authorization: Bearer ' . $access_token,
    ]);
    return $response;
  }
}
