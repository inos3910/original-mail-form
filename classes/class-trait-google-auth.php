<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}


trait OMF_Trait_Google_Auth
{
  use OMF_Trait_Cryptor;

  /**
   * Google連携に必要な情報を取得する
   * @return array
   */
  private function get_google_settings_values(): array
  {
    $client_id     = get_option('omf_google_client_id');
    $client_secret = get_option('omf_google_client_secret');
    $redirect_uri  = admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings');
    $access_token  = $this->decrypt_secret(get_option('_omf_google_access_token'), 'access_token');
    $refresh_token  = $this->decrypt_secret(get_option('_omf_google_refresh_token'), 'refresh_token');

    //アクセストークンの有効フラグ
    $is_access_token = $this->is_valid_access_token($access_token, $client_id);
    //アクセストークンが期限切れの場合
    if (!$is_access_token) {
      //リフレッシュトークンで再取得
      $this->refresh_google_access_token($client_id, $client_secret, $refresh_token);
      $access_token  = $this->decrypt_secret(get_option('_omf_google_access_token'), 'access_token');
      $refresh_token  = $this->decrypt_secret(get_option('_omf_google_refresh_token'), 'refresh_token');
    }

    return [
      'client_id'     => $client_id,
      'client_secret' => $client_secret,
      'redirect_uri'  => $redirect_uri,
      'access_token'  => $access_token,
      'refresh_token' => $refresh_token
    ];
  }

  /**
   * アクセストークンの有効チェック
   *
   * @param string $access_token
   * @param string $client_id
   * @return boolean
   */
  private function is_valid_access_token(string $access_token, string $client_id): bool
  {
    if (empty($access_token)) {
      return false;
    }

    //アクセストークンの有効性チェック
    $_response = OMF_Utils::curl_get("https://oauth2.googleapis.com/tokeninfo?access_token={$access_token}");
    if (empty($_response) || is_wp_error($_response)) {
      return false;
    }

    $response = json_decode($_response, true);
    return !empty($response["azp"]) && ($response["azp"] === $client_id);
  }

  /**
   * リフレッシュトークンでアクセストークンを再取得
   *
   * @param string $client_id
   * @param string $client_secret
   * @param string $refresh_token
   * @return boolean|array
   */
  private function refresh_google_access_token(string $client_id, string $client_secret, string $refresh_token): bool|array
  {
    $url = 'https://oauth2.googleapis.com/token';

    $post_data = [
      'client_id'     => $client_id,
      'client_secret' => $client_secret,
      'refresh_token' => $refresh_token,
      'grant_type'    => 'refresh_token'

    ];

    $response = OMF_Utils::curl_post($url, $post_data);
    if (empty($response) || is_wp_error($response)) {
      $this->save_google_tokens([
        'access_token'  => '',
        'refresh_token' => ''
      ]);
      return false;
    }

    $response_data = json_decode($response, true);

    $tokens = [
      'access_token'  => !empty($response_data['access_token']) ? $response_data['access_token'] : '',
      'refresh_token' => $refresh_token
    ];

    //DB更新
    $this->save_google_tokens($tokens);

    return $tokens;
  }

  /**
   * Google OAuth認証後のアクセストークンとリフレッシュトークンを取得する関数
   *
   * @param string $client_id
   * @param string $client_secret
   * @param string $redirect_uri
   * @param string $code
   * @return boolean|array
   */
  private function fetch_google_access_token(string $client_id, string $client_secret, string $redirect_uri, string $code): bool|array
  {
    $url = 'https://oauth2.googleapis.com/token';

    $post_data = [
      'code'          => $code,
      'client_id'     => $client_id,
      'client_secret' => $client_secret,
      'redirect_uri'  => $redirect_uri,
      'grant_type'    => 'authorization_code'
    ];

    $response = OMF_Utils::curl_post($url, $post_data);
    if (empty($response) || is_wp_error($response)) {
      return false;
    }

    $response_data = json_decode($response, true);

    return [
      'expires_in'    => $response_data['expires_in'],
      'access_token'  => $response_data['access_token'],
      'refresh_token' => $response_data['refresh_token']
    ];
  }

  /**
   * OAuth認証リダイレクト時にトークン生成・保存
   *
   * @param string $client_id
   * @param string $client_secret
   * @param string $redirect_uri
   * @return array
   */
  private function set_tokens(string $client_id, string $client_secret, string $redirect_uri): array
  {
    $tokens = $this->fetch_google_access_token($client_id, $client_secret, $redirect_uri, $_GET['code']);
    if (!empty($tokens)) {
      $this->save_google_tokens($tokens);
    }

    return $tokens;
  }

  /**
   * トークンをデータベースに保存
   *
   * @param array $tokens
   * @return void
   */
  private function save_google_tokens(array $tokens)
  {
    if (!empty($tokens)) {
      update_option('_omf_google_access_token', $this->encrypt_secret($tokens['access_token'], 'access_token'), 'no');
      update_option('_omf_google_refresh_token', $this->encrypt_secret($tokens['refresh_token'], 'refresh_token'), 'no');
    }
  }

  /**
   * トークンをデータベースから削除
   *
   * @return void
   */
  private function remove_google_tokens()
  {
    delete_option('_omf_google_access_token');
    delete_option('_omf_google_refresh_token');
  }

  /**
   * Google OAuth 2.0の認証用URLを生成
   *
   * @param string $client_id
   * @param string $redirect_uri
   * @param string $scope
   * @param string $access_type
   * @param string $approval_prompt
   * @return string
   */
  private function get_google_auth_url(string $client_id, string $redirect_uri, string $scope = 'https://www.googleapis.com/auth/spreadsheets', string $access_type = 'offline', string $approval_prompt = 'force'): string
  {
    $base_url = 'https://accounts.google.com/o/oauth2/v2/auth';
    $params = [
      'client_id'       => $client_id,
      'redirect_uri'    => $redirect_uri,
      'response_type'   => 'code',
      'scope'           => $scope,
      'access_type'     => $access_type,
      'approval_prompt' => $approval_prompt,
    ];

    $auth_url = $base_url . '?' . http_build_query($params);

    return $auth_url;
  }
}
