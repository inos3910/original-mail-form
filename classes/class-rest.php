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
        'permission_callback' => '__return_true',
        'callback'            => [$this, 'rest_api_validate']
      ]
    );

    //送信
    register_rest_route(
      'omf-api/v0',
      '/send',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => '__return_true',
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
  public function rest_api_validate(WP_REST_Request $params): mixed
  {
    return $this->rest_response(function () use ($params) {

      //nonceチェック
      $is_verify_nonce = $this->is_verify_nonce();
      if (!$is_verify_nonce) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param     = $params->get_params();
      $post_data = !empty($param) ? array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $param) : [];
      $post_id   = $post_data['post_id'];
      $errors    = $this->validate_mail_form_data($post_data, $post_id);

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
  public function rest_api_send(WP_REST_Request $params): mixed
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    return $this->rest_response(function () use ($params) {
      //nonceチェック
      $is_verify_nonce = $this->is_verify_nonce();
      if (!$is_verify_nonce) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param     = $params->get_params();
      $post_data = !empty($param) ? array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $param) : [];
      $post_id   = $post_data['post_id'];
      $errors    = $this->validate_mail_form_data($post_data, $post_id);

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
        return;
      }

      //メールIDを追加
      $post_data['mail_id'] = $this->get_mail_id($form->ID);

      //送信前のフック
      do_action('omf_before_send_mail', $post_data, $form, $post_id);

      //メール送信
      $results         = $this->send_mails($post_data, $post_id);
      $is_sended_reply = $results['is_sended_reply'];
      $is_sended_admin = $results['is_sended_admin'];
      $is_sended_both  = $results['is_sended_both'];

      //送信成功
      if ($is_sended_both) {

        //メールIDを更新
        $this->update_mail_id($form->ID, $post_data['mail_id']);
        //送信後のアクションフック追加
        do_action('omf_after_send_mail', $post_data, $form, $post_id);

        return [
          'is_sended'    => $is_sended_both,
          'data'         => $post_data,
          'redirect_url' => $this->get_complete_page_url($form)
        ];
      }
      //送信失敗
      else {
        $errors = [];

        if (!$is_sended_reply) {
          $errors['reply_mail'] = ['自動返信メールの送信処理に失敗しました'];
        }

        if (!$is_sended_admin) {
          $errors['admin_mail'] = ['通知メールの送信処理に失敗しました'];
        }

        return [
          'is_sended' => $is_sended_both,
          'data'      => $post_data,
          'errors'    => $errors,
        ];
      }
    }, $params);
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
    $res = $fn($params);
    $response = new WP_REST_Response($res);
    if ($response->is_error()) {
      $error_response = $response->as_error();
      $error_data = $error_response->get_error_data();
      $status = !empty($error_data['status']) ? $error_data['status'] : 404;
      $response->set_status($status);
    } else {
      $response->set_status(200);
    }
    return $response;
  }

  /**
   * nonceチェック
   *
   * @return boolean
   */
  private function is_verify_nonce(): bool
  {
    //フォーム認証
    $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
    return wp_verify_nonce($nonce, 'wp_rest') !== false;
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
    session_write_close();
    return $_SESSION[$session_name_auth];
  }

  /**
   * メール送信
   *
   * @param array $post_data
   * @param integer $post_id
   * @return array
   */
  private function send_mails(array $post_data, int $post_id): array
  {
    //自動返信メール送信処理
    $is_sended_reply = $this->send_reply_mail($post_data, $post_id);

    //管理者宛メール送信処理
    $post_data['omf_reply_mail_sended'] = $is_sended_reply ? '送信成功' : '送信失敗';
    $is_sended_admin = $this->send_admin_mail($post_data, $post_id);

    return [
      'is_sended_reply' => $is_sended_reply,
      'is_sended_admin' => $is_sended_admin,
      'is_sended_both'  => $is_sended_reply && $is_sended_admin
    ];
  }
}
