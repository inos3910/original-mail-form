<?php

/**
 * Plugin Name: Original Mail Form
 * Plugin URI:
 * Description: メールフォーム設定プラグイン（クラシックテーマ用）
 * Author: SHARESL
 * Author URI: https://sharesl.net/
 * Version: 1.0
 */

class Original_Mail_Forms
{

  /**
   * セッション接頭辞
   * @var string
   */
  private $session_prefix;

  /**
   * nonceのアクション名
   * @var string
   */
  private $nonce_action;

  /**
   * 送信データセッション名
   * @var string
   */
  private $post_data_session_name;

  /**
   * 認証セッション名
   * @var string
   */
  private $auth_session_name;

  /**
   * エラーセッション名
   * @var string
   */
  private $errors_session_name;

  /**
   * フォーム画面のパス
   * @var array
   */
  private $form_page_pathes = [];

  /**
   * construct
   */
  public function __construct()
  {
    require_once plugin_dir_path(__FILE__) . 'classes/class-config.php';
    add_action('plugins_loaded', [$this, 'plugins_loaded']);
  }

  /**
   * 初期化
   */
  public function plugins_loaded()
  {
    //テンプレート
    add_action('parse_request', [$this, 'init_sessions'], 11);
    add_action('template_redirect', [$this, 'redirect_form_pages']);
    add_action('wp_enqueue_scripts', [$this, 'load_recaptcha_script']);
    //管理画面
    add_action('admin_menu', [$this, 'add_admin_setting_menu']);
    add_action('admin_menu', [$this, 'add_admin_recaptcha_menu']);
    add_action('init', [$this, 'create_post_type']);
    add_action('admin_enqueue_scripts', [$this, 'add_omf_scripts']);
    //REST API
    add_action('rest_api_init', [$this, 'add_custom_endpoint']);

    //フィルターフック追加
    $this->add_filter_hooks();

    //テンプレ側から使うデータ取得Class
    require_once plugin_dir_path(__FILE__) . 'classes/class-omf.php';
  }

  /**
   * REST API判定
   * @return boolean
   */
  public function is_rest()
  {
    return (defined('REST_REQUEST') && REST_REQUEST);
  }

  /**
   * REST APIエンドポイント追加
   */
  public function add_custom_endpoint()
  {
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
      $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field($_SERVER['HTTP_X_WP_NONCE']) : '';
      $auth = wp_verify_nonce($nonce, 'wp_rest');

      //nonce認証OK
      if ($auth) {
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
      }
      //nonce認証NG
      else {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
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
      $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field($_SERVER['HTTP_X_WP_NONCE']) : '';
      $auth = wp_verify_nonce($nonce, 'wp_rest');

      //nonce認証OK
      if ($auth) {
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
        $session_prefix = OMF_Config::PREFIX . "{$linked_mail_form->post_name}";
        //認証セッション名を更新
        $auth_session_name = "{$session_prefix}_auth";
        $_SESSION[$auth_session_name] = true;
        session_write_close();

        //メールIDを追加
        $post_data['mail_id'] = $this->get_mail_id($linked_mail_form->ID);

        //自動返信メール送信処理
        $is_sended_reply = $this->send_reply_mail($post_data, $post_id);
        //管理者宛メール送信処理
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
            $errors['reply_mail'] = '自動返信メールの送信処理に失敗しました';
          }
          if (!$is_sended_admin) {
            $errors['admin_mail'] = '管理者宛メールの送信処理に失敗しました';
          }
          return [
            'is_sended' => $is_sended,
            'data' => $post_data,
            'errors' => $errors,
          ];
        }
      }
      //nonce認証NG
      else {
        return new WP_Error('failed', __('認証NG'), ['status' => 404]);
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
    if (is_wp_error($response)) {
      $error_data = $response->get_error_data();
      $status = !empty($error_data['status']) ? $error_data['status'] : 404;
      $response->set_status($status);
    } else {
      $response->set_status(200);
    }
    return $response;
  }

  /**
   * セッション有効化
   */
  public function init_sessions()
  {
    if (!$this->is_rest() && session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
      header('Expires:-1');
      header('Cache-Control:');
      header('Pragma:');
    }
  }

  /**
   * フィルターフック追加
   */
  public function add_filter_hooks()
  {
    add_filter('omf_get_errors', [$this, 'get_errors']);
    add_filter('omf_get_post_values', [$this, 'get_post_values']);
    add_filter('omf_nonce_field', [$this, 'nonce_field']);
    add_filter('omf_create_nonce', [$this, 'create_nonce']);
    add_filter('omf_recaptcha_field', [$this, 'recaptcha_field']);
  }

  //JSを追加
  public function add_omf_scripts()
  {
    global $post_type;
    if ($post_type === OMF_Config::NAME) {
      wp_enqueue_script('omf-script', plugins_url('/dist/js/main.js', __FILE__), [], '1.0', true);
    }
  }

  /**
   * エラーセッションを取得
   * @return [type] [description]
   */
  public function get_errors()
  {
    $errors = [];
    if (empty($this->errors_session_name)) {
      return $errors;
    }

    if (!empty($_SESSION[$this->errors_session_name])) {
      $errors = $_SESSION[$this->errors_session_name];
      unset($_SESSION[$this->errors_session_name]);
    }

    return $errors;
  }

  /**
   * 送信データを取得
   * @return Object
   */
  public function get_post_values()
  {
    if (empty($_SESSION[$this->post_data_session_name])) {
      return [];
    }

    $data = $_SESSION[$this->post_data_session_name];

    return $data;
  }

  /**
   * nonceフィールドを出力する
   */
  public function nonce_field()
  {
    wp_nonce_field($this->nonce_action, 'omf_nonce', false);
  }

  /**
   * nonceを生成する
   * @return String
   */
  public function create_nonce()
  {
    return wp_create_nonce('wp_rest');
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

    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? get_option('omf_recaptcha_field_name') : 'g-recaptcha-response';
?>
    <input type="hidden" name="<?php echo esc_attr($recaptcha_field_name) ?>" id="g-recaptcha-response">
  <?php
  }

  /**
   * ページと連携しているメールフォームのスラッグを取得
   * @param  int|string $post_id $post_id ページID
   * @return string スラッグ
   */
  public function get_linked_mail_form_slug($post_id = null)
  {
    $current_page_id = !empty($post_id) ? $post_id : get_the_ID();
    if (empty($current_page_id)) {
      return;
    }

    $linked_mail_form_slug = get_post_meta($current_page_id, 'cf_omf_select', true);
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
  public function get_linked_mail_form($post_id = null)
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
   * フォームを設置するページのパス
   * @param  WP_Post $linked_mail_form メールフォームページのオブジェクト
   * @return array
   */
  public function get_form_page_paths($linked_mail_form)
  {
    $paths = [];

    $linked_mail_form = !empty($linked_mail_form) ? $linked_mail_form : $this->get_linked_mail_form();
    if (empty($linked_mail_form)) {
      return $paths;
    }

    $paths['entry']    = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_entry', true);
    $paths['confirm']  = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_confirm', true);
    $paths['complete'] = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_complete', true);

    return $paths;
  }

  /**
   * フォームを設置するページのIDを取得
   * @param  WP_Post $linked_mail_form メールフォームページのオブジェクト
   * @return array
   */
  public function get_form_page_ids($linked_mail_form)
  {
    $ids = [];

    $linked_mail_form = !empty($linked_mail_form) ? $linked_mail_form : $this->get_linked_mail_form();
    if (empty($linked_mail_form)) {
      return $ids;
    }

    $entry_page_path    = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_entry', true);
    $confirm_page_path  = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_confirm', true);
    $complete_page_path = get_post_meta($linked_mail_form->ID, 'cf_omf_screen_complete', true);

    $ids['entry']       = url_to_postid($entry_page_path);
    $ids['confirm']     = url_to_postid($confirm_page_path);
    $ids['complete']    = url_to_postid($complete_page_path);

    return $ids;
  }

  /**
   * メールフォームのセッションをクリアする
   */
  public function clear_sessions()
  {
    //送信データセッションを破棄
    if (!empty($_SESSION[$this->post_data_session_name])) {
      unset($_SESSION[$this->post_data_session_name]);
    }

    //認証セッションを破棄
    if (!empty($_SESSION[$this->auth_session_name])) {
      unset($_SESSION[$this->auth_session_name]);
    }

    //エラーセッションを破棄
    if (!empty($_SESSION[$this->errors_session_name])) {
      unset($_SESSION[$this->errors_session_name]);
    }
  }


  //フォームのリダイレクト
  public function redirect_form_pages()
  {
    //管理画面・投稿・固定ページ以外無効
    if (is_admin() || (!is_page() && !is_single())) {
      return;
    }

    //現在のページのID取得
    $current_page_id   = get_the_ID();

    //連携するメールフォームを取得
    $linked_mail_form  = $this->get_linked_mail_form($current_page_id);
    if (empty($linked_mail_form)) {
      return;
    }

    //セッション名を更新
    $is_session_names = $this->update_sesion_names($linked_mail_form);
    if (!$is_session_names) {
      return;
    }

    //入力・確認・完了画面のページパスを取得する
    $page_pathes = $this->get_form_page_paths($linked_mail_form);
    if (
      empty($page_pathes['entry']) ||
      empty($page_pathes['confirm']) ||
      empty($page_pathes['complete'])
    ) {
      $this->clear_sessions();
      return;
    }

    //入力・確認・完了画面のページIDを取得する
    $page_ids = $this->get_form_page_ids($linked_mail_form);
    if (
      empty($page_ids['entry']) ||
      empty($page_ids['confirm']) ||
      empty($page_ids['complete'])
    ) {
      $this->clear_sessions();
      return;
    }

    //フォーム入力ページ
    if ($current_page_id === $page_ids['entry']) {
      $this->contact_enter_page_redirect($page_pathes);
    }
    //確認画面
    elseif ($current_page_id === $page_ids['confirm']) {
      $this->contact_confirm_page_redirect($page_pathes);
    }
    //送信完了画面
    elseif ($current_page_id === $page_ids['complete']) {
      $this->contact_complete_page_redirect($page_pathes);
    }
    //それ以外のページ
    else {
      //セッションを破棄
      $this->clear_sessions();
      return;
    }
  }

  /**
   * セッション名を更新
   * @param  WP_Post $linked_mail_form
   * @return boolean
   */
  public function update_sesion_names($linked_mail_form)
  {
    $linked_mail_form = !empty($linked_mail_form) ? $linked_mail_form : $this->get_linked_mail_form();
    if (empty($linked_mail_form)) {
      return false;
    }

    //セッション接頭辞を一意にする
    $this->session_prefix = OMF_Config::PREFIX . "{$linked_mail_form->post_name}";

    //nonceアクション名を更新
    global $post;
    $this->nonce_action = "{$this->session_prefix}_{$post->ID}";

    //送信データセッション名を更新
    $this->post_data_session_name = "{$this->session_prefix}_data";

    //認証セッション名を更新
    $this->auth_session_name = "{$this->session_prefix}_auth";

    //エラーセッション名を更新
    $this->errors_session_name = "{$this->session_prefix}_errors";

    return true;
  }


  /**
   * 入力ページ（初期ページ）のリダイレクト処理
   * @param  array $page_pathes
   */
  public function contact_enter_page_redirect($page_pathes)
  {
    //フォーム認証
    $nonce   = filter_input(INPUT_POST, 'omf_nonce', FILTER_SANITIZE_SPECIAL_CHARS);
    $auth    = wp_verify_nonce($nonce, $this->nonce_action);
    //nonce認証OK
    if ($auth) {
      //各要素を取得
      $post_data = !empty($_POST) ? array_map([$this, 'custom_escape'], $_POST) : [];

      //検証
      $errors = $this->validate_mail_form_data($post_data);
      //エラーがある場合は入力画面に戻す
      if (!empty($errors)) {
        $_SESSION[$this->errors_session_name] = $errors;
        return;
      }

      //送信データをセッションで保持
      $_SESSION[$this->post_data_session_name] = $this->filter_post_keys($post_data);

      // 検証OKの場合は確認画面へ
      session_regenerate_id(true);
      //認証フラグを立てる
      $_SESSION[$this->auth_session_name] = true;
      wp_safe_redirect(esc_url(home_url($page_pathes['confirm'])));
      exit;
    }
    //nonce認証NG
    else {
      //セッションを破棄
      if (!empty($_SESSION[$this->auth_session_name])) {
        unset($_SESSION[$this->auth_session_name]);
      }

      //戻るボタン・エラー以外
      if (
        filter_input(INPUT_POST, 'submit_back', FILTER_SANITIZE_SPECIAL_CHARS) !== "back"
        && empty($_SESSION[$this->errors_session_name])
      ) {
        if (!empty($_SESSION[$this->post_data_session_name])) {
          unset($_SESSION[$this->post_data_session_name]);
        }
      }

      return;
    }
  }

  // 不要な送信データをフィルターする
  public function filter_post_keys($post_data)
  {
    if (empty($post_data)) {
      return;
    }

    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? get_option('omf_recaptcha_field_name') : 'g-recaptcha-response';
    $remove_keys = ['omf_nonce', '_wp_http_referer', $recaptcha_field_name];
    $post_data = array_diff_key($post_data, array_flip($remove_keys));

    return $post_data;
  }

  /**
   * 確認画面のリダイレクト処理
   * @param  array $page_pathes
   */
  public function contact_confirm_page_redirect($page_pathes)
  {
    //セッションがある場合
    if (
      !empty($_SESSION[$this->auth_session_name])
      && $_SESSION[$this->auth_session_name] === true
      && !empty($_SESSION[$this->post_data_session_name])
    ) {
      //戻るボタンの場合
      if (filter_input(INPUT_POST, 'submit_back', FILTER_SANITIZE_SPECIAL_CHARS) === "back") {
        //入力画面に戻す
        wp_safe_redirect(esc_url(home_url($page_pathes['entry'])), 307);
        exit;
      }
      //メール送信の場合
      elseif (filter_input(INPUT_POST, 'omf_nonce')) {

        //reCAPTCHAが設定されているか判定
        $is_recaptcha = $this->can_use_recaptcha();
        if ($is_recaptcha) {
          //reCAPTCHA認証を実行
          $recaptcha = $this->verify_google_recaptcha();
          //認証失敗時はエラーメッセージを追加して入力画面にリダイレクト
          if (!$recaptcha) {
            $_SESSION[$this->errors_session_name] = ['reCAPTCHA認証に失敗しました。'];
            //入力画面に戻す
            wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
            exit;
          }
        }

        // メール送信処理
        $send = $this->send($page_pathes);

        //メール送信完了の場合
        if ($send === true) {
          //完了画面にリダイレクト
          wp_safe_redirect(esc_url(home_url($page_pathes['complete'])));
          exit;
        }
        //メール送信失敗の場合
        else {
          //エラーメッセージを追加して入力画面にリダイレクト
          $_SESSION[$this->errors_session_name] = [
            'send' => ['送信中にエラーが発生しました。お手数ですが、再度送信をお試しください']
          ];
          wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
          exit;
        }
      }
      //戻るボタンかメール送信以外の場合
      else {
        return;
      }
    }
    //セッションがない場合
    else {
      //入力画面に戻す
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }
  }

  /**
   * 完了画面のリダイレクト処理
   * @param  array $page_pathes
   */
  public function contact_complete_page_redirect($page_pathes)
  {
    //セッションがある場合
    if (!empty($_SESSION[$this->auth_session_name]) && $_SESSION[$this->auth_session_name] === true) {
      //セッションを破棄
      $this->clear_sessions();
      return;
    }
    //セッションがない場合
    else {
      //入力画面に戻す
      wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
      exit;
    }
  }


  //フォームデータの検証
  public function validate_mail_form_data($post_data, $post_id = null)
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


    //バリデーション設定
    foreach ((array)$validations as $valid) {
      $error_message = $this->validate($post_data, $valid);
      if (!empty($error_message)) {
        $errors[$valid['target']] = $error_message;
      }
    }

    //reCAPTCHA
    $is_recaptcha = $this->can_use_recaptcha($post_id);
    if ($is_recaptcha) {
      $recaptcha = $this->verify_google_recaptcha();
      if (!$recaptcha) {
        $errors['recaptcha'] = ['reCAPTCHA認証に失敗しました。'];
      }
    }

    return $errors;
  }

  /**
   * データを検証
   * @param  Array $post_data 検証するデータ
   * @param  String $validation 検証条件
   * @return array エラー文
   */
  public function validate($post_data, $validation)
  {
    if (empty($validation)) {
      return;
    }

    $post_key = $validation['target'];
    $data     = !empty($post_data[$post_key]) ? $post_data[$post_key] : '';

    $errors = [];

    foreach ((array)$validation as $key => $value) {
      //最小文字数
      if ($key === 'min') {
        $error_message = $this->validate_min($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //最大文字数
      elseif ($key === 'max') {
        $error_message = $this->validate_max($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //必須
      elseif ($key === 'required') {
        $error_message = $this->validate_required($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //電話番号
      elseif ($key === 'tel') {
        $error_message = $this->validate_tel($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //メールアドレス
      elseif ($key === 'email') {
        $error_message = $this->validate_email($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //URL
      elseif ($key === 'url') {
        $error_message = $this->validate_url($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角数字
      elseif ($key === 'numeric') {
        $error_message = $this->validate_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英字
      elseif ($key === 'alpha') {
        $error_message = $this->validate_alpha($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英数字
      elseif ($key === 'alphanumeric') {
        $error_message = $this->validate_alpha_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ
      elseif ($key === 'katakana') {
        $error_message = $this->validate_katakana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ひらがな
      elseif ($key === 'hiragana') {
        $error_message = $this->validate_hiragana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ or ひらがな
      elseif ($key === 'kana') {
        $error_message = $this->validate_kana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ThrowsSpamAway
      elseif ($key === 'throws_spam_away') {
        $error_message = $this->validate_throws_spam_away($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //一致する文字
      elseif ($key === 'matching_char') {
        $error_message = $this->validate_matching_char($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      } else {
        continue;
      }
    }
    return $errors;
  }

  /**
   * 最小文字数を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_min($data, $value)
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (mb_strlen($data) <= intval($value)) {
      $error = "{$value}文字以上入力してください";
    }
    return $error;
  }

  /**
   * 最大文字数を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_max($data, $value)
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (mb_strlen($data) >= intval($value)) {
      $error = "{$value}文字以内で入力してください";
    }
    return $error;
  }

  /**
   * 必須項目を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_required($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if (empty($data)) {
      $error = "必須項目です";
    }

    return $error;
  }

  /**
   * 電話番号を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_tel($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_tel = !empty($data) ? filter_var($data, FILTER_VALIDATE_REGEXP, [
      'options' => [
        'regexp' => '/\A(((0(\d{1}[-(]?\d{4}|\d{2}[-(]?\d{3}|\d{3}[-(]?\d{2}|\d{4}[-(]?\d{1}|[5789]0[-(]?\d{4})[-)]?)|\d{1,4}\-?)\d{4}|0120[-(]?\d{3}[-)]?\d{3})\z/'
      ]
    ]) : false;

    if (!$is_tel) {
      $error = "電話番号の形式で入力してください";
    }

    return $error;
  }

  /**
   * メールアドレスを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_email($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_email = !empty($data) ? filter_var($data, FILTER_VALIDATE_EMAIL) : false;
    if (!$is_email) {
      $error = "正しいメールアドレスを入力してください";
    }

    return $error;
  }

  /**
   * URLを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_url($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_url = !empty($data) ? filter_var($data, FILTER_VALIDATE_URL) : false;
    if (!$is_url) {
      $error = "URLの形式で入力してください";
    }

    return $error;
  }

  /**
   * 半角数字を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_numeric($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_numeric = !empty($data) ? preg_match('/^[0-9]+$/', $data) === 1 : false;
    if (!$is_numeric) {
      $error = "半角数字で入力してください";
    }

    return $error;
  }

  /**
   * 半角英字を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_alpha($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_alpha = !empty($data) ? preg_match('/^[a-zA-Z]+$/', $data) === 1 : false;
    if (!$is_alpha) {
      $error = "半角英字で入力してください";
    }

    return $error;
  }

  /**
   * 半角英数字を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_alpha_numeric($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_alpha_numeric = !empty($data) ? preg_match('/^[a-zA-Z0-9]+$/', $data) === 1 : false;
    if (!$is_alpha_numeric) {
      $error = "半角英数字で入力してください";
    }

    return $error;
  }

  /**
   * カタカナを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_katakana($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_katakana = !empty($data) ? preg_match('/^[ァ-ヶー]+$/u', $data) === 1 : false;
    if (!$is_katakana) {
      $error = "全角カタカナで入力してください";
    }

    return $error;
  }

  /**
   * ひらがなを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_hiragana($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_hiragana = !empty($data) ? preg_match('/^[ぁ-んー]+$/u', $data) === 1 : false;
    if (!$is_hiragana) {
      $error = "ひらがなで入力してください";
    }

    return $error;
  }


  /**
   * カタカナ or ひらがなを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_kana($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_kana = !empty($data) ? preg_match('/^[ァ-ヾぁ-んー]+$/u', $data) === 1 : false;
    if (!$is_kana) {
      $error = "全角カタカナもしくはひらがなで入力してください";
    }

    return $error;
  }


  /**
   * Throws SPAM Awayを検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_throws_spam_away($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $check_spam = $this->throws_spam_away($data);

    //スパム判定された場合
    if ($check_spam['valid'] === false) {
      $error = $check_spam['message'];
    }

    return $error;
  }


  /**
   * 一致する文字列を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public function validate_matching_char($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    //一致させる文字列を配列で取得
    $words = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!empty($words) && !in_array($data, $words, true)) {
      $error = '不正な値が送信されました。';
    }

    return $error;
  }




  /**
   * Throws SPAM Awayプラグインの検証処理を追加
   * @param  string $value 検証する文字列
   * @return array 検証結果
   */
  public function throws_spam_away($value)
  {
    //ファイルの存在確認
    $filename = WP_PLUGIN_DIR . '/throws-spam-away/throws_spam_away.class.php';

    $result['valid'] = true;

    if (!file_exists($filename)) {
      return $result;
    }

    //ファイルが存在する場合は読み込み
    include_once($filename);

    //クラスが存在しない場合
    if (!class_exists('ThrowsSpamAway')) {
      return $result;
    }

    $throwsSpamAway = new ThrowsSpamAway();

    $args = [];
    $value = esc_attr($value);

    if (!empty($value)) {

      // IPアドレスチェック
      $ip = $_SERVER['REMOTE_ADDR'];
      // 許可リスト判定
      // $white_ip_check = !$throwsSpamAway->white_ip_check( $ip );

      // 拒否リスト判定
      $chk_ip = $throwsSpamAway->ip_check($ip);

      // 許可リストに入っていないまたは拒否リストに入っている場合はエラー
      // if ( ! $white_ip_check || ! $chk_ip ) {

      // 許可リストに入っていない場合はエラー
      //  if ( ! $white_ip_check ) {

      // 拒否リストに入っている場合はエラー
      if (!$chk_ip) {
        $result['valid']  = false;
        $result['message'] = '不明なエラーで送信できません';
        return $result;
      }

      // IPアドレスチェックを超えた場合は通常のスパムチェックが入ります。
      $chk_result = $throwsSpamAway->validate_comment("", $value, $args);

      // エラーがあればエラー文言返却
      if (!$chk_result) {
        // エラータイプを取得
        $error_type = $throwsSpamAway->error_type;
        $message_str = "";
        /**
         エラー種類
        'must_word'         必須キーワード
        'ng_word'           NGキーワード
        'url_count_over'    リンク数オーバー
        'not_japanese'      日本語不足
         */
        switch ($error_type) {
          case "must_word":
            $message_str = "必須キーワードが入っていないため送信出来ません ";
            break;
          case "ng_word":
            $message_str = "NGキーワードが含まれているため送信できません ";
            break;
          case "url_count_over":
            $message_str = "リンクが多すぎます ";
            break;
          case "not_japanese":
            $message_str = "日本語が含まれないか日本語文字数が少ないため送信出来ません ";
            break;
          default:
            $message_str = "エラーが発生しました:" . $error_type;
        }
        $result['valid'] = false;
        $result['message'] = $message_str;
        return $result;
      }
    }

    return $result;
  }


  /**
   * reCAPTCHA設定の有無を判定
   * @return boolean
   */
  public function can_use_recaptcha($post_id = null)
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

    $is_recaptcha = get_post_meta($linked_mail_form->ID, 'cf_omf_recaptcha', true);
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
  public function verify_google_recaptcha()
  {
    $recaptcha_secret = get_option('omf_recaptcha_secret_key');
    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? get_option('omf_recaptcha_field_name') : 'g-recaptcha-response';
    $recaptcha_response = filter_input(INPUT_POST, $recaptcha_field_name, FILTER_SANITIZE_SPECIAL_CHARS);

    // APIリクエスト
    $recaptch_url = 'https://www.google.com/recaptcha/api/siteverify';
    $recaptcha_params = [
      'secret' => $recaptcha_secret,
      'response' => $recaptcha_response,
    ];
    $request_params_query =  http_build_query($recaptcha_params);
    $endpoint = "{$recaptch_url}?{$request_params_query}";

    $verify_response = $this->curl_get_contents($endpoint);

    // APIレスポンス確認
    $response_data = json_decode($verify_response);

    //成功
    if (!empty($response_data) && $response_data->success && $response_data->score >= 0.5) {
      return true;
    }
    //失敗
    else {
      return false;
    }
  }

  /**
   * curlでデータ取得する関数
   * @param  string  $url
   * @param  integer $timeout タイムアウト（秒）
   */
  public function curl_get_contents($url, $timeout = 60)
  {
    $ch = curl_init();
    $header = [
      "Content-Type: application/json"
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    //テストサーバー
    if ($_SERVER['SERVER_NAME'] === 'testii.mixh.jp') {
      $username = 'sharesl';
      $password = 'sharesl-test';
      curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
    }

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
  }

  /**
   * 送信処理
   * @return boolean
   */
  public function send($page_pathes)
  {

    //フォーム認証
    $nonce = filter_input(INPUT_POST, 'omf_nonce', FILTER_SANITIZE_SPECIAL_CHARS);
    $auth  = wp_verify_nonce($nonce, $this->nonce_action);

    //認証OKの場合
    if ($auth) {

      //各要素を取得
      $post_data = !empty($_SESSION[$this->post_data_session_name]) ? array_map([$this, 'custom_escape'], $_SESSION[$this->post_data_session_name]) : [];

      //検証
      $errors = $this->validate_mail_form_data($post_data);
      //エラーがある場合は入力画面に戻す
      if (!empty($errors)) {
        $_SESSION[$this->errors_session_name] = $errors;
        wp_safe_redirect(esc_url(home_url($page_pathes['entry'])));
        exit;
      }

      //メールフォームを取得
      $linked_mail_form = $this->get_linked_mail_form();
      //メールID
      $post_data['mail_id'] = $this->get_mail_id($linked_mail_form->ID);

      //自動返信メール送信処理
      $is_sended_reply = $this->send_reply_mail($post_data);
      //管理者宛メール送信処理
      $is_sended_admin = $this->send_admin_mail($post_data);

      $is_sended = $is_sended_reply && $is_sended_admin;
      if ($is_sended) {
        //送信後にメールIDを更新
        $this->update_mail_id($linked_mail_form->ID, $post_data['mail_id']);
      }

      return $is_sended;
    } else {
      return false;
    }
  }

  /**
   * エスケープ処理
   * @param  string $input
   * @return string
   */
  public function custom_escape($input)
  {
    $input = wp_strip_all_tags($input);
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
  }

  /**
   * メールIDを取得
   * @param  int $form_id
   * @return int
   */
  public function get_mail_id($form_id)
  {
    $mail_id = get_post_meta($form_id, 'cf_omf_mail_id', true);
    if (empty($mail_id)) {
      $mail_id = 1;
    } else {
      $mail_id = intval(sanitize_text_field($mail_id));
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
  public function update_mail_id($form_id, $mail_id)
  {
    return  update_post_meta($form_id, 'cf_omf_mail_id', $mail_id);
  }

  /**
   * 自動返信メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @return boolean
   */
  public function send_reply_mail($post_data, $post_id = null)
  {

    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return false;
    }

    //メール情報を取得
    $form_title    = get_post_meta($linked_mail_form->ID, 'cf_omf_reply_title', true);
    $mail_to       = get_post_meta($linked_mail_form->ID, 'cf_omf_reply_to', true);
    $mail_template = get_post_meta($linked_mail_form->ID, 'cf_omf_reply_mail', true);
    $mail_from     = get_post_meta($linked_mail_form->ID, 'cf_omf_reply_from', true);
    $from_name     = get_bloginfo('name');

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html($this->get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/'))
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    //件名
    $reply_subject     = $this->replace_form_mail_tags($form_title, $tag_to_text);
    //宛先
    $reply_mailaddress = $this->replace_form_mail_tags($mail_to, $tag_to_text);
    //メール本文のifタグを置換
    $mail_template     = $this->replace_form_mail_if_tags($mail_template, $tag_to_text);
    //メールタグを置換
    $reply_message     = $this->replace_form_mail_tags($mail_template, $tag_to_text);
    //メールヘッダー
    $reply_headers[]   = "From: {$from_name} <{$mail_from}>";
    $reply_headers[]   = "Reply-To: {$from_name} <{$mail_from}>";

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

    return $is_sended_reply;
  }

  /**
   * 管理者宛メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @return boolean
   */
  public function send_admin_mail($post_data, $post_id = null)
  {
    $linked_mail_form = $this->get_linked_mail_form($post_id);
    if (empty($linked_mail_form)) {
      return false;
    }

    //メール情報を取得
    $form_title    = get_post_meta($linked_mail_form->ID, 'cf_omf_admin_title', true);
    $mail_to       = get_post_meta($linked_mail_form->ID, 'cf_omf_admin_to', true);
    $mail_template = get_post_meta($linked_mail_form->ID, 'cf_omf_admin_mail', true);
    $mail_from     = get_post_meta($linked_mail_form->ID, 'cf_omf_admin_from', true);
    $from_name     = get_bloginfo('name');

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

    //件名
    $admin_subject     = $this->replace_form_mail_tags($form_title, $tag_to_text);
    //宛先
    $admin_mailaddress = $this->replace_form_mail_tags($mail_to, $tag_to_text);
    //メール本文のifタグを置換
    $mail_template     = $this->replace_form_mail_if_tags($mail_template, $tag_to_text);
    //メールタグを置換
    $admin_message     = $this->replace_form_mail_tags($mail_template, $tag_to_text);
    //メールヘッダー
    $admin_headers[]   = "From: {$from_name} <{$mail_from}>";
    $admin_headers[]   = "Reply-To: {$from_name} <{$mail_from}>";

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

    return $is_sended_admin;
  }

  /**
   * 日時取得
   * @return string
   */
  public function get_current_datetime()
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
  public function replace_form_mail_if_tags($text, $tags)
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
  public function replace_form_mail_tags($text, $tag_to_text)
  {
    if (empty($text)) {
      return;
    }

    preg_match_all('/\{(.+?)\}/', $text, $matches);

    if (!empty($matches[1])) {
      foreach ($matches[1] as $tag) {
        $replacementText = isset($tag_to_text[$tag]) ? $tag_to_text[$tag] : '';
        if (empty($replacementText)) {
          continue;
        }

        $text = str_replace("{" . $tag . "}", $replacementText, $text);
      }
    }

    return $text;
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
    $recaptcha_site_key = get_option('omf_recaptcha_site_key');
    wp_enqueue_script('recaptcha-script', "https://www.google.com/recaptcha/api.js?render={$recaptcha_site_key}", [], null, true);

    $custom_script = "
    grecaptcha.ready(function() {
      grecaptcha.execute('{$recaptcha_site_key}',
      {action: 'homepage' })
      .then(function(token) {
        var recaptchaResponse = document.getElementById('g-recaptcha-response');
        recaptchaResponse.value = token;
        });
      }
    );";
    wp_add_inline_script('recaptcha-script', $custom_script);

    //サイトキーを変数で出力
    $recaptcha_site_key_script = "
    var reCAPTCHA_site_key = '{$recaptcha_site_key}';
    ";
    wp_add_inline_script('recaptcha-script', $recaptcha_site_key_script, 'before');
  }

  /**
   * 設定オプションページを追加
   * @return [type] [description]
   */
  public function add_admin_setting_menu()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      'プラグインの更新',
      'プラグインの更新',
      'manage_options',
      'omf_settings',
      [$this, 'admin_omf_settings_page']
    );
  }

  /**
   * 設定オプション画面のソース
   */
  public function admin_omf_settings_page()
  {
    if (filter_input(INPUT_POST, 'update_omf', FILTER_SANITIZE_NUMBER_INT) === '1') {
      $this->update_plugin_from_github();
    }
  ?>
    <h1>プラグインの更新</h1>
    <div class="admin_optional">
      <form method="post" action="">
        <p>Github上で管理している最新のmasterブランチのファイルに更新します。</p>
        <p><a href="https://github.com/inos3910/original-mail-form" target="_blank" rel="noopener">GitHubリポジトリはこちら →</a></p>
        <button class="button" type="submit" name="update_omf" value="1">更新開始</button>
      </form>
    </div>
  <?php
  }

  /**
   * プラグインをmasterブランチに更新
   *
   * @return void
   */
  public function update_plugin_from_github()
  {
    $github_repo_url = 'https://github.com/inos3910/original-mail-form/archive/master.zip';
    $plugin_dir = plugin_dir_path(__FILE__);

    $response = wp_safe_remote_get($github_repo_url);

    if (is_wp_error($response)) {
      echo '<div class="error"><p>GitHubからファイルを取得する際にエラーが発生しました。</p></div>';
    }

    $zip_content = wp_remote_retrieve_body($response);
    $temp_zip_path = sys_get_temp_dir() . '/github-update.zip';

    if (!file_put_contents($temp_zip_path, $zip_content)) {
      echo '<div class="error"><p>一時ファイルの保存に失敗しました。</p></div>';
    }

    // ZIPファイルの中身をプラグインディレクトリにコピーし、上書き
    $zip = new ZipArchive;
    if ($zip->open($temp_zip_path) === true) {
      //除外ファイル
      $excluded_files = ['.gitignore', 'package.json', 'package-lock.json', 'yarn.lock', 'webpack.config.js'];
      $files_to_extract = [];
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $file_info = $zip->statIndex($i);
        if (!in_array(basename($file_info['name']), $excluded_files)) {
          $files_to_extract[] = $file_info['name'];
        }
      }
      $zip->extractTo($plugin_dir, $files_to_extract);
      $zip->close();
    }

    unlink($temp_zip_path);

    //ファイルの移動
    $target_dir = $plugin_dir . "original-mail-form-master/";
    $this->moveFiles($target_dir, $plugin_dir);

    // メッセージを表示
    echo '<div class="updated"><p>プラグインが更新されました。</p></div>';
  }

  /**
   * 指定フォルダ内の中身をすべて任意の場所に移動
   *
   * @param [type] $source 指定フォルダ
   * @param [type] $destination 任意の場所
   * @return void
   */
  public function moveFiles($source, $destination)
  {
    if (!is_dir($source)) {
      return;
    }

    if (!is_dir($destination)) {
      mkdir($destination, 0755, true);
    }

    if ($handle = opendir($source)) {
      while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
          $sourcePath = $source . '/' . $entry;
          $destinationPath = $destination . '/' . $entry;

          if (is_dir($sourcePath)) {
            $this->moveFiles($sourcePath, $destinationPath);
          } else {
            if (file_exists($destinationPath)) {
              // 移動先のファイルが存在する場合は削除
              unlink($destinationPath);
            }
            rename($sourcePath, $destinationPath);
          }
        }
      }

      closedir($handle);
    }

    //ディレクトリ削除
    rmdir($source);
  }

  /**
   * reCAPTCHA設定オプションページを追加
   * @return [type] [description]
   */
  public function add_admin_recaptcha_menu()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      'reCAPTCHA設定',
      'reCAPTCHA設定',
      'manage_options',
      'recaptcha_settings',
      [$this, 'admin_recaptcha_settings_page']
    );
    add_action('admin_init', [$this, 'register_recaptcha_settings']);
  }

  /**
   * reCAPTCHA設定オプションページ 項目の登録
   */
  public function register_recaptcha_settings()
  {
    register_setting('recaptcha-settings-group', 'omf_recaptcha_site_key');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_secret_key');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_score');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_field_name');
  }

  /**
   * reCAPTCHA設定オプション画面のソース
   */
  public function admin_recaptcha_settings_page()
  {
  ?>
    <h1>reCAPTCHA設定</h1>
    <div class="admin_optional">
      <form method="post" action="options.php">
        <?php
        settings_fields('recaptcha-settings-group');
        do_settings_sections('recaptcha-settings-group');
        $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? get_option('omf_recaptcha_field_name') : 'g-recaptcha-response';
        ?>
        <p><a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">reCAPTCHA v3 コンソールでキーを取得 →</a></p>
        <table class="form-table">
          <tr>
            <th scope="row">reCAPTCHA v3 サイトキー</th>
            <td>
              <p>
                <input class="regular-text code" type="text" name="omf_recaptcha_site_key" value="<?php echo esc_attr(get_option('omf_recaptcha_site_key')); ?>">
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row">reCAPTCHA v3 シークレットキー</th>
            <td>
              <p>
                <input class="regular-text code" type="text" name="omf_recaptcha_secret_key" value="<?php echo esc_attr(get_option('omf_recaptcha_secret_key')); ?>">
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row">しきい値（0.0 - 1.0）</th>
            <td>
              <p>
                <input class="small-text" type="number" pattern="\d*" min="0.0" max="1.0" step="0.1" name="omf_recaptcha_score" value="<?php echo esc_attr(get_option('omf_recaptcha_score')) ?>">
              </p>
              <p class="description">大きいほど判定が厳しくなる。デフォルトでは、0.5。</p>
            </td>
          </tr>
          <tr>
            <th scope="row">reCAPTCHAフィールド名</th>
            <td>
              <p>
                <input class="regular-text code" type="text" name="omf_recaptcha_field_name" value="<?php echo esc_attr($recaptcha_field_name); ?>">
              </p>
              <p class="description">フォーム内に出力されるinput要素のname属性を設定。デフォルトは「g-recaptcha-response」</p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
  <?php
  }

  /**
   * メールフォーム投稿タイプを作成
   */
  public function create_post_type()
  {
    $labels = [
      'name'                => 'メールフォーム',
      'singular_name'       => 'メールフォーム',
      'add_new'             => '新規追加',
      'add_new_item'        => '新規追加',
      'edit_item'           => 'フォーム設定を編集',
      'new_item'            => '新規追加',
      'view_item'           => 'フォームを表示',
      'search_items'        => 'フォームを検索',
      'not_found'           => 'フォームが見つかりません',
      'not_found_in_trash'  => 'ゴミ箱に記事はありません',
      'parent_item_colon'   => ''
    ];
    $args = [
      'labels'              => $labels,
      'has_archive'         => false,
      'public'              => false,
      'exclude_from_search' => true,
      'publicly_queryable'  => false,
      'show_ui'             => true,
      'query_var'           => true,
      'rewrite'             => [
        'with_front' => false
      ],
      'capability_type'     => 'page',
      'hierarchical'        => false,
      'menu_position'       => 5,
      'menu_icon'           => 'dashicons-email',
      'show_in_rest'        => false,
      'supports'            => [
        'title'
      ]
    ];
    //投稿タイプの登録
    register_post_type(OMF_Config::NAME, $args);
    //メタボックスの追加
    add_action('add_meta_boxes_' . OMF_Config::NAME, [$this, 'add_meta_box_omf']);
    add_action('add_meta_boxes', [$this, 'add_meta_box_posts']);
    //カスタムフィールドの保存
    add_action('save_post', [$this, 'save_omf_custom_field']);
    //CSSの追加
    add_action('admin_enqueue_scripts', [$this, 'custom_omf_styles']);
  }

  /**
   * CSSの追加
   */
  public function custom_omf_styles()
  {
    wp_enqueue_style('omf-admin-style', plugins_url('/dist/css/style.css', __FILE__));
  }

  /**
   * カスタムフィールドの保存
   * @param  [type] $post_id ページID
   */
  public function save_omf_custom_field($post_id)
  {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    //単一データの場合
    $update_meta_keys = [
      'cf_omf_reply_title',
      'cf_omf_reply_mail',
      'cf_omf_reply_to',
      'cf_omf_reply_from',
      'cf_omf_admin_title',
      'cf_omf_admin_mail',
      'cf_omf_admin_to',
      'cf_omf_admin_from',
      'cf_omf_condition_id',
      'cf_omf_select',
      'cf_omf_screen_entry',
      'cf_omf_screen_confirm',
      'cf_omf_screen_complete',
      'cf_omf_recaptcha',
      'cf_omf_mail_id'
    ];
    foreach ((array)$update_meta_keys as $key) {
      if (isset($_POST[$key])) {
        update_post_meta($post_id, $key, sanitize_textarea_field($_POST[$key]));
      }
    }

    //配列の場合
    $update_array_meta_keys = [
      'cf_omf_condition_post'
    ];

    //配列の場合
    foreach ((array)$update_array_meta_keys as $key) {
      if (isset($_POST[$key])) {
        $raw_array = $_POST[$key];
        $sanitized_array = array_map('sanitize_text_field', $raw_array);
        update_post_meta($post_id, $key, $sanitized_array);
      }
    }

    //バリデーションの場合
    $valid_key = 'cf_omf_validation';
    if (isset($_POST[$valid_key])) {
      $raw_validations = $_POST[$valid_key];
      $validations     = [];
      foreach ((array)$raw_validations as $key => $value) {
        $sanitized_validations = array_map('sanitize_text_field', $value);
        if (empty($sanitized_validations)) {
          continue;
        }

        $validations[] = $sanitized_validations;
      }

      if (!empty($validations)) {
        update_post_meta($post_id, $valid_key, $validations);
      }
    }
  }

  /**
   * メールフォーム投稿ページにメタボックスを追加
   */
  public function add_meta_box_omf()
  {
    //画面設定
    add_meta_box('omf-metabox-screen', '画面設定', [$this, 'screen_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //自動返信メール本文
    add_meta_box('omf-metabox-reply_mail', '自動返信メール', [$this, 'reply_mail_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //管理者宛メール本文
    add_meta_box('omf-metabox-admin_mail', '管理者宛メール', [$this, 'admin_mail_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //表示する投稿タイプ
    add_meta_box('omf-metabox-condition', '表示条件', [$this, 'condition_meta_box_callback'], OMF_Config::NAME, 'side', 'default');
    //recaptchaのオン・オフ
    add_meta_box('omf-metabox-recaptcha', 'reCAPTCHA設定', [$this, 'recaptcha_meta_box_callback'], OMF_Config::NAME, 'side', 'default');
    //バリデーション
    add_meta_box('omf-metabox-validation', 'バリデーション設定', [$this, 'validation_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //メールID
    add_meta_box('omf-metabox-mail_id', 'メールID', [$this, 'mail_id_meta_box_callback'], OMF_Config::NAME, 'side', 'default');
  }

  /**
   * 表示条件に合致した投稿・固定ページにメタボックスを追加する
   */
  public function add_meta_box_posts()
  {

    //すべてのフォームを取得
    $args = [
      'numberposts'   => -1,
      'post_type'     => OMF_Config::NAME,
      'post_status'   => 'publish',
      'no_found_rows' => true,
    ];
    $mail_forms = get_posts($args);
    if (empty($mail_forms)) {
      return;
    }

    //現在のページのID
    $current_post_id = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
    //現在のページの情報
    $current_screen = get_current_screen();

    foreach ((array)$mail_forms as $form) {
      //投稿タイプの条件取得
      $post_types = get_post_meta($form->ID, 'cf_omf_condition_post', true);
      if (empty($post_types)) {
        return;
      }

      //投稿タイプの条件判定
      $is_match_post_type = in_array($current_screen->post_type, $post_types, true);
      if (!$is_match_post_type) {
        return;
      }

      //特定のIDの条件取得
      $raw_specific_post_ids = get_post_meta($form->ID, 'cf_omf_condition_id', true);

      //特定のIDに入力がある場合
      if (!empty($raw_specific_post_ids)) {
        //特定のID
        $specific_post_ids = explode(",", $raw_specific_post_ids);
        $specific_post_ids = array_map('trim', $specific_post_ids);

        //IDが合致する場合のみ追加
        foreach ((array)$specific_post_ids as $specific_post_id) {
          if ((int)$current_post_id !== (int)$specific_post_id) {
            continue;
          }

          add_meta_box('omf-metabox-link_form', 'メールフォーム連携', [$this, 'select_mail_form_meta_box_callback'], $current_screen->post_type, 'side', 'default');
        }
      }
      //特定のIDに入力がない場合
      else {
        add_meta_box('omf-metabox-link_form', 'メールフォーム連携', [$this, 'select_mail_form_meta_box_callback'], $current_screen->post_type, 'side', 'default');
      }
    }
  }

  /**
   * バリデーション設定
   * @param WP_Post $post
   */
  public function validation_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_validation($post, 'バリデーション設定', 'cf_omf_validation');
      ?>
    </div>
  <?php
  }

  /**
   * reCAPTCHA設定
   * @param WP_Post $post
   */
  public function recaptcha_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_boolean($post, 'reCAPTCHAを設定する', 'cf_omf_recaptcha');
      ?>
    </div>
  <?php
  }

  /**
   * 画面設定
   * @param WP_Post $post
   */
  public function screen_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <p>▼フォームを設定できる画面は固定ページか投稿ページのどちらかです。</p>
      <?php
      $this->omf_meta_box_text($post, '入力画面', 'cf_omf_screen_entry');
      $this->omf_meta_box_text($post, '確認画面', 'cf_omf_screen_confirm');
      $this->omf_meta_box_text($post, '完了画面', 'cf_omf_screen_complete');
      ?>
    </div>
  <?php
  }

  /**
   * 自動返信メール
   * @param WP_Post $post
   */
  public function reply_mail_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_text($post, '件名', 'cf_omf_reply_title');
      $description = <<<EOD
      フォームのname属性に指定した値は{name}と指定してメールに反映可能。<br>
      その他、デフォルトで下記のタグを用意。<br>
      {send_datetime} : 送信日時（Y/m/d (曜日) H:i）<br>
      {mail_id} ： メールID（連番）<br>
      {site_name}：WordPressサイト名<br>
      {site_url}：WordPressサイトURL
      EOD;
      $this->omf_meta_box_textarea($post, '自動返信メール本文', 'cf_omf_reply_mail', $description);
      $this->omf_meta_box_text($post, '宛先', 'cf_omf_reply_to');
      $this->omf_meta_box_text($post, '送信元', 'cf_omf_reply_from');
      ?>
    </div>
  <?php
  }

  /**
   * 管理者宛メール
   * @param WP_Post $post
   */
  public function admin_mail_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_text($post, '件名', 'cf_omf_admin_title');
      $description = <<<EOD
      フォームのname属性に指定した値は{name}と指定してメールに反映可能。<br>
      その他、デフォルトで下記のタグを用意。<br>
      {send_datetime} : 送信日時（Y/m/d (曜日) H:i）<br>
      {mail_id} ： メールID（連番）<br>
      {site_name}：WordPressサイト名<br>
      {site_url}：WordPressサイトURL
      EOD;
      $this->omf_meta_box_textarea($post, '管理者宛メール本文', 'cf_omf_admin_mail', $description);
      $this->omf_meta_box_text($post, '宛先', 'cf_omf_admin_to');
      $this->omf_meta_box_text($post, '送信元', 'cf_omf_admin_from');
      ?>
    </div>
  <?php
  }

  /**
   * 表示条件
   * @param WP_Post $post
   */
  public function condition_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_post_types($post, '投稿タイプ', 'cf_omf_condition_post');
      $this->omf_meta_box_side_text($post, '投稿/固定ページID', 'cf_omf_condition_id');
      ?>
    </div>
  <?php
  }

  /**
   * メールID
   * @param WP_Post $post
   */
  public function mail_id_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_number($post, 'メールID', 'cf_omf_mail_id', 'メール本文内{mail_id}として使える。フォーム送信毎に自動で1ずつ増加する。');
      ?>
    </div>
  <?php
  }

  /**
   * メールフォームを選択
   * @param WP_Post $post
   */
  public function select_mail_form_meta_box_callback($post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_select_mail_forms($post, '連携するメールフォームを選択', 'cf_omf_select');
      ?>
    </div>
  <?php
  }

  /**
   * バリデーションのメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_validation($post, $title, $meta_key, $description = null)
  {
    $values = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox omf-metabox--repeat">
      <button class="omf-metabox__add-button" type="button" id="js-omf-repeat-add-button">項目を追加</button>
      <?php
      if (!empty($values)) :
        foreach ((array)$values as $key => $value) :
          $target           = !empty($value['target']) ? $value['target'] : '';
          $min              = !empty($value['min']) ? $value['min'] : '';
          $max              = !empty($value['max']) ? $value['max'] : '';
          $required         = !empty($value['required']) ? $value['required'] : '';
          $tel              = !empty($value['tel']) ? $value['tel'] : '';
          $email            = !empty($value['email']) ? $value['email'] : '';
          $url              = !empty($value['url']) ? $value['url'] : '';
          $numeric          = !empty($value['numeric']) ? $value['numeric'] : '';
          $alpha            = !empty($value['alpha']) ? $value['alpha'] : '';
          $alphanumeric     = !empty($value['alphanumeric']) ? $value['alphanumeric'] : '';
          $katakana         = !empty($value['katakana']) ? $value['katakana'] : '';
          $hiragana         = !empty($value['hiragana']) ? $value['hiragana'] : '';
          $kana             = !empty($value['kana']) ? $value['kana'] : '';
          $throws_spam_away = !empty($value['throws_spam_away']) ? $value['throws_spam_away'] : '';
          $matching_char    = !empty($value['matching_char']) ? $value['matching_char'] : '';
      ?>

          <div class="omf-metabox__list js-omf-repeat-field" data-omf-validation-count="<?php echo esc_attr($key) ?>" draggable="true">
            <div class="omf-metabox__head">
              <div class="omf-metabox__remove js-omf-remove"></div>
              <div class="omf-metabox__head__title js-omf-field-title"><?php echo esc_attr($target) ?></div>
              <div class="omf-metabox__toggle js-omf-toggle"></div>
            </div>
            <div class="omf-metabox__body js-omf-toggle-field">
              <div class="omf-metabox__row">
                <span>バリデーションする項目</span>
                <span>
                  <input class="js-omf-input-field-title" type="text" name="<?php echo esc_attr("{$meta_key}[{$key}][target]") ?>" value="<?php echo esc_attr($target) ?>">
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>最小文字数</span>
                <span>
                  <input type="text" name="<?php echo esc_attr("{$meta_key}[{$key}][min]") ?>" value="<?php echo esc_attr($min) ?>">
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>最大文字数</span>
                <span>
                  <input type="text" name="<?php echo esc_attr("{$meta_key}[{$key}][max]") ?>" value="<?php echo esc_attr($max) ?>">
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>一致する文字（カンマ区切りで複数指定）</span>
                <span>
                  <input class="large-text" type="text" name="<?php echo esc_attr("{$meta_key}[{$key}][matching_char]") ?>" value="<?php echo esc_attr($matching_char) ?>">
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>必須</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][required]") ?>" value="1" <?php if ($required) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>電話番号</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][tel]") ?>" value="1" <?php if ($tel) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>メールアドレス</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][email]") ?>" value="1" <?php if ($email) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>URL</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][url]") ?>" value="1" <?php if ($url) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>半角数字</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][numeric]") ?>" value="1" <?php if ($numeric) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>半角英字</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][alpha]") ?>" value="1" <?php if ($alpha) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>半角英数字</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][alphanumeric]") ?>" value="1" <?php if ($alphanumeric) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>カタカナ</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][katakana]") ?>" value="1" <?php if ($katakana) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>ひらがな</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][hiragana]") ?>" value="1" <?php if ($hiragana) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>カタカナ or ひらがな</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][kana]") ?>" value="1" <?php if ($kana) echo esc_attr(' checked') ?>>
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>ThrowsSpamAway</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][throws_spam_away]") ?>" value="1" <?php if ($throws_spam_away) echo esc_attr(' checked') ?>>
                </span>
              </div>
            </div>
          </div>
        <?php
        endforeach;
      else :
        ?>
        <div class="omf-metabox__list js-omf-repeat-field" data-omf-validation-count="0" draggable="true">
          <div class="omf-metabox__head">
            <div class="omf-metabox__remove js-omf-remove"></div>
            <div class="omf-metabox__head__title js-omf-field-title"></div>
            <div class="omf-metabox__toggle js-omf-toggle"></div>
          </div>
          <div class="omf-metabox__body js-omf-toggle-field">
            <div class="omf-metabox__row">
              <span>バリデーションする項目</span>
              <span>
                <input class="js-omf-input-field-title" type="text" name="<?php echo esc_attr("{$meta_key}[0][target]") ?>" value="">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>最小文字数</span>
              <span>
                <input type="text" name="<?php echo esc_attr("{$meta_key}[0][min]") ?>" value="">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>最大文字数</span>
              <span>
                <input type="text" name="<?php echo esc_attr("{$meta_key}[0][max]") ?>" value="">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>一致する文字（カンマ区切りで複数指定）</span>
              <span>
                <input type="text" name="<?php echo esc_attr("{$meta_key}[0][matching_char]") ?>" value="">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>必須</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][required]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>電話番号</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][tel]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>メールアドレス</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][email]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>URL</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][url]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>半角数字</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][numeric]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>半角英字</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][alpha]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>半角英数字</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][alphanumeric]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>カタカナ</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][katakana]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>ひらがな</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][hiragana]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>カタカナ or ひらがな</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][kana]") ?>" value="1">
              </span>
            </div>
            <div class="omf-metabox__row">
              <span>ThrowsSpamAway</span>
              <span>
                <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[0][throws_spam_away]") ?>" value="1">
              </span>
            </div>
          </div>
        </div>
      <?php
      endif;
      ?>
    </div>
  <?php
  }


  /**
   * テキストエリアのメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_textarea($post, $title, $meta_key, $description = null)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox">
      <div class="omf-metabox__item">
        <table>
          <tbody>
            <th>
              <label for="<?php echo esc_attr($meta_key) ?>"><?php echo esc_html($title) ?></label>
            </th>
            <td>
              <textarea class="widefat" id="<?php echo esc_attr($meta_key) ?>" name="<?php echo esc_attr($meta_key) ?>" cols="160" rows="10"><?php echo esc_attr($value) ?></textarea>
              <?php
              if (!empty($description)) {
              ?>
                <p class="description">
                  <?php echo $description ?>
                </p>
              <?php
              }
              ?>
            </td>
          </tbody>
        </table>
      </div>
    </div>
  <?php
  }

  /**
   * 真偽値のメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   */
  public function omf_meta_box_boolean($post, $title, $meta_key)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox omf-metabox--side">
      <p>
        <b><?php echo esc_html($title) ?></b>
      </p>
      <div class="omf-metabox__item">
        <label>
          <input type="radio" name="<?php echo esc_attr($meta_key) ?>" value="1" <?php if ($value == 1) echo ' checked'; ?>>
          <span>はい</span>
        </label>
        <label>
          <input type="radio" name="<?php echo esc_attr($meta_key) ?>" value="0" <?php if (empty($value)) echo ' checked'; ?>>
          <span>いいえ</span>
        </label>
      </div>
    </div>
  <?php
  }

  /**
   * テキストのメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_text($post, $title, $meta_key, $description = null)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox">
      <div class="omf-metabox__item">
        <table>
          <tbody>
            <th>
              <label for="<?php echo esc_attr($meta_key) ?>"><?php echo esc_html($title) ?></label>
            </th>
            <td>
              <input class="widefat" type="text" id="<?php echo esc_attr($meta_key) ?>" name="<?php echo esc_attr($meta_key) ?>" value="<?php echo esc_attr($value) ?>">
              <?php
              if (!empty($description)) {
              ?>
                <p class="description">
                  <?php echo $description ?>
                </p>
              <?php
              }
              ?>
            </td>
          </tbody>
        </table>
      </div>
    </div>
  <?php
  }

  /**
   * 投稿タイプ選択のメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   */
  public function omf_meta_box_post_types($post, $title, $meta_key)
  {

    $value = get_post_meta($post->ID, $meta_key, true);

    $args = [
      'public' => true,
      '_builtin' => false
    ];
    $post_types = get_post_types($args);
    $post_types = array_merge(['post', 'page'], array_values($post_types));

  ?>
    <div class="omf-metabox omf-metabox--side">
      <div class="omf-metabox__item">
        <?php foreach ((array)$post_types as $post_type) {
          $post_type_obj = get_post_type_object($post_type);
          $checked = in_array($post_type, (array)$value, true);
        ?>
          <label>
            <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[]") ?>" value="<?php echo esc_attr($post_type) ?>" <?php if ($checked) echo ' checked'; ?>>
            <span><?php echo esc_html($post_type_obj->labels->name) ?></span>
          </label>
        <?php
        } ?>
      </div>
    </div>
  <?php
  }

  /**
   * 数値のメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_number($post, $title, $meta_key, $description = null)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox">
      <div class="omf-metabox__item">
        <label class="label" for="<?php echo esc_attr($meta_key) ?>"><?php echo esc_html($title) ?></label>
        <input type="number" id="<?php echo esc_attr($meta_key) ?>" name="<?php echo esc_attr($meta_key) ?>" value="<?php echo esc_attr($value) ?>" min="0" step="1">
        <?php
        if (!empty($description)) {
        ?>
          <p class="description">
            <?php echo $description ?>
          </p>
        <?php
        }
        ?>
      </div>
    </div>
  <?php
  }

  /**
   * テキストのメタボックス（サイド）
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_side_text($post, $title, $meta_key, $description = null)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox omf-metabox--side">
      <div class="omf-metabox__item">
        <label for="<?php echo esc_attr($meta_key) ?>"><?php echo esc_html($title) ?></label>
        <input class="widefat" type="text" id="<?php echo esc_attr($meta_key) ?>" name="<?php echo esc_attr($meta_key) ?>" value="<?php echo esc_attr($value) ?>">
        <?php
        if (!empty($description)) {
        ?>
          <p class="description">
            <?php echo $description ?>
          </p>
        <?php
        }
        ?>
      </div>
    </div>
  <?php
  }

  /**
   * メールフォームを選択のメタボックス
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   */
  public function omf_meta_box_select_mail_forms($post, $title, $meta_key)
  {

    $args = [
      'numberposts'   => -1,
      'post_type'     => OMF_Config::NAME,
      'post_status'   => 'publish',
      'no_found_rows' => true,
    ];
    $mail_forms = get_posts($args);

    if (empty($mail_forms)) {
      return;
    }

    $value = get_post_meta($post->ID, $meta_key, true);

  ?>
    <div class="omf-metabox omf-metabox--side">
      <div class="omf-metabox__item">
        <?php
        $no_checked = true;
        foreach ((array)$mail_forms as $form) {
          $checked = in_array($form->post_name, (array)$value, true);
          if ($checked) {
            $no_checked = false;
          }
        ?>
          <label>
            <input type="radio" name="<?php echo esc_attr("$meta_key") ?>" value="<?php echo esc_attr($form->post_name) ?>" <?php if ($checked) echo ' checked'; ?>>
            <span>「<?php echo esc_html(get_the_title($form->ID)) ?>」と連携</span>
          </label>
        <?php
        }
        ?>
        <label>
          <input type="radio" name="<?php echo esc_attr("$meta_key") ?>" value="" <?php if ($no_checked) echo ' checked'; ?>>
          <span>連携しない</span>
        </label>
      </div>
    </div>
<?php
  }
}

new Original_Mail_Forms();
