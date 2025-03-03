<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;


class OMF_Page
{
  use OMF_Trait_Send, OMF_Trait_Validation;

  /**
   * セッション接頭辞
   * @var string
   */
  private $session_name_prefix;

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
   * 送信済みフラグセッション名
   *
   * @var string
   */
  private $session_name_sent;

  /**
   * nonceのアクション名
   * @var string
   */
  private $nonce_action;

  public function __construct()
  {
    $this->add_actions();
    $this->add_filters();
  }

  /**
   * アクションフックの追加
   *
   * @return void
   */
  private function add_actions()
  {
    add_action('parse_request', [$this, 'init_sessions']);
    add_action('wp_enqueue_scripts', [$this, 'load_scripts']);
    add_action('template_redirect', [$this, 'redirect_form_pages']);
  }

  /**
   * フィルターフックの追加
   *
   * @return void
   */
  private function add_filters()
  {
    add_filter('omf_get_errors', [$this, 'get_errors']);
    add_filter('omf_get_post_values', [$this, 'get_post_values']);
    add_filter('omf_nonce_field', [$this, 'nonce_field']);
    add_filter('omf_recaptcha_field', [$this, 'recaptcha_field']);
    add_filter('omf_create_token', [$this, 'create_token']);
  }

  /**
   * REST API判定
   * @return bool
   */
  private function is_rest(): bool
  {
    return (defined('REST_REQUEST') && REST_REQUEST);
  }

  /**
   * セッション有効化
   *
   * @return void
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
   * スクリプトの読み込み
   *
   * @return void
   */
  public function load_scripts()
  {
    $this->load_recaptcha_script();
    $this->load_disable_browser_back_script();
  }


  /**
   * テンプレートにreCAPTCHA用のスクリプト追加
   * @return void
   */
  private function load_recaptcha_script(): bool
  {

    $is_recaptcha_page = $this->is_recaptcha_page();
    if (!$is_recaptcha_page) {
      return false;
    }

    //JS出力
    $recaptcha_site_key = !empty(get_option('omf_recaptcha_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_site_key'))) : '';

    wp_enqueue_script('recaptcha-script', "https://www.google.com/recaptcha/api.js?render={$recaptcha_site_key}", [], null, [
      'strategy' => 'defer',
      'in_footer' => true
    ]);

    $custom_script = $this->get_recaptcha_script($recaptcha_site_key);
    return wp_add_inline_script('recaptcha-script', $custom_script);
  }

  /**
   * reCAPTCHAが有効なページかどうか判定
   *
   * @return boolean
   */
  private function is_recaptcha_page(): bool
  {
    //reCAPTCHA設定の有無を判定
    $is_recaptcha = $this->can_use_recaptcha();
    if (empty($is_recaptcha)) {
      return false;
    }

    //現在のページのID取得
    $current_page_id = get_the_ID();

    //連携するメールフォームを取得
    $form  = $this->get_form($current_page_id);
    if (empty($form)) {
      return false;
    }

    //入力・確認・完了画面のページIDを取得する
    $page_ids = $this->get_form_page_ids($form);
    //入力・確認画面どちらかでなければ設置しない
    $recaptcha_page_ids = [$page_ids['entry'], $page_ids['confirm']];
    if (!in_array($current_page_id, $recaptcha_page_ids, true)) {
      return false;
    }

    return true;
  }

  /**
   * recaptchaのJSを取得
   *
   * @param string $recaptcha_site_key
   * @return string
   */
  private function get_recaptcha_script(string $recaptcha_site_key): string
  {

    $script = "
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

    return $script;
  }

  /**
   * ブラウザバックを無効化するスクリプトを追加
   *
   * @return void
   */
  private function load_disable_browser_back_script()
  {
    //現在のページのID取得
    $current_page_id = get_the_ID();

    //連携するメールフォームを取得
    $form  = $this->get_form($current_page_id);
    if (empty($form)) {
      return;
    }

    //入力・確認・完了画面のページIDを取得する
    $page_ids = $this->get_form_page_ids($form);

    //完了画面でなければ設置しない
    if ($current_page_id !== (int)$page_ids['complete']) {
      return;
    }

    wp_enqueue_script('omf-disable-back-button-script', plugins_url('dist/js/disable-back-button.js', __DIR__), [], null, [
      'strategy' => 'defer',
      'in_footer' => true
    ]);
  }

  /**
   * エラーデータを取得
   * @return array
   */
  public function get_errors(): array
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
   *
   * @return array
   */
  public function get_post_values(): array
  {
    $post_data = [];

    //セッションがある場合
    if (!empty($_SESSION[$this->session_name_post_data])) {
      $post_data = $_SESSION[$this->session_name_post_data];
    }

    //POSTがある場合は上書き
    if (!empty($_POST)) {
      $posts = array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $_POST);
      foreach ((array)$posts as $key => $value) {
        $post_data[$key] = $value;
      }
      $post_data = $this->filter_post_keys($post_data);
    }

    //アップロードファイルを追加
    $post_data = $this->add_uploaded_files($post_data);

    return $post_data;
  }

  /**
   * nonceフィールドを出力する
   *
   * @return void
   */
  public function nonce_field()
  {
    //nonceを出力
    wp_nonce_field($this->nonce_action, 'omf_nonce', true);
    //ワンタイムトークンの出力
    $this->token_field();
  }

  /**
   * ワンタイムトークンの出力
   *
   * @return void
   */
  public function token_field()
  {
    $token = '';
    //初期画面
    if ($this->is_page('entry')) {
      $token = $this->create_token();
    }
    //初期画面以外
    else {
      $token = !empty($_SESSION['omf_token']) ? $_SESSION['omf_token'] : '';
    }
    echo '<input type="hidden" name="omf_token" value="' . $token . '">';
  }

  /**
   * ワンタイムトークンの生成
   *
   * @return string
   */
  public function create_token(): string
  {
    if (!empty($_SESSION['omf_token'])) {
      return $_SESSION['omf_token'];
    }

    $token_byte = random_bytes(16);
    $token = bin2hex($token_byte);
    $_SESSION['omf_token'] = $token;
    return $token;
  }

  /**
   * reCAPTCHAフィールド出力
   *
   * @return void
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
   * セッション名を更新する
   *
   * @param string $prefix_id
   * @return void
   */
  private function update_session_names(string $prefix_id)
  {
    //セッション接頭辞を一意にする
    $this->session_name_prefix = OMF_Config::PREFIX . $prefix_id;
    //送信データセッション名を更新
    $this->session_name_post_data = "{$this->session_name_prefix}_data";
    //認証セッション名を更新
    $this->session_name_auth = "{$this->session_name_prefix}_auth";
    //エラーセッション名を更新
    $this->session_name_error = "{$this->session_name_prefix}_errors";
    //戻るセッション名を更新
    $this->session_name_back = "{$this->session_name_prefix}_back";
    //送信完了セッション名を更新
    $this->session_name_sent = "{$this->session_name_prefix}_sent";
    //nonceアクション名を更新
    $this->nonce_action = "{$this->session_name_prefix}_nonce_action";
  }

  /**
   * すべてのメールフォームのセッションをクリアする
   *
   * @return void
   */
  private function clear_sessions_all()
  {
    $prefix_id = $this->get_form_slug();
    $this->clear_sessions($prefix_id);
  }

  /**
   * メールフォームのセッションをクリアする
   * @param string $prefix_id フォームID
   * @return void
   */
  private function clear_sessions(string $prefix_id)
  {
    if (empty($_SESSION) || empty($prefix_id)) {
      return;
    }

    $prefix = OMF_Config::PREFIX . $prefix_id;
    foreach ($_SESSION as $key => $value) {
      if (strpos($key, $prefix) === 0) {
        unset($_SESSION[$key]);
      }
    }
  }

  /**
   * 不要な送信データをフィルターする
   *
   * @param array $post_data
   * @return array
   */
  private function filter_post_keys(array $post_data): array
  {
    if (empty($post_data)) {
      return [];
    }

    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
    $remove_keys = ['confirm', 'send', 'omf_nonce', '_wp_http_referer', 'omf_token', $recaptcha_field_name];

    $filterd_post_data = array_diff_key($post_data, array_flip($remove_keys));

    return $filterd_post_data;
  }


  /**
   * nonce認証
   *
   * @return bool
   */
  private function is_valid_nonce(): bool
  {
    $nonce = filter_input(INPUT_POST, 'omf_nonce');
    return wp_verify_nonce($nonce, $this->nonce_action) !== false;
  }

  /**
   * リファラー認証
   *
   * @param string $slug ページスラッグ名
   * @return bool
   */
  private function is_valid_referer(string $slug): bool
  {
    //リファラー認証
    $referer           = filter_input(INPUT_POST, '_wp_http_referer');
    $referer           = !empty($referer) ? sanitize_text_field(wp_unslash($referer)) : null;
    $referer_post_id   = !empty($referer) ? url_to_postid($referer) : null;
    $referer_post      = !empty($referer_post_id) ? get_post($referer_post_id) : null;
    $referer_post_slug = !empty($referer_post) ? $referer_post->post_name : null;

    return !empty($referer_post) && $referer_post_slug === $slug;
  }

  /**
   * nonce認証とリファラー認証
   *
   * @param string $slug ページスラッグ名
   * @return boolean
   */
  private function is_authenticate(string $slug): bool
  {
    $is_valid_nonce = $this->is_valid_nonce();
    $is_valid_referer = $this->is_valid_referer($slug);
    return $is_valid_nonce && $is_valid_referer;
  }

  /**
   * トークン認証
   *
   * @return bool
   */
  private function is_valid_token(): bool
  {
    $token = OMF_Utils::custom_escape(filter_input(INPUT_POST, 'omf_token'));
    $session_token = !empty($_SESSION['omf_token']) ? $_SESSION['omf_token'] : '';
    return !empty($token) && $token === $session_token;
  }

  /**
   * フォームのリダイレクト
   *
   * @return void
   */
  public function redirect_form_pages()
  {
    //管理画面・投稿・固定ページ以外無効
    if (!is_page() && !is_single()) {
      return;
    }

    //現在のページのID取得
    $current_page_id = get_the_ID();
    $form = $this->get_form($current_page_id);
    if (empty($form)) {
      $this->clear_sessions_all();
      return;
    }

    //セッション接頭辞を一意にする
    $this->update_session_names($form->post_name);

    //リダイレクト
    $this->redirect_handler($form->ID, $current_page_id);
  }

  /**
   * リダイレクト振り分け
   *
   * @param integer|string $form_id
   * @return void
   */
  private function redirect_handler(int|string $form_id, int|string $current_page_id)
  {
    if (empty($current_page_id)) {
      return;
    }

    //表示条件からページ情報を取得
    $page_paths = $this->get_form_page_paths($form_id);
    $pages = $this->get_active_form_pages($form_id, $page_paths);

    //フォーム入力ページ
    if ($current_page_id === $pages['entry']->ID) {
      $this->entry_page_redirect($page_paths, $pages);
    }
    //確認画面
    elseif ($current_page_id === $pages['confirm']->ID) {
      $this->confirm_page_redirect($page_paths, $pages);
    }
    //完了画面
    elseif ($current_page_id === $pages['complete']->ID) {
      $this->complete_page_redirect($page_paths);
    }
    //それ以外
    else {
      return;
    }
  }

  /**
   * 入力ページ（初期ページ）のリダイレクト処理
   *
   * @param array $page_paths
   * @param array $pages
   * @return void
   */
  private function entry_page_redirect(array $page_paths, array $pages)
  {
    //戻るボタンの場合
    if ($this->is_back_button_clicked()) {
      $this->redirect_to_entry_page_by_back($page_paths);
      return;
    }

    //メール送信の場合
    if ($this->is_mail_send_request()) {
      //データ取得
      $post_data = $this->get_post_values();
      $this->mail_send_handler($page_paths, $pages['entry']->post_name, $post_data);
      return;
    }

    //確認ボタンではない場合
    if (!$this->is_confirm_button_clicked()) {
      $this->initialize_entry_page_sessions();
      return;
    }

    //nonce認証・リファラー認証
    $is_authenticate = $this->is_authenticate($pages['entry']->post_name);
    //認証NG
    if (!$is_authenticate) {
      $_SESSION[$this->session_name_auth] = false;
      return;
    }

    //各入力項目を取得
    $post_data = $this->get_post_values();

    //入力項目を検証
    $errors = $this->validate_mail_form_data($post_data);

    //検証NGの場合は入力画面に戻す
    if (!empty($errors)) {
      $this->redirect_to_entry_page_by_invalid($post_data, $page_paths, $errors);
      return;
    }

    // 検証OKの場合はセッションを保持して確認画面へ
    $this->redirect_to_confirm_page_by_valid($post_data, $page_paths);
  }

  /**
   * 戻るボタンのクリック有無
   *
   * @return boolean
   */
  private function is_back_button_clicked(): bool
  {
    return  filter_input(INPUT_POST, 'submit_back') === "back";
  }

  /**
   * 確認ボタンクリックの有無
   *
   * @return boolean
   */
  private function is_confirm_button_clicked(): bool
  {
    return filter_input(INPUT_POST, 'confirm') === 'confirm';
  }

  /**
   * 戻るボタンで入力画面に戻ってきたときのリダイレクト
   *
   * @param array $page_paths
   * @return void
   */
  private function redirect_to_entry_page_by_back(array $page_paths)
  {
    //戻るフラグがあるの場合
    if ($this->has_back_session()) {
      //認証フラグをオフ
      $_SESSION[$this->session_name_auth] = false;
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
      exit;
    }
  }

  /**
   * 戻るセッションがある場合
   *
   * @return boolean
   */
  private function has_back_session(): bool
  {
    return !empty($_SESSION[$this->session_name_back]) && $_SESSION[$this->session_name_back] === true;
  }

  /**
   * 入力ページに入る処理（初期化）
   *
   * @return void
   */
  private function initialize_entry_page_sessions()
  {
    //戻るフラグがある場合
    if ($this->has_back_session()) {
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
  }

  /**
   * 検証NGの場合に入力ページにエラー情報を持ってリダイレクト
   *
   * @param array $post_data
   * @param array $page_paths
   * @param array $errors
   * @return void
   */
  private function redirect_to_entry_page_by_invalid(array $post_data, array $page_paths, array $errors)
  {
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    $_SESSION[$this->session_name_error] = $errors;
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
    exit;
  }

  /**
   * 検証OKの場合に確認画面にリダイレクト
   *
   * @param array $post_data
   * @param array $page_paths
   * @return void
   */
  private function redirect_to_confirm_page_by_valid(array $post_data, array $page_paths)
  {
    $_SESSION[$this->session_name_auth] = true;
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['confirm'])), 307);
    exit;
  }

  /**
   * 確認画面のリダイレクト処理
   * @param  array $page_paths
   * @param  array $pages
   * @return void
   */
  private function confirm_page_redirect(array $page_paths, array $pages)
  {
    //戻るボタンの処理
    if ($this->is_back_button_clicked()) {
      $this->back_to_entry_page($page_paths);
      return;
    }

    //戻るフラグが残ってる場合は削除
    if (!empty($_SESSION[$this->session_name_post_data]['submit_back'])) {
      unset($_SESSION[$this->session_name_post_data]['submit_back']);
    }

    // POSTがない場合の処理
    if (empty($_POST)) {
      $this->handle_no_post_confirm_page($page_paths);
      return;
    }

    // POSTとセッションがある場合
    if ($this->has_valid_session()) {
      $this->handle_valid_session_confirm_page($page_paths, $pages);
      return;
    }

    // POSTあり、セッションがない場合
    $this->handle_no_session_confirm_page($page_paths, $pages);
  }


  /**
   * 確認ページから入力ページにリダイレクト（戻る）
   *
   * @param array $page_paths
   * @return void
   */
  private function back_to_entry_page(array $page_paths)
  {
    //戻るフラグをオン
    $_SESSION[$this->session_name_back] = true;
    //認証フラグをオフ
    $_SESSION[$this->session_name_auth] = false;
    $_SESSION[$this->session_name_post_data] = $this->get_post_values();
    session_write_close();
    //入力画面に戻す
    wp_safe_redirect(esc_url(home_url($page_paths['entry'])), 307);
    exit;
  }

  /**
   * 確認ページでPOSTがない場合の処理
   *
   * @param array $page_paths
   * @return null
   */
  private function handle_no_post_confirm_page(array $page_paths)
  {
    //セッションがある場合
    if ($this->has_valid_session()) {
      return;
    }

    //POSTもセッションもない場合
    $_SESSION[$this->session_name_auth] = false;
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
    exit;
  }

  /**
   * 認証フラグの有無
   *
   * @return boolean
   */
  private function has_valid_session(): bool
  {
    return !empty($_SESSION[$this->session_name_auth]) && $_SESSION[$this->session_name_auth] === true;
  }

  /**
   * 確認ページでセッションがある場合の処理
   *
   * @param array $page_paths
   * @param array $pages
   * @return void
   */
  private function handle_valid_session_confirm_page(array $page_paths, array $pages)
  {
    //メール送信以外の場合
    if (!$this->is_mail_send_request()) {
      $this->enter_confirm_page($page_paths);
      return;
    }

    //メール送信
    $post_data = $this->get_post_values();
    $_SESSION[$this->session_name_auth] = true;
    $_SESSION[$this->session_name_post_data] = $post_data;
    // メール送信処理
    $this->mail_send_handler($page_paths, $pages['confirm']->post_name, $post_data);
  }

  /**
   * 確認ページでセッションがない場合の処理
   *
   * @param array $page_paths
   * @param array $pages
   * @return void
   */
  private function handle_no_session_confirm_page(array $page_paths, array $pages)
  {
    //nonce認証・リファラー認証
    $is_authenticate = $this->is_authenticate($pages['confirm']->post_name) || $this->is_authenticate($pages['entry']->post_name);
    //token検証
    $is_valid_token = $this->is_valid_token();
    //認証NG
    if (!$is_authenticate || !$is_valid_token) {
      $this->back_to_entry_page_by_invalid($page_paths);
      return;
    }

    //メール送信の場合
    if ($this->is_mail_send_request()) {
      //データ取得
      $post_data = $this->get_post_values();
      $this->mail_send_handler($page_paths, $pages['confirm']->post_name, $post_data);
      return;
    }
  }

  /**
   * 送信リクエストの有無（送信ボタン）
   */
  private function is_mail_send_request(): bool
  {
    return filter_input(INPUT_POST, 'send') === 'send';
  }

  /**
   * 検証NGの場合に入力画面に戻す
   *
   * @param array $page_paths
   * @return void
   */
  private function back_to_entry_page_by_invalid(array $page_paths)
  {
    $post_data = $this->get_post_values();
    $_SESSION[$this->session_name_back] = true;
    $_SESSION[$this->session_name_auth] = false;
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
    exit;
  }

  /**
   * 確認ページに入る
   *
   * @return void
   */
  private function enter_confirm_page(array $page_paths)
  {
    //データを取得して保存
    $post_data = $this->get_post_values();
    $_SESSION[$this->session_name_auth] = true;
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    session_write_close();
    //リダイレクトする
    wp_safe_redirect(esc_url(home_url($page_paths['confirm'])));
    exit;
  }

  /**
   * 完了画面のリダイレクト処理
   * @param  array $page_paths
   * @return void
   */
  private function complete_page_redirect(array $page_paths)
  {
    //POSTがある場合はリダイレクト
    if (!empty($_POST)) {
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_paths['complete'])));
      exit;
    }

    //セッションがある場合
    if ($this->has_valid_session()) {
      //セッションを破棄
      $this->clear_sessions_all();
    }
    //セッションがない場合
    else {
      session_write_close();
      wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
      exit;
    }
  }


  /**
   * メール送信のエラーハンドラー
   *
   * @param array $page_paths
   * @param string $referer_slug リファラーページのスラッグ名
   * @param array $post_data
   * @return void
   */
  private function mail_send_handler(array $page_paths, string $referer_slug, array $post_data = [])
  {
    // メール送信処理
    $send = $this->send($page_paths, $referer_slug, $post_data);
    //メール送信完了の場合
    if ($send === true) {
      $this->mail_send_success($page_paths);
    }
    //メール送信失敗の場合
    else {
      $this->mail_send_fail($page_paths, $post_data);
    }
  }

  /**
   * メール送信完了時
   *
   * @param array $page_paths
   * @return void
   */
  private function mail_send_success(array $page_paths)
  {
    //完了画面にリダイレクト
    $_SESSION[$this->session_name_auth] = true;
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['complete'])), 307);
    exit;
  }

  /**
   * メール送信失敗時
   *
   * @param array $page_paths
   * @param array $post_data
   * @return void
   */
  private function mail_send_fail(array $page_paths, array $post_data)
  {
    $_SESSION[$this->session_name_auth] = false;
    //エラーメッセージを追加して入力画面にリダイレクト
    $_SESSION[$this->session_name_error] = [
      'send' => ['送信中にエラーが発生しました。お手数ですが、再度送信をお試しください']
    ];
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($post_data);
    session_write_close();
    wp_safe_redirect(esc_url(home_url($page_paths['entry'])));
    exit;
  }

  /**
   * 送信処理
   * @param array $page_paths
   * @param string $referer_slug
   * @param array $post_data
   * @return bool
   */
  private function send(array $page_paths, string $referer_slug, array $post_data = []): bool
  {

    //nonce認証・リファラー認証
    $is_authenticate = $this->is_authenticate($referer_slug);
    //認証NGの場合
    if (!$is_authenticate) {
      return false;
    }

    //token検証
    $is_valid_token = $this->is_valid_token();
    //token検証NG
    if (!$is_valid_token) {
      //送信済みフラグの有無で判定
      return $this->has_sent_session();
    }

    //要素がない場合はセッションから各要素を取得
    if (empty($post_data)) {
      $post_data = $this->get_post_data_session();
    }

    //検証
    $errors = $this->validate_mail_form_data($post_data);
    //エラーがある場合は入力画面に戻す
    if (!empty($errors)) {
      $this->redirect_to_entry_page_by_invalid($post_data, $page_paths, $errors);
    }

    //メールフォームを取得し、メールIDを設定
    $form = $this->get_form();
    $post_data['mail_id'] = $this->get_mail_id($form->ID);

    //送信前のフック
    $post_id = get_the_ID();
    do_action('omf_before_send_mail', $post_data, $form, $post_id);

    // メール送信
    $result = $this->send_mails($post_data, $form->ID, $post_id);

    $this->after_send_mails($form, $post_data, $post_id);

    return $result;
  }

  /**
   * メール送信
   *
   * @param array $post_data
   * @param integer $form_id
   * @param integer $post_id
   * @return boolean
   */
  private function send_mails(array $post_data, int $form_id, int $post_id): bool
  {
    //添付ファイルの変換処理
    $converted      = $this->convert_attachments($post_data);
    $attachments    = $converted['attachment_paths'];
    $attachment_ids = $converted['attachment_ids'];
    $post_data      = $converted['tags'];

    //自動返信の有無
    $is_disable_reply_mail = $this->is_disable_reply_mail($form_id);
    //自動返信なしの場合
    if ($is_disable_reply_mail) {
      //通知メール送信処理
      $is_sended_admin = $this->send_admin_mail($post_data, $post_id, $attachments);
      return $is_sended_admin;
    }

    //自動返信ありの場合
    //自動返信メール送信処理
    $is_sended_reply = $this->send_reply_mail($post_data, $post_id, $attachments);
    //通知メール送信処理
    $post_data['omf_reply_mail_sended'] = $is_sended_reply ? '【自動返信】送信成功' : '【自動返信】送信失敗';
    $is_sended_admin = $this->send_admin_mail($post_data, $post_id, $attachments);

    //添付ファイルの一時タグを削除
    $this->remove_temporary_media_tag($attachment_ids);

    return $is_sended_reply && $is_sended_admin;
  }

  /**
   * 送信済みフラグの有無
   *
   * @return boolean
   */
  private function has_sent_session(): bool
  {
    return !empty($_SESSION[$this->session_name_sent]) && $_SESSION[$this->session_name_sent] === true;
  }

  /**
   * セッションに保存しているPOSTデータを取得する　
   *
   * @return array
   */
  private function get_post_data_session(): array
  {
    return !empty($_SESSION[$this->session_name_post_data]) ? array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $_SESSION[$this->session_name_post_data]) : [];
  }

  /**
   * メール送信成功後の処理
   *
   * @param WP_Post $form
   * @param array $post_data
   * @param integer $post_id
   * @return void
   */
  private function after_send_mails(WP_Post $form, array $post_data, int $post_id): void
  {
    //重複送信防止処理
    $this->prevent_duplicate_sending();
    //送信後にメールIDを更新
    $this->update_mail_id($form->ID, $post_data['mail_id']);
    //送信後のフック
    do_action('omf_after_send_mail', $post_data, $form, $post_id);
  }

  /**
   * 二重送信防止
   *
   * @return void
   */
  private function prevent_duplicate_sending()
  {
    //ワンタイムトークンを破棄
    if (!empty($_SESSION['omf_token'])) {
      unset($_SESSION['omf_token']);
    }

    //送信済みフラグをON
    $_SESSION[$this->session_name_sent] = true;
  }
}
