<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use Closure;
use WP_Post;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


class OMF_Rest
{
  use OMF_Trait_Send, OMF_Trait_Validation;

  public function __construct()
  {
    //REST API
    add_action('rest_api_init', [$this, 'add_custom_endpoint']);
  }

  /**
   * REST APIエンドポイント追加
   *
   * @return void
   */
  public function add_custom_endpoint()
  {
    //REST APIが無効の場合は終了
    $is_rest_api = get_option('omf_is_rest_api') === '1';
    if (!$is_rest_api) {
      return;
    }

    //バリデーションのみ
    register_rest_route(
      'omf-api/v0',
      '/validate',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => [$this, 'rest_permission'],
        'callback'            => [$this, 'rest_api_validate']
      ]
    );

    //送信
    register_rest_route(
      'omf-api/v0',
      '/send',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => [$this, 'rest_permission'],
        'callback'            => [$this, 'rest_api_send']
      ]
    );
  }


  /**
   * REST API 送信データのバリデーション
   *
   * @param WP_REST_Request $param
   * @return WP_REST_Response
   */
  public function rest_api_validate(WP_REST_Request $params)
  {
    if ($this->is_valid_nonce()) { OMF_Utils::start_session(); }
    return $this->rest_response(function () use ($params) {

      //nonceチェック
      $is_valid_nonce = $this->is_valid_nonce();
      if (!$is_valid_nonce) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $post_id   = $this->get_post_id_header();
      if (!ctype_digit($post_id) || (int) $post_id < 1) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param     = $params->get_params();
      $this->captcha_input = $param;
      $post_data = $param;
      //ファイル項目以外の配列の送り込みを制限する
      $post_data = $this->restrict_array_values($post_data, $post_id);
      $post_data = $this->restore_uploaded_files($post_data, $post_id);

      $errors    = $this->validate_submission($post_data, (int) $post_id);

      //エラーがある場合は検証NG（エラー内容を含める）
      if (!empty($errors)) {
        return [
          'valid'  => false,
          'errors' => $errors,
          'data'   => $post_data
        ];
      }
      //エラーがない場合は検証OK
      else {
        return [
          'valid' => true,
          'data'  => $post_data
        ];
      }
    }, $params);
  }

  /**
   * REST API メール送信
   *
   * @param WP_REST_Request $param
   * @return WP_REST_Response
   */
  public function rest_api_send(WP_REST_Request $params)
  {
    if ($this->is_valid_nonce()) { OMF_Utils::start_session(); }

    return $this->rest_response(function () use ($params) {
      //nonceチェック
      $is_authenticate = $this->is_authenticate();
      if (!$is_authenticate) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $post_id   = $this->get_post_id_header();
      if (!ctype_digit($post_id) || (int) $post_id < 1) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param     = $params->get_params();
      $this->captcha_input = $param;
      $post_data = $param;
      //ファイル項目以外の配列の送り込みを制限する
      $post_data = $this->restrict_array_values($post_data, $post_id);
      $post_data = $this->restore_uploaded_files($post_data, $post_id);
      //アップロードファイルを検証・保存して追加（nonceが正しいPOSTの時だけ保存する）

      $errors    = $this->validate_submission($post_data, (int) $post_id);
      //バリデーションエラーがある場合はエラーを返す
      if (!empty($errors)) {
        return [
          'valid'  => false,
          'errors' => $errors,
          'data'   => $post_data
        ];
      }

      //メールフォームを取得
      $form = $this->get_form($post_id);
      if (empty($form)) {
        return;
      }

      //nonce認証成功セッション更新
      $is_auth_success_session = $this->update_auth_success_session($form);
      if (!$is_auth_success_session) {
        session_write_close();
        return;
      }

      //メールIDを追加
      $post_data['mail_id'] = $this->get_mail_id($form->ID);

      //送信前のフック
      do_action('omf_before_send_mail', $post_data, $form, $post_id);

      //メール送信
      $send_results = $this->send_mails($post_data, $post_id, $form->ID);
      //送信後の処理
      if ($send_results['is_sended']) {
        $this->after_send_mails();
      }

      //レスポンスを生成
      $response = $this->create_send_response($send_results, $post_data, $form, $post_id);
      return $response;
    }, $params);
  }

  /**
   * 送信完了時のレスポンスを作成する
   *
   * @param array $send_results
   * @param array $post_data
   * @param WP_Post|array $form
   * @param integer|string|null $post_id
   * @return array
   */
  private function create_send_response(array $send_results, array $post_data, WP_Post|array $form, int|string|null $post_id): array
  {
    //送信成功
    if ($send_results['is_sended']) {
      //メールIDを更新
      $this->update_mail_id($form->ID, $post_data['mail_id']);
      //送信後のアクションフック追加
      if (!in_array(OMF_Delivery::mode($form->ID), ['wp_async','server_cron'], true)) { do_action('omf_after_send_mail', $post_data, $form, $post_id); }
      //レスポンスを生成
      $result = $this->create_send_success_response($post_data, $form);
      return $result;
    }
    //送信失敗
    else {
      $result = $this->create_send_fail_response($send_results, $post_data, $form);
      return $result;
    }
  }

  /**
   * 送信成功時のレスポンスを生成
   *
   * @param bool $is_sended
   * @param array $post_data
   * @param WP_Post|array $form
   * @param integer|string|null $post_id
   * @return array
   */
  private function create_send_success_response(array $post_data, WP_Post|array $form): array
  {
    return [
      'is_sended'    => true,
      'data'         => $post_data,
      'redirect_url' => $this->get_complete_page_url($form)
    ];
  }

  /**
   * 送信失敗時のレスポンスを生成
   *
   * @param array $send_results
   * @param array $post_data
   * @return array
   */
  private function create_send_fail_response(array $send_results, array $post_data): array
  {
    $errors = [];

    if (isset($send_results['is_sended_reply']) && !$send_results['is_sended_reply']) {
      $errors['reply_mail'] = ['自動返信メールの送信処理に失敗しました'];
    }

    if (isset($send_results['is_sended_admin']) && !$send_results['is_sended_admin']) {
      $errors['admin_mail'] = ['通知メールの送信処理に失敗しました'];
    }

    return [
      'is_sended' => false,
      'data'      => $post_data,
      'errors'    => $errors,
    ];
  }

  /**
   * REST API レスポンス処理
   *
   * @param Closure $fn
   * @param WP_REST_Request $params
   * @return WP_REST_Response
   */
  private function rest_response(Closure $fn, WP_REST_Request $params): mixed
  {
    $result = $fn($params);
    if (is_wp_error($result)) {
      // 原型のv0は認証NGもWP_ErrorをJSON化したHTTP 200で返していた。
      return $result->get_error_code() === 'failed' ? new WP_REST_Response($result, 200) : $result;
    }
    // v0の既存クライアントはHTTP 200のJSON内で検証・送信結果を判定する。
    return new WP_REST_Response($result, 200);
  }

  /**
   * X-OMF-POST-IDヘッダーの取得
   *
   * @return string
   */
  private function get_post_id_header(): string
  {
    $post_id = isset($_SERVER['HTTP_X_OMF_POST_ID']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_OMF_POST_ID'])) : '';
    return $post_id;
  }

  /**
   * 設置方式を確認し、v0の認証は各コールバックの冒頭で必ず行う。
   */
  public function rest_permission(): bool|WP_Error
  {
    $page_id = (int) $this->get_post_id_header();
    if ($page_id && OMF_Embed_Context::resolve($page_id)['present']) {
      return new WP_Error('omf_embed_flow', '設置したページのフォームから送信してください。', ['status' => 403]);
    }
    return true;
  }

  /**
   * nonceチェック
   *
   * @return boolean
   */
  private function is_valid_nonce(): bool
  {
    //フォーム認証
    $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
    return wp_verify_nonce($nonce, 'wp_rest') !== false;
  }

  /**
   * ワンタイムトークンチェック
   *
   * @return boolean
   */
  private function is_valid_token(): bool
  {
    //フォーム認証
    $token = isset($_SERVER['HTTP_X_OMF_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_OMF_TOKEN'])) : '';
    $session_token = !empty($_SESSION['omf_token']) ? $_SESSION['omf_token'] : '';
    return !empty($token) && $token === $session_token;
  }

  /**
   * 認証
   *
   * @return boolean
   */
  private function is_authenticate(): bool
  {
    $is_valid_nonce = $this->is_valid_nonce();
    $is_valid_token = $this->is_valid_token();
    return $is_valid_nonce && $is_valid_token;
  }

  /**
   * フォームから完了画面を取得
   *
   * @param WP_Post $form
   * @return string
   */
  private function get_complete_page_url(WP_Post $form): string
  {
    $screen_ids = $this->get_form_page_ids($form);
    $complete_page_id = !empty($screen_ids) ? $screen_ids['complete'] : '';
    return !empty($complete_page_id) ? get_permalink($complete_page_id) : '';
  }

  /**
   * nonce認証OKセッションを更新する
   *
   * @param WP_Post $form
   * @return boolean
   */
  private function update_auth_success_session(WP_Post $form): bool
  {
    if (empty($form)) {
      return false;
    }

    // 検証OKの場合は認証フラグを立てる
    session_regenerate_id(true);
    $session_name_prefix = OMF_Config::PREFIX . "{$form->post_name}";
    //認証セッション名を更新
    $session_name_auth = "{$session_name_prefix}_auth";
    $_SESSION[$session_name_auth] = true;
    return $_SESSION[$session_name_auth];
  }

  /**
   * メール送信
   *
   * @param array $post_data
   * @param integer $post_id
   * @param integer $form_id
   * @return array
   */
  private function send_mails(array $post_data, int $post_id, int $form_id): array
  {
    return $this->deliver_submission($post_data, $form_id, $post_id);
  }

  /**
   * 送信後の処理
   *
   * @return void
   */
  private function after_send_mails()
  {
    //ワンタイムトークンを破棄
    if (!empty($_SESSION['omf_token'])) {
      unset($_SESSION['omf_token']);
    }
  }
}
