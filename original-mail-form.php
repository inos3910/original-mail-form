<?php

/**
 * Plugin Name: Original Mail Form
 * Plugin URI: https://github.com/inos3910/original-mail-form
 * Update URI: https://github.com/inos3910/original-mail-form
 * Description: メールフォーム設定プラグイン（クラシックテーマ用）
 * Author: SHARESL
 * Author URI: https://sharesl.net/
 * Version: 1.0
 */

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_REST_Server;
use WP_Error;
use WP_REST_Response;

class OMF_Plugin
{

  /**
   * セッション接頭辞
   * @var string
   */
  private $session_name_prefix;

  /**
   * nonceのアクション名
   * @var string
   */
  private $nonce_action;

  /**
   * 送信データセッション名
   * @var string
   */
  private $session_name_post_data;

  /**
   * 認証セッション名
   * @var string
   */
  private $session_name_auth;

  /**
   * エラーセッション名
   * @var string
   */
  private $session_name_error;

  /**
   * 戻るセッション名
   * @var string
   */
  private $session_name_back;

  /**
   * OMF_Admin class
   *
   * @var instance
   */
  private $admin;

  /**
   * construct
   */
  public function __construct()
  {
    add_action('plugins_loaded', [$this, 'plugins_loaded']);
  }

  /**
   * 初期化
   */
  public function plugins_loaded()
  {
    require_once plugin_dir_path(__FILE__) . 'classes/class-config.php';
    require_once plugin_dir_path(__FILE__) . 'classes/class-admin.php';
    require_once plugin_dir_path(__FILE__) . 'classes/class-omf.php';

    //管理画面
    if (class_exists('Sharesl\Original\MailForm\OMF_Admin')) {
      $this->admin = new OMF_Admin();
    }

    //テンプレート
    add_action('parse_request', [$this, 'init_sessions']);
    add_action('template_redirect', [$this, 'redirect_form_pages']);
    add_action('wp_enqueue_scripts', [$this, 'load_scripts']);

    //REST API
    add_action('rest_api_init', [$this, 'add_custom_endpoint']);

    //フィルターフック追加
    add_filter('omf_get_errors', [$this, 'get_errors']);
    add_filter('omf_get_post_values', [$this, 'get_post_values']);
    add_filter('omf_nonce_field', [$this, 'nonce_field']);
    add_filter('omf_recaptcha_field', [$this, 'recaptcha_field']);
  }

  /**
   * セッション有効化
   */
  public function init_sessions()
  {
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.entropy_file', '/dev/urandom');
    ini_set('session.entropy_length', '32');

    if (!$this->is_rest() && session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
      header('Expires:-1');
      header('Cache-Control:');
      header('Pragma:');
    }
  }

  /**
   * REST API判定
   * @return boolean
   */
  private function is_rest()
  {
    return (defined('REST_REQUEST') && REST_REQUEST);
  }

  /**
   * REST APIエンドポイント追加
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
   * @param Array $param
   * @return void
   */
  public function rest_api_validate($params)
  {
    return $this->rest_response(function ($params) {

      //フォーム認証
      $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
      $auth = wp_verify_nonce($nonce, 'wp_rest');

      //nonce認証NG
      if (!$auth) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param = $params->get_params();
      //各要素を取得
      $post_data = !empty($param) ? array_map([$this, 'custom_escape'], $param) : [];
      //ページID
      $post_id = $post_data['post_id'];
      //検証
      $errors = $this->validate_mail_form_data($post_data, $post_id);
      //エラーがある場合は入力画面に戻す
      if (!empty($errors)) {
        return [
          'valid' => false,
          'errors' => $errors,
          'data' => $post_data
        ];
      } else {
        return [
          'valid' => true,
          'data' => $post_data
        ];
      }
    }, $params);
  }

  /**
   * REST API メール送信
   *
   * @param Array $param
   * @return void
   */
  public function rest_api_send($params)
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    return $this->rest_response(function ($params) {
      //フォーム認証
      $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
      $auth = wp_verify_nonce($nonce, 'wp_rest');
      //nonce認証OK
      if (!$auth) {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
      }

      $param = $params->get_params();
      //各要素を取得
      $post_data = !empty($param) ? array_map([$this, 'custom_escape'], $param) : [];
      //ページID
      $post_id = $post_data['post_id'];
      //検証
      $errors = $this->validate_mail_form_data($post_data, $post_id);
      //バリデーションエラーがある場合はエラーを返す
      if (!empty($errors)) {
        return [
          'valid' => false,
          'errors' => $errors,
          'data' => $post_data
        ];
      }

      //メールフォームを取得
      $linked_mail_form = $this->get_linked_mail_form($post_id);
      if (empty($linked_mail_form)) {
        return;
      }

      // 検証OKの場合は認証フラグを立てる
      session_regenerate_id(true);
      $session_name_prefix = OMF_Config::PREFIX . "{$linked_mail_form->post_name}";
      //認証セッション名を更新
      $session_name_auth = "{$session_name_prefix}_auth";
      $_SESSION[$session_name_auth] = true;
      session_write_close();

      //メールIDを追加
      $post_data['mail_id'] = $this->get_mail_id($linked_mail_form->ID);

      //送信前のフック
      do_action('omf_before_send_mail', $post_data, $linked_mail_form, $post_id);

      //自動返信メール送信処理
      $is_sended_reply = $this->send_reply_mail($post_data, $post_id);
      //管理者宛メール送信処理
      $post_data['omf_reply_mail_sended'] = $is_sended_reply ? '送信成功' : '送信失敗';
      $is_sended_admin = $this->send_admin_mail($post_data, $post_id);
      $is_sended = $is_sended_reply && $is_sended_admin;
      //送信成功
      if ($is_sended) {

        //完了画面（リダイレクト先）を取得
        $screen_ids = $this->get_form_page_ids($linked_mail_form);
        $complete_page_id = !empty($screen_ids) ? $screen_ids['complete'] : '';
        $complete_page_url = !empty($complete_page_id) ? get_permalink($complete_page_id) : '';
        //送信後にメールIDを更新
        $this->update_mail_id($linked_mail_form->ID, $post_data['mail_id']);

        //送信後のフック
        do_action('omf_after_send_mail', $post_data, $linked_mail_form, $post_id);

        return [
          'is_sended' => $is_sended,
          'data' => $post_data,
          'redirect_url' => $complete_page_url
        ];
      }
      //送信失敗
      else {
        $errors = [];
        if (!$is_sended_reply) {
          $errors['reply_mail'] = ['自動返信メールの送信処理に失敗しました'];
        }
        if (!$is_sended_admin) {
          $errors['admin_mail'] = ['管理者宛メールの送信処理に失敗しました'];
        }
        return [
          'is_sended' => $is_sended,
          'data' => $post_data,
          'errors' => $errors,
        ];
      }
    }, $params);
  }

  /**
   * REST API レスポンス処理
   *
   * @param Function $func
   * @param Object $params
   * @return Object
   */
  public function rest_response($func, $params)
  {
    $res = $func($params);
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
   * エラーデータを取得
   * @return Array
   */
  public function get_errors()
  {
    if (empty($_SESSION[$this->session_name_error])) {
      return [];
    }

    //エラーは一度しか取得しない
    $errors = $_SESSION[$this->session_name_error];
    unset($_SESSION[$this->session_name_error]);

    return $errors;
  }

  /**
   * 送信データを取得
   * @return Array
   */
  public function get_post_values()
  {
    $post_data = [];
    //セッションがある場合
    if (!empty($_SESSION[$this->session_name_post_data])) {
      $post_data = $_SESSION[$this->session_name_post_data];
    }

    //POSTがある場合は上書き
    if (!empty($_POST)) {
      $posts = array_map([$this, 'custom_escape'], $_POST);
      foreach ((array)$posts as $key => $value) {
        $post_data[$key] = $value;
      }
      $post_data = $this->filter_post_keys($post_data);
    }

    return $post_data;
  }

  /**
   * nonceフィールドを出力する
   */
  public function nonce_field()
  {
    wp_nonce_field($this->nonce_action, 'omf_nonce', true);
  }

  /**
   * reCAPTCHAフィールド
   * @return [type] [description]
   */
  public function recaptcha_field()
  {
    $is_recaptcha = $this->can_use_recaptcha();
    if (!$is_recaptcha) {
      return;
    }

    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
    $site_key = !empty(get_option('omf_recaptcha_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_site_key'))) : '';

    $html =  <<<EOM
    <input type="hidden" name="{$recaptcha_field_name}" id="g-recaptcha-response" data-sitekey="{$site_key}">
    EOM;

    echo $html;
  }

  /**
   * ページと連携しているメールフォームのスラッグを取得
   * @param  int|string $post_id $post_id ページID
   * @return string スラッグ
   */
  private function get_linked_mail_form_slug($post_id = null)
  {
    $current_page_id = !empty($post_id) ? $post_id : get_the_ID();
    if (empty($current_page_id)) {
      return;
    }

    $linked_mail_form_slug = $this->custom_escape(get_post_meta($current_page_id, 'cf_omf_select', true));
    if (empty($linked_mail_form_slug)) {
      return;
    }

    return $linked_mail_form_slug;
  }

  /**
   * ページと連携しているメールフォームのページオブジェクトを取得
   * @param  [int|string] $post_id ページID
   * @return [WP_Post|array|null] ページのWP_Postオブジェクト
   */
  private function get_linked_mail_form($post_id = null)
  {
    $linked_mail_form_slug = $this->get_linked_mail_form_slug($post_id);
    if (empty($linked_mail_form_slug)) {
      return;
    }

    $linked_mail_form = get_page_by_path($linked_mail_form_slug, OBJECT, OMF_Config::NAME);
    if (empty($linked_mail_form)) {
      return;
    }

    return $linked_mail_form;
  }

  /**
   * フォームを設置するページのIDを取得
   * @param  WP_Post $linked_mail_form メールフォームページのオブジェクト
   * @return array
   */
  private function get_form_page_ids($linked_mail_form = null)
  {
    $ids = [];

    $linked_mail_form = !empty($linked_mail_form) ? $linked_mail_form : $this->get_linked_mail_form();
    if (empty($linked_mail_form)) {
      return $ids;
    }

    $entry_page_path    = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_screen_entry', true));
    $confirm_page_path  = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_screen_confirm', true));
    $complete_page_path = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_screen_complete', true));

    $ids['entry']       = url_to_postid($entry_page_path);
    $ids['confirm']     = url_to_postid($confirm_page_path);
    $ids['complete']    = url_to_postid($complete_page_path);

    return $ids;
  }

  /**
   * すべてのメールフォームのセッションをクリアする
   * @return void
   */
  private function clear_sessions_all()
  {
    $prefix_id = $this->get_linked_mail_form_slug();
    $this->clear_sessions($prefix_id);
  }

  /**
   * メールフォームのセッションをクリアする
   * @param String $prefix_id フォームID
   */
  private function clear_sessions($prefix_id)
  {
    $session_name_prefix = OMF_Config::PREFIX . $prefix_id;
    $session_name_post_data = "{$session_name_prefix}_data";
    $session_name_auth = "{$session_name_prefix}_auth";
    $session_name_error = "{$session_name_prefix}_errors";
    $session_name_back = "{$session_name_prefix}_back";

    //送信データセッションを破棄
    if (isset($_SESSION[$session_name_post_data])) {
      $_SESSION[$session_name_post_data] = [];
      unset($_SESSION[$session_name_post_data]);
    }

    //認証セッションを破棄
    if (isset($_SESSION[$session_name_auth])) {
      $_SESSION[$session_name_auth] = false;
      unset($_SESSION[$session_name_auth]);
    }

    //エラーセッションを破棄
    if (isset($_SESSION[$session_name_error])) {
      $_SESSION[$session_name_error] = [];
      unset($_SESSION[$session_name_error]);
    }

    //戻るセッションを破棄
    if (isset($_SESSION[$session_name_back])) {
      $_SESSION[$session_name_back] = false;
      unset($_SESSION[$session_name_back]);
    }
  }


  //フォームのリダイレクト
  public function redirect_form_pages()
  {
    //管理画面・投稿・固定ページ以外無効
    if (!is_page() && !is_single()) {
      return;
    }

    //現在のページのID取得
    $current_page_id    = get_the_ID();
    $omf_form_slug      = $this->custom_escape(get_post_meta($current_page_id, 'cf_omf_select', true));
    $omf_form           = !empty($omf_form_slug) ? get_page_by_path($omf_form_slug, OBJECT, OMF_Config::NAME) : null;
    //フォーム設定がないページはセッションをクリア
    if (empty($omf_form)) {
      $this->clear_sessions_all();
      return;
    }

    //画面設定からパスを取得
    $page_pathes = [
      'entry'    => $this->custom_escape(get_post_meta($omf_form->ID, 'cf_omf_screen_entry', true)),
      'confirm'  => $this->custom_escape(get_post_meta($omf_form->ID, 'cf_omf_screen_confirm', true)),
      'complete' => $this->custom_escape(get_post_meta($omf_form->ID, 'cf_omf_screen_complete', true))
    ];

    //表示条件からページ情報を取得
    $conditions =  get_post_meta($omf_form->ID, 'cf_omf_condition_post', true);
    $pages = [];
    foreach ((array)$conditions as $cond) {
      $cond = $this->custom_escape($cond);
      foreach ((array)$page_pathes as $key => $path) {
        if (!empty($pages[$key])) {
          continue;
        }
        $pages[$key] = !empty($path) ? get_page_by_path($path, OBJECT, $cond) : null;
      }
    }

    //セッション接頭辞を一意にする
    $this->update_session_names($omf_form_slug);

    //フォーム入力ページ
    if ($current_page_id === $pages['entry']->ID) {
      $this->contact_entry_page_redirect($page_pathes, $pages);
    }
    //確認画面
    elseif ($current_page_id === $pages['confirm']->ID) {
      $this->contact_confirm_page_redirect($page_pathes, $pages);
    }
    //完了画面
    elseif ($current_page_id === $pages['complete']->ID) {
      $this->contact_complete_page_redirect($page_pathes, $pages);
    }
    //それ以外
    else {
      return;
    }
  }

  /**
   * セッション名を更新する
   *
   * @param String $prefix_id
   * @return void
   */
  private function update_session_names($prefix_id)
  {
    //セッション接頭辞を一意にする
    $this->session_name_prefix = OMF_Config::PREFIX . $prefix_id;
    //nonceアクション名を更新
    $this->nonce_action = "{$this->session_name_prefix}_nonce_action";
    //送信データセッション名を更新
    $this->session_name_post_data = "{$this->session_name_prefix}_data";
    //認証セッション名を更新
    $this->session_name_auth = "{$this->session_name_prefix}_auth";
    //エラーセッション名を更新
    $this->session_name_error = "{$this->session_name_prefix}_errors";
    //戻るセッション名を更新
    $this->session_name_back = "{$this->session_name_prefix}_back";
  }

  /**
   * 入力ページ（初期ページ）のリダイレクト処理
   * @param  array $page_pathes
   * @param  array $pages
   */
  private function contact_entry_page_redirect($page_pathes, $pages)
  {
    //戻るボタン
    if (filter_input(INPUT_POST, 'submit_back') === "back") {
      //戻るフラグがオンの場合
      if (!empty($_SESSION[$this->session_name_back]) && $_SESSION[$this->session_name_back] === true) {
        //認証フラグをオフ
        $_SESSION[$this->session_name_auth] = false;
        session_write_close();
        wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
        exit;
      }
    }

    //確認ボタンではない場合
    if (filter_input(INPUT_POST, 'confirm') !== 'confirm') {
      //戻るフラグがある場合
      if (!empty($_SESSION[$this->session_name_back]) && $_SESSION[$this->session_name_back] === true) {
        //戻るフラグをオフ
        $_SESSION[$this->session_name_back] = false;
        unset($_SESSION[$this->session_name_back]);
      }
      //戻るフラグがない場合
      else {
        $_SESSION[$this->session_name_auth] = false;
        //エラーがなければ送信データをクリア
        if (empty($_SESSION[$this->session_name_error])) {
          $_SESSION[$this->session_name_post_data] = [];
          unset($_SESSION[$this->session_name_post_data]);
        }
      }
      return;
    }

    //フォーム認証
    $nonce   = filter_input(INPUT_POST, 'omf_nonce');
    $auth    = wp_verify_nonce($nonce, $this->nonce_action);
    //nonce認証NG
    if (!$auth) {
      $_SESSION[$this->session_name_auth] = false;
      return;
    }

    //リファラー認証
    $referer           = filter_input(INPUT_POST, '_wp_http_referer');
    $referer           = !empty($referer) ? sanitize_text_field(wp_unslash($referer)) : null;
    $referer_post_id   = !empty($referer) ? url_to_postid($referer) : null;
    $referer_post      = !empty($referer_post_id) ? get_post($referer_post_id) : null;
    $referer_post_slug = !empty($referer_post) ? $referer_post->post_name : null;
    //リファラー認証NG
    if (empty($referer_post) || $referer_post_slug !== $pages['entry']->post_name) {
      $_SESSION[$this->session_name_auth] = false;
      return;
    }

    //各要素を取得
    $post_data = !empty($_POST) ? array_map([$this, 'custom_escape'], $_POST) : [];

    //検証
    $errors = $this->validate_mail_form_data($post_data);
    //エラーがある場合は入力画面に戻す
    if (!empty($errors)) {
      $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
      $_SESSION[$this->session_name_error] = $errors;
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }

    // 検証OKの場合はセッションを保持して確認画面へ
    $_SESSION[$this->session_name_auth] = true;
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_pathes['confirm'])), 307);
    exit;
  }

  // 不要な送信データをフィルターする
  private function filter_post_keys($post_data)
  {
    if (empty($post_data)) {
      return;
    }

    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
    $remove_keys = ['confirm', 'send', 'omf_nonce', '_wp_http_referer', $recaptcha_field_name];
    $post_data = array_diff_key($post_data, array_flip($remove_keys));

    return $post_data;
  }

  /**
   * 確認画面のリダイレクト処理
   * @param  array $page_pathes
   * @param  array $pages
   */
  private function contact_confirm_page_redirect($page_pathes, $pages)
  {
    //戻るボタンの場合
    if (filter_input(INPUT_POST, 'submit_back') === "back") {
      //戻るフラグをオン
      $_SESSION[$this->session_name_back] = true;
      //認証フラグをオフ
      $_SESSION[$this->session_name_auth] = false;
      $_SESSION[$this->session_name_post_data] = $this->get_post_values();
      session_write_close();
      //入力画面に戻す
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])), 307);
      exit;
    }

    //POSTがある場合
    if (!empty($_POST)) {
      //セッションがある場合
      if (
        !empty($_SESSION[$this->session_name_auth])
        && $_SESSION[$this->session_name_auth] === true
      ) {
        //メール送信以外の場合
        if (filter_input(INPUT_POST, 'send') !== 'send') {
          //データを取得して保存
          $post_data = $this->get_post_values();
          $_SESSION[$this->session_name_auth] = true;
          $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
          session_write_close();
          //リダイレクトする
          wp_safe_redirect(esc_url(home_url($page_pathes['confirm'])));
          exit;
        }

        $post_data = $this->get_post_values();
        $_SESSION[$this->session_name_auth] = true;
        $_SESSION[$this->session_name_post_data] = $post_data;
        // メール送信処理
        $this->mail_send_handler($page_pathes, $pages, $post_data);
      }
      //セッションがない場合
      else {
        //nonce取得
        $nonce   = filter_input(INPUT_POST, 'omf_nonce');
        $auth    = wp_verify_nonce($nonce, $this->nonce_action);
        //nonce認証NG
        if (!$auth) {
          $post_data = $this->get_post_values();
          $_SESSION[$this->session_name_back] = true;
          $_SESSION[$this->session_name_auth] = false;
          $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
          session_write_close();
          wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
          exit;
        }

        //リファラー認証
        $referer           = filter_input(INPUT_POST, '_wp_http_referer');
        $referer           = !empty($referer) ? sanitize_text_field(wp_unslash($referer)) : null;
        $referer_post_id   = !empty($referer) ? url_to_postid($referer) : null;
        $referer_post      = !empty($referer_post_id) ? get_post($referer_post_id) : null;
        $referer_post_slug = !empty($referer_post) ? $referer_post->post_name : null;
        //リファラー認証NG
        if (empty($referer_post) || $referer_post_slug !== $pages['confirm']->post_name) {
          $post_data = $this->get_post_values();
          $_SESSION[$this->session_name_back] = true;
          $_SESSION[$this->session_name_auth] = false;
          $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
          session_write_close();
          wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
          exit;
        }

        //認証OKの場合
        //メール送信以外の場合はそのまま表示
        if (filter_input(INPUT_POST, 'send') !== 'send') {
          return;
        }

        //データ取得
        $post_data = $this->get_post_values();
        // メール送信処理
        $this->mail_send_handler($page_pathes, $pages, $post_data);
      }
    }
    //POSTがない場合
    else {
      //セッションがある場合
      if (
        !empty($_SESSION[$this->session_name_auth])
        && $_SESSION[$this->session_name_auth] === true
      ) {

        //メール送信以外の場合はそのまま表示
        if (filter_input(INPUT_POST, 'send') !== 'send') {
          $post_data = $this->get_post_values();
          return;
        }

        //データ取得
        $post_data = $this->get_post_values();
        // メール送信処理
        $this->mail_send_handler($page_pathes, $pages, $post_data);
      }
      //POSTもセッションもない場合
      else {
        $_SESSION[$this->session_name_auth] = false;
        session_write_close();
        wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
        exit;
      }
    }
  }

  /**
   * 完了画面のリダイレクト処理
   * @param  array $page_pathes
   * @param  array $pages
   */
  private function contact_complete_page_redirect($page_pathes, $pages)
  { //POSTがある場合はリダイレクト
    if (!empty($_POST)) {
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['complete'])));
      exit;
    }

    //セッションがある場合
    if (
      !empty($_SESSION[$this->session_name_auth])
      && $_SESSION[$this->session_name_auth] === true
    ) {
      //セッションを破棄
      $this->clear_sessions_all();
      return;
    }
    //セッションがない場合
    else {
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }
  }


  //フォームデータの検証
  private function validate_mail_form_data($post_data, $post_id = null)
  {
    $errors = [];

    //連携しているメールフォームを取得
    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return $errors['undefined'] = ['メールフォームにエラーが起きました'];
    }

    //バリデーション設定を取得
    $validations = get_post_meta($linked_mail_form->ID, 'cf_omf_validation', true);
    if (empty($validations)) {
      return $errors;
    }

    //バリデーション読み込み
    require_once plugin_dir_path(__FILE__) . 'classes/class-validation.php';

    //バリデーション設定
    foreach ((array)$validations as $valid) {
      $valid = array_map([$this, 'custom_escape'], $valid);
      $error_message = OMF_Validation::validate($post_data, $valid);
      if (!empty($error_message)) {
        $errors[$valid['target']] = $error_message;
      }
    }

    //reCAPTCHA
    $is_recaptcha = $this->can_use_recaptcha($post_id);
    if ($is_recaptcha) {
      $recaptcha = $this->verify_google_recaptcha();
      if (!$recaptcha) {
        $errors['recaptcha'] = ['フォーム認証エラーのためもう一度送信してください。'];
      }
    }

    return $errors;
  }

  /**
   * reCAPTCHA設定の有無を判定
   * @return boolean
   */
  private function can_use_recaptcha($post_id = null)
  {
    //reCAPTCHAのキーを確認
    if (empty(get_option('omf_recaptcha_secret_key')) || empty(get_option('omf_recaptcha_site_key'))) {
      return false;
    }

    //reCAPTCHA設定を確認
    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return false;
    }

    $is_recaptcha = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_recaptcha', true));
    if (empty($is_recaptcha)) {
      return false;
    }

    //入力画面のみ
    $current_page_id = get_the_ID();
    $page_ids        = $this->get_form_page_ids($linked_mail_form);
    if ($page_ids['entry'] !== $current_page_id) {
      return false;
    }

    return $is_recaptcha;
  }

  /**
   * reCAPTCHA認証処理
   * @return boolean
   */
  private function verify_google_recaptcha()
  {
    $recaptcha_secret = !empty(get_option('omf_recaptcha_secret_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_secret_key'))) : '';
    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
    $recaptcha_response = !empty(filter_input(INPUT_POST, $recaptcha_field_name)) ? sanitize_text_field(wp_unslash(filter_input(INPUT_POST, $recaptcha_field_name))) : '';

    if (empty($recaptcha_secret) || empty($recaptcha_response)) {
      return false;
    }

    // APIリクエスト
    $recaptch_url = 'https://www.google.com/recaptcha/api/siteverify';
    $recaptcha_params = [
      'secret' => $recaptcha_secret,
      'response' => $recaptcha_response,
    ];
    $request_params_query =  http_build_query($recaptcha_params);
    $endpoint = "{$recaptch_url}?{$request_params_query}";

    $verify_response = $this->curl_get_contents($endpoint);
    //curl取得エラーの場合
    if (empty($verify_response) || is_wp_error($verify_response)) {
      return false;
    }

    // APIレスポンス確認
    $response_data = json_decode($verify_response);

    return !empty($response_data) && $response_data->success && $response_data->score >= 0.5;
  }

  /**
   * curlでデータ取得する関数
   * @param  string  $url
   * @param  integer $timeout タイムアウト（秒）
   */
  private function curl_get_contents($url, $timeout = 60)
  {
    $ch = curl_init();
    $header = [
      "Content-Type: application/json"
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
   * @param string url
   * @param array post_data
   * @param int timeout
   **/
  private function curl_post_contents($url, $post_data, $header = null, $timeout = 60)
  {
    // 送信データをURLエンコード
    $data = wp_json_encode($post_data);

    $ch = curl_init();

    $header = !empty($header) ? $header : [
      "Content-Type: application/json",
      'Cache-Control: no-cache',
      'Pragma: no-cache'
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
   * メール送信のエラーハンドラー
   *
   * @param Array $page_pathes
   * @param Array $pages
   * @param Array $post_data
   * @return void
   */
  private function mail_send_handler($page_pathes, $pages, $post_data = null)
  {
    // メール送信処理
    $send = $this->send($page_pathes, $pages, $post_data);
    //メール送信完了の場合
    if ($send === true) {
      //完了画面にリダイレクト
      $_SESSION[$this->session_name_auth] = true;
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['complete'])), 307);
      exit;
    }
    //メール送信失敗の場合
    else {
      $_SESSION[$this->session_name_auth] = false;
      //エラーメッセージを追加して入力画面にリダイレクト
      $_SESSION[$this->session_name_error] = [
        'send' => ['送信中にエラーが発生しました。お手数ですが、再度送信をお試しください']
      ];
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }
  }

  /**
   * 送信処理
   * @param Array $page_pathes
   * @param Array $pages
   * @param Array $post_data
   * @return boolean
   */
  private function send($page_pathes, $pages, $post_data = null)
  {

    //フォーム認証
    $nonce = filter_input(INPUT_POST, 'omf_nonce');
    $auth  = wp_verify_nonce($nonce, $this->nonce_action);
    //認証NGの場合
    if (!$auth) {
      return false;
    }

    //リファラー認証
    $referer           = filter_input(INPUT_POST, '_wp_http_referer');
    $referer           = !empty($referer) ? sanitize_text_field(wp_unslash($referer)) : null;
    $referer_post_id   = !empty($referer) ? url_to_postid($referer) : null;
    $referer_post      = !empty($referer_post_id) ? get_post($referer_post_id) : null;
    $referer_post_slug = !empty($referer_post) ? $referer_post->post_name : null;
    //リファラー認証NG
    if (empty($referer_post) || $referer_post_slug !== $pages['confirm']->post_name) {
      return false;
    }

    //各要素を取得
    if (empty($post_data)) {
      $post_data = !empty($_SESSION[$this->session_name_post_data]) ? array_map([$this, 'custom_escape'], $_SESSION[$this->session_name_post_data]) : [];
    }

    //検証
    $errors = $this->validate_mail_form_data($post_data);
    //エラーがある場合は入力画面に戻す
    if (!empty($errors)) {
      $_SESSION[$this->session_name_error] = $errors;
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }

    //メールフォームを取得
    $linked_mail_form = $this->get_linked_mail_form();
    //メールID
    $post_data['mail_id'] = $this->get_mail_id($linked_mail_form->ID);

    //送信前のフック
    $post_id = get_the_ID();
    do_action('omf_before_send_mail', $post_data, $linked_mail_form, $post_id);

    //自動返信メール送信処理
    $is_sended_reply = $this->send_reply_mail($post_data);
    //管理者宛メール送信処理
    $post_data['omf_reply_mail_sended'] = $is_sended_reply ? '送信成功' : '送信失敗';
    $is_sended_admin = $this->send_admin_mail($post_data);

    $is_sended = $is_sended_reply && $is_sended_admin;
    if ($is_sended) {
      //送信後にメールIDを更新
      $this->update_mail_id($linked_mail_form->ID, $post_data['mail_id']);

      //送信後のフック
      do_action('omf_after_send_mail', $post_data, $linked_mail_form, $post_id);
    }

    return $is_sended;
  }

  /**
   * エスケープ処理
   * @param  string $input
   * @param  boolean $is_text_field 改行を含むテキストフィールドの場合
   * @return string
   */
  private function custom_escape($input, $is_text_field = false)
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
   * メールIDを取得
   * @param  int $form_id
   * @return int
   */
  private function get_mail_id($form_id)
  {
    $mail_id = $this->custom_escape(get_post_meta($form_id, 'cf_omf_mail_id', true));
    if (empty($mail_id)) {
      $mail_id = 1;
    } else {
      ++$mail_id;
    }

    return $mail_id;
  }

  /**
   * メールIDを更新
   * @param  int $form_id
   * @param  int $mail_id
   * @return boolean
   */
  private function update_mail_id($form_id, $mail_id)
  {
    return  update_post_meta($form_id, 'cf_omf_mail_id', $mail_id);
  }

  /**
   * 自動返信メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @return boolean
   */
  private function send_reply_mail($post_data, $post_id = null)
  {

    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return false;
    }

    //メール情報を取得
    $form_title    = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_reply_title', true));
    $mail_to       = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_reply_to', true));
    $mail_template = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_reply_mail', true), true);
    $mail_from     = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_reply_from', true));
    $mail_from     = !empty($mail_from) ? str_replace(PHP_EOL, '', $mail_from) : '';
    $from_name     = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_reply_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(PHP_EOL, '', $from_name) : '';

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html($this->get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/'))
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    //送信前のフック
    do_action('omf_before_send_reply_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name);

    //件名
    $reply_subject     = $this->replace_form_mail_tags($form_title, $tag_to_text);
    //宛先
    $reply_mailaddress = $this->replace_form_mail_tags($mail_to, $tag_to_text);
    //メール本文のifタグを置換
    $mail_template     = $this->replace_form_mail_if_tags($mail_template, $tag_to_text);
    //メールタグを置換
    $reply_message     = $this->replace_form_mail_tags($mail_template, $tag_to_text);
    //フィルターを通す
    $reply_message     = apply_filters('omf_reply_mail', $reply_message, $tag_to_text);

    //メールヘッダー
    $reply_headers[]   = "From: {$from_name} <{$mail_from}>";
    $reply_headers[]   = "Reply-To: {$from_name} <{$mail_from}>";
    $reply_headers     = implode(PHP_EOL, $reply_headers);

    //wp mailのfromを変更
    add_filter('wp_mail_from', function () use ($mail_from) {
      return $mail_from;
    }, PHP_INT_MAX);

    add_filter('wp_mail_from_name', function () use ($from_name) {
      return $from_name;
    }, PHP_INT_MAX);

    //メール送信処理
    $is_sended_reply = wp_mail(
      //宛先
      $reply_mailaddress,
      //件名
      $reply_subject,
      //内容
      $reply_message,
      //メールヘッダー
      $reply_headers
    );

    if ($is_sended_reply) {
      //送信後のフック
      do_action('omf_after_send_reply_mail', $tag_to_text, $reply_mailaddress, $reply_subject, $reply_message, $reply_headers);
    }

    return $is_sended_reply;
  }

  /**
   * 管理者宛メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @return boolean
   */
  private function send_admin_mail($post_data, $post_id = null)
  {
    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return false;
    }

    //メール情報を取得
    $form_title    = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_admin_title', true));
    $mail_to       = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_admin_to', true));
    $mail_template = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_admin_mail', true), true);
    $mail_from     = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_admin_from', true));
    $mail_from     = !empty($mail_from) ? str_replace(PHP_EOL, '', $mail_from) : '';
    $from_name     = $this->custom_escape(get_post_meta($linked_mail_form->ID, 'cf_omf_admin_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(PHP_EOL, '', $from_name) : '';

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html($this->get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/')),
      'user_agent'    => $_SERVER["HTTP_USER_AGENT"],
      'user_ip'       => $_SERVER["REMOTE_ADDR"],
      'host'          => gethostbyaddr($_SERVER["REMOTE_ADDR"])
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    //送信前のフック
    do_action('omf_before_send_admin_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name);

    //件名
    $admin_subject     = $this->replace_form_mail_tags($form_title, $tag_to_text);
    //宛先
    $admin_mailaddress = $this->replace_form_mail_tags($mail_to, $tag_to_text);
    //メール本文のifタグを置換
    $mail_template     = $this->replace_form_mail_if_tags($mail_template, $tag_to_text);
    //メールタグを置換
    $admin_message     = $this->replace_form_mail_tags($mail_template, $tag_to_text);
    //フィルターを通す
    $admin_message     = apply_filters('omf_admin_mail', $admin_message, $tag_to_text);

    //メールヘッダー
    $admin_headers[]   = "From: {$from_name} <{$mail_from}>";
    $admin_headers[]   = "Reply-To: {$from_name} <{$mail_from}>";

    //wp mailのfromを変更
    add_filter('wp_mail_from', function () use ($mail_from) {
      return $mail_from;
    }, PHP_INT_MAX);

    add_filter('wp_mail_from_name', function () use ($from_name) {
      return $from_name;
    }, PHP_INT_MAX);

    //メール送信処理
    $is_sended_admin   = wp_mail(
      //宛先
      $admin_mailaddress,
      //件名
      $admin_subject,
      //内容
      $admin_message,
      //メールヘッダー
      $admin_headers
    );

    //メール送信成功時
    if ($is_sended_admin) {
      //送信後のフック
      do_action('omf_after_send_admin_mail', $tag_to_text, $admin_mailaddress, $admin_subject, $admin_message, $admin_headers);
    }

    //DB保存
    $data_to_save = array_merge([
      'omf_mail_title'        => $admin_subject,
      'omf_mail_to'           => $admin_mailaddress,
    ], $tag_to_text);

    //自動返信メールのすぐ下に通知メールの送信結果を追加する
    $data_to_save = $this->add_after_key($data_to_save, 'omf_reply_mail_sended', $is_sended_admin ? '送信成功' : '送信失敗', 'omf_admin_mail_sended');

    $this->save_data($linked_mail_form, $data_to_save);

    //Slack通知
    $this->send_slack([
      'subject'        => $admin_subject,
      'message'        => $admin_message,
      'post_data'      => $tag_to_text,
      'form'           => $linked_mail_form,
      'is_mail_sended' => $is_sended_admin
    ]);

    return $is_sended_admin;
  }

  /**
   * 指定したキーの次に追加する
   *
   * @param array $array
   * @param string $key
   * @param any $value
   * @param string $new_key
   * @return void
   */
  public function add_after_key($array, $key, $value, $new_key)
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

  /**
   * Slackに通知内容を送信
   *
   * @param array $data
   * @return void
   */
  private function send_slack($data)
  {
    $form = $data['form'] ?? '';
    if (empty($form)) {
      return;
    }

    $is_slack_notify = get_post_meta($form->ID, 'cf_omf_is_slack_notify', true) === '1';
    if (!$is_slack_notify) {
      return;
    }

    $webhook_url = get_post_meta($form->ID, 'cf_omf_slack_webhook_url', true);
    $channel = get_post_meta($form->ID, 'cf_omf_slack_channel', true);
    $subject     = $data['subject'] ?? '';
    $message     = $data['message'] ?? '';
    $post_data   = $data['post_data'] ?? '';

    //データが空の場合は終了
    if (
      empty($webhook_url) ||
      empty($channel) ||
      empty($message) ||
      empty($post_data)
    ) {
      return;
    }

    $form_title = get_the_title($form->ID);
    $form_title = $this->custom_escape($form_title);

    $notify_data = [
      "channel"     => "#{$channel}", //チャンネル名
      "username"    => "メールフォーム", //BOT名
      "icon_emoji"  => ":seal:", //アイコン
      "attachments" => [
        [
          "pretext"     => "▼通知：{$form_title}",
          "color"       => "#10afaa",
          "title"       => $subject,
          "text"        => ">>>\n{$message}",
          "footer"      => !empty($post_data['site_name']) ? $post_data['site_name'] : '',
          "footer_icon" => get_site_icon_url(),
        ]
      ]
    ];

    $this->curl_post_contents($webhook_url, $notify_data);
  }

  /**
   * *DB保存
   *
   * @param WP_Post $form
   * @param array $data_to_save
   * @return void
   */
  private function save_data($form, $data_to_save)
  {
    if (empty($form) || empty($data_to_save)) {
      return;
    }

    $is_use_db = get_post_meta($form->ID, 'cf_omf_save_db', true) === '1';
    if (!$is_use_db) {
      return;
    }

    $data_post_type = $this->admin->get_data_post_type_by_id($form->ID);
    if (empty($data_post_type)) {
      return;
    }

    $post_id = wp_insert_post([
      'post_type'   => $data_post_type,
      'post_title'  => $data_to_save['omf_mail_title'],
      'post_status' => 'publish',
      'meta_input'  => $data_to_save
    ]);

    if (!empty($post_id)) {
      //スラッグを重複回避でIDにしておく
      wp_update_post([
        'ID'        => $post_id,
        'post_name' => $post_id
      ]);
    }
  }

  /**
   * 日時取得
   * @return string
   */
  private function get_current_datetime()
  {
    $w              = wp_date("w");
    $week_name      = ["日", "月", "火", "水", "木", "金", "土"];
    $send_datetime  = wp_date("Y/m/d ({$week_name[$w]}) H:i");
    return $send_datetime;
  }

  /**
   * メールタグのif文を置換
   * @param string $text
   * @param array $tags
   * @return string
   */
  private function replace_form_mail_if_tags($text, $tags)
  {
    $text = preg_replace_callback('/{if:([a-zA-Z_]+)}([\s\S]*?){\/if:\1}/', function ($matches) use ($tags) {
      $tag = $matches[1];
      $content = $matches[2];

      // タグが存在し、かつ値が空でない場合にのみコンテンツを表示
      if (isset($tags[$tag]) && $tags[$tag] !== '') {
        return $content;
      } else {
        return '';
      }
    }, $text);

    //3個以上の連続した改行コード・改行文字を2個に固定
    $text = preg_replace("/(\r\n){3,}|\r{3,}|\n{3,}/", "\n\n", $text);

    return $text;
  }

  /**
   * メールタグを置換
   * @param  string $text
   * @param  array $tag_to_text
   * @return string
   */
  private function replace_form_mail_tags($text, $tag_to_text)
  {
    if (empty($text)) {
      return;
    }

    preg_match_all('/\{(.+?)\}/', $text, $matches);

    if (!empty($matches[1])) {
      foreach ($matches[1] as $tag) {
        $replacement_text = isset($tag_to_text[$tag]) ? $tag_to_text[$tag] : '';
        $replacement_text = apply_filters('omf_mail_tag', $replacement_text, $tag);
        if (empty($replacement_text)) {
          $replacement_text = '';
        }

        $text = str_replace("{" . $tag . "}", $replacement_text, $text);
      }
    }

    return $text;
  }

  //スクリプトの読み込み
  public function load_scripts()
  {
    $this->load_recaptcha_script();
    $this->load_disable_browser_back_script();
  }


  /**
   * テンプレートにreCAPTCHA用のスクリプト追加
   */
  public function load_recaptcha_script()
  {
    //reCAPTCHA設定の有無を判定
    $is_recaptcha = $this->can_use_recaptcha();
    if (empty($is_recaptcha)) {
      return;
    }

    //現在のページのID取得
    $current_page_id   = get_the_ID();

    //連携するメールフォームを取得
    $linked_mail_form  = $this->get_linked_mail_form($current_page_id);
    if (empty($linked_mail_form)) {
      return;
    }

    //入力・確認・完了画面のページIDを取得する
    $page_ids = $this->get_form_page_ids($linked_mail_form);

    //入力・確認画面どちらかでなければ設置しない
    $recaptcha_page_ids = [$page_ids['entry'], $page_ids['confirm']];
    if (!in_array($current_page_id, $recaptcha_page_ids, true)) {
      return;
    }

    //JS出力
    $recaptcha_site_key = !empty(get_option('omf_recaptcha_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_site_key'))) : '';
    wp_enqueue_script('recaptcha-script', "https://www.google.com/recaptcha/api.js?render={$recaptcha_site_key}", [], null, true);

    $custom_script = "
    grecaptcha.ready(function () {
      const setToken = () => {
        grecaptcha
          .execute('{$recaptcha_site_key}', { action: 'homepage' })
          .then(function (token) {
            var recaptchaResponse = document.getElementById(
              'g-recaptcha-response'
            );
            recaptchaResponse.value = token;
          });
      };
      setToken();
      setInterval(() => {
        setToken();
      }, 1000*60);
    });";
    wp_add_inline_script('recaptcha-script', $custom_script);
  }

  /**
   * ブラウザバックを無効化するスクリプトを追加
   *
   * @return void
   */
  public function load_disable_browser_back_script()
  {
    //現在のページのID取得
    $current_page_id = get_the_ID();

    //連携するメールフォームを取得
    $linked_mail_form  = $this->get_linked_mail_form($current_page_id);
    if (empty($linked_mail_form)) {
      return;
    }

    //入力・確認・完了画面のページIDを取得する
    $page_ids = $this->get_form_page_ids($linked_mail_form);

    //完了画面でなければ設置しない
    if ($current_page_id !== (int)$page_ids['complete']) {
      return;
    }

    wp_enqueue_script('omf-disable-back-button-script', plugins_url('/dist/js/disable-back-button.js', __FILE__), [], '1.0', true);
  }
}

new OMF_Plugin();
