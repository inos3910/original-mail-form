<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

class OMF_Admin
{
  use OMF_Trait_Form, OMF_Trait_Cryptor, OMF_Trait_Google_Auth, OMF_Trait_Update;

  public function __construct()
  {
    //管理画面
    add_action('init', [$this, 'init']);
    add_action('admin_init', [$this, 'redirects']);
    add_filter('manage_edit-' . OMF_Config::NAME . '_columns', [$this, 'custom_posts_columns']);
    add_action('manage_' . OMF_Config::NAME . '_posts_custom_column', [$this, 'add_column'], 10, 2);
    add_action('admin_menu', [$this, 'add_admin_submenus']);
    add_action('add_meta_boxes_' . OMF_Config::NAME, [$this, 'add_meta_box_omf']);
    add_action('add_meta_boxes', [$this, 'add_meta_box_posts']);
    add_action('save_post', [$this, 'save_omf_custom_field']);
    add_action('admin_enqueue_scripts', [$this, 'add_omf_srcs']);
    add_action('post_row_actions', [$this, 'admin_omf_data_list_row'], 10, 2);
  }

  //初期化
  public function init()
  {
    $this->create_post_type();
    $this->create_save_data_post_types();
  }

  //リダイレクト
  public function redirects()
  {
    $this->oauth_redirect();
    $this->disconnect_oauth_redirect();
  }

  /**
   * CSS・JSの追加
   *
   * @return void
   */
  public function add_omf_srcs()
  {
    $this->add_omf_styles();
    $this->add_omf_scripts();
  }

  /**
   * CSSの追加
   *
   * @return void
   */
  public function add_omf_styles()
  {
    wp_enqueue_style('omf-admin-style', plugins_url('dist/css/style.css', __DIR__));

    //送信データの場合は新規投稿ボタンを非表示にする
    global $post_type;
    if (!empty($post_type) && $this->is_omf_data_post_type($post_type)) {
      echo '<style>.wrap .wp-heading-inline + .page-title-action{display: none;}</style>';
    }
  }

  /**
   * JSを追加
   *
   * @return void
   */
  public function add_omf_scripts()
  {
    global $post_type;
    if (!empty($post_type)) {
      if ($post_type === OMF_Config::NAME) {
        wp_enqueue_script('omf-script', plugins_url('dist/js/main.js', __DIR__), [], '1.0', true);
      }

      if ($this->is_omf_data_post_type($post_type)) {
        wp_enqueue_script('omf-data-list-script', plugins_url('dist/js/data-list.js', __DIR__), [], '1.0', true);
      }
    }
  }

  /**
   *メールフォーム投稿タイプを作成
   *
   * @return void
   */
  private function create_post_type()
  {
    $labels = [
      'name'                => 'メールフォーム',
      'singular_name'       => 'メールフォーム',
      'add_new'             => '新規追加',
      'add_new_item'        => '新規追加',
      'edit_item'           => 'フォームを編集',
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
    register_post_type(OMF_Config::NAME, $args);
  }

  /**
   * 送信データ投稿タイプを作成
   *
   * @return void
   */
  private function create_save_data_post_types()
  {
    //フォームを取得
    $mail_forms = $this->get_forms();
    if (empty($mail_forms)) {
      return;
    }

    //フォームの中でDB保存フラグがあるものだけ送信データの投稿タイプを作成
    foreach ((array)$mail_forms as $form) {
      $is_use_db = get_post_meta($form->ID, 'cf_omf_save_db', true) === '1';
      if (!$is_use_db) {
        continue;
      }

      $data_post_type = $this->get_data_post_type_by_id($form->ID);
      if (empty($data_post_type)) {
        continue;
      }

      //送信データの投稿タイプを登録
      $data_labels = [
        'name'                => "{$form->post_title} 送信データ",
        'singular_name'       => "{$form->post_title} 送信データ",
        'edit_item'           => "{$form->post_title} 送信データを編集",
        'new_item'            => '新規追加',
        'view_item'           => '送信データを表示',
        'search_items'        => '送信データを検索',
        'not_found'           => '送信データが見つかりません',
        'not_found_in_trash'  => 'ゴミ箱に送信データはありません',
      ];
      $data_args = [
        'label'               => "{$form->post_title} 送信データ",
        'labels'              => $data_labels,
        'capability_type'     => 'page',
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'has_archive'         => false,
        'supports'            => ['title']
      ];
      register_post_type($data_post_type, $data_args);

      //送信データ一覧のカラムを編集
      add_filter("manage_edit-{$data_post_type}_columns", [$this, 'custom_data_columns']);
      //送信データ一覧のカラムを追加
      add_action("manage_{$data_post_type}_posts_custom_column", [$this, 'add_data_column'], 10, 2);

      //詳細ページにメタボックス追加
      add_action("add_meta_boxes_{$data_post_type}", [$this, 'add_meta_box_data_detail']);
    }
  }

  /**
   * サブメニューの追加
   *
   * @return void
   */
  public function add_admin_submenus()
  {
    $this->add_admin_setting();
    $this->add_admin_recaptcha_settings();
    $this->add_admin_data_settings();
    $this->add_admin_google_settings();
    $this->add_admin_update_settings();
  }

  /**
   * 設定オプションページを追加
   *
   * @return void
   */
  public function add_admin_setting()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      '設定',
      '設定',
      'manage_options',
      'omf_settings',
      [$this, 'load_admin_template']
    );
    add_action('admin_init', [$this, 'register_omf_settings']);
  }

  /**
   * 設定オプションページ 項目の登録
   *
   * @return void
   */
  public function register_omf_settings()
  {
    register_setting('omf-settings-group', 'omf_is_rest_api');
  }

  /**
   * 送信データ詳細ページにメタボックスを追加
   *
   * @param WP_Post $post
   * @return void
   */
  public function add_meta_box_data_detail(WP_Post $post)
  {
    //送信データ詳細
    add_meta_box('omf-metabox-data-detail', '送信データ詳細', [$this, 'data_detail_meta_box_callback'], $post->post_type, 'normal', 'default');
    //メモ
    add_meta_box('omf-metabox-data-memo', 'メモ', [$this, 'data_memo_meta_box_callback'], $post->post_type, 'normal', 'default');
  }

  /**
   * 送信データ詳細のメタボックス
   *
   * @param WP_Post $post
   * @return void
   */
  public function data_detail_meta_box_callback(WP_Post $post)
  {
    $all_fields = get_post_custom($post->ID);
    $form_slug = $this->get_form_slug_by_data_post_id($post->ID);
    $label = get_post_type_object($post->post_type)->label;
    $fields = $this->filter_hidden_custom_field($all_fields);

    if (!empty($fields)) { ?>
      <div class="omf-data__frame">
        <table class="omf-data__table">
          <?php
          foreach ((array)$fields as $key => $value) :
            //メモはスキップ
            if ($key === 'cf_omf_data_memo') {
              continue;
            }
          ?>
            <tr>
              <th>
                <?php
                $field_key = apply_filters('omf_data_custom_field_key_' . $form_slug, $key);
                $field_key = $this->replace_custom_field_default_key($field_key);
                echo esc_html($field_key);
                ?>
              </th>
              <td>
                <?php
                if (!empty($value)) {
                  foreach ((array)$value as $val) {
                    $sanitized = sanitize_textarea_field(wp_unslash($val));
                    echo '<div class="pre">' . esc_html($sanitized) . '</div>';
                  }
                }
                ?>
              </td>
            </tr>
          <?php
          endforeach;
          ?>
        </table>
        <p>
          <a href="<?php echo esc_url(admin_url("edit.php?post_type={$post->post_type}")) ?>">→ <?php echo esc_html($label) ?>一覧へ戻る</a>
        </p>

      </div>
    <?php
    }
  }

  /**
   *メモのメタボックス
   *
   * @param WP_Post $post
   * @return void
   */
  public function data_memo_meta_box_callback(WP_Post $post)
  {
    $this->omf_meta_box_textarea($post, 'メモ', 'cf_omf_data_memo');
  }

  /**
   * 送信データの投稿IDから連携しているフォームのスラッグ名を取得
   *
   * @param integer|string $data_id
   * @return string
   */
  private function get_form_slug_by_data_post_id(int|string $data_id): string
  {
    //CFがない場合
    if (empty($data_id)) {
      return '';
    }

    //投稿タイプ名から取得
    $data_post_type = get_post_type($data_id);
    preg_match('/' . OMF_Config::DBDATA . '(\d+)/', $data_post_type, $matches);
    $form_id = $matches[1] ?? null;
    $form_slug = !empty($form_id) ? get_post_field('post_name', $form_id) : '';
    return $form_slug;
  }

  /**
   * プラグイン側で生成されるカスタムフィールドのキーの名前を日本語に置き換える
   *
   * @param string $field_key
   * @return string
   */
  private function replace_custom_field_default_key(string  $field_key): string
  {
    if ($field_key === 'omf_mail_title') {
      return '件名';
    }

    if ($field_key === 'omf_mail_to') {
      return '管理者メール送信先';
    }

    if ($field_key === 'omf_admin_mail_sended') {
      return '通知メール';
    }

    if ($field_key === 'omf_reply_mail_sended') {
      return '自動返信メール';
    }

    if ($field_key === 'site_url') {
      return 'WEBサイトURL';
    }

    if ($field_key === 'site_name') {
      return 'WEBサイト名';
    }

    if ($field_key === 'send_datetime') {
      return '送信日時';
    }

    if ($field_key === 'mail_id') {
      return 'メールID';
    }

    if ($field_key === 'user_agent') {
      return 'OS・ブラウザ情報';
    }

    if ($field_key === 'user_ip') {
      return 'IPアドレス';
    }

    if ($field_key === 'host') {
      return 'ホスト情報';
    }

    return $field_key;
  }

  /**
   * 隠しデータはフィルタリングする
   *
   * @param array $fields
   * @return array
   */
  private function filter_hidden_custom_field(array $fields): array
  {
    if (empty($fields)) {
      return [];
    }

    $new_fields = [];
    foreach ((array)$fields as $key => $value) {
      if (!(preg_match("/^_/", $key))) {
        $new_fields[$key] = $value;
      }
    }

    return $new_fields;
  }

  /**
   * 投稿タイプ一覧画面のカスタマイズ
   *
   * @param array $columns
   * @return array
   */
  public function custom_posts_columns(array $columns): array
  {
    unset($columns['author']);
    //「日時」列を最後に持ってくる為、一旦クリア
    if (isset($columns['date'])) {
      $date = $columns['date'];
      unset($columns['date']);
    }

    $columns['omf_slug']          = "スラッグ";
    $columns['omf_entry']         = "フォーム入力画面";
    $columns['omf_recaptcha']     = "reCAPTCHA設定";
    $columns['omf_save_db']       = "データベース保存";
    $columns['omf_slack']         = "Slack通知";
    $columns['omf_google_sheets'] = "Googleスプレッドシート書き込み";

    // 「日時」列を再セット
    if (isset($date)) {
      $columns['date']   = $date;
    }

    return $columns;
  }

  /**
   * 投稿タイプ一覧画面に値を出力
   *
   * @param string $column_name
   * @param integer $post_id
   * @return void
   */
  public function add_column(string $column_name, int $post_id)
  {
    //スラッグ
    if ($column_name === 'omf_slug') {
      $post = get_post($post_id);
      if (!empty($post)) {
        echo esc_html($post->post_name);
      }
    }
    //入力画面
    elseif ($column_name === 'omf_entry') {
      $entry_page = get_post_meta($post_id, 'cf_omf_screen_entry', true);
      echo esc_html($entry_page);
    }
    // reCAPTCHA設定
    elseif ($column_name === 'omf_recaptcha') {
      $is_recaptcha = get_post_meta($post_id, 'cf_omf_recaptcha', true);
      if (!empty($is_recaptcha) && $is_recaptcha == 1) {
        echo esc_html('有効');
      } else {
        echo esc_html('無効');
      }
    }
    // データベース保存
    elseif ($column_name === 'omf_save_db') {
      $is_use_db = get_post_meta($post_id, 'cf_omf_save_db', true);
      if ($is_use_db === '1') {
        echo esc_html('有効');
      } else {
        echo esc_html('無効');
      }
    }
    // Slack通知
    elseif ($column_name === 'omf_slack') {
      $is_use_db = get_post_meta($post_id, 'cf_omf_is_slack_notify', true);
      if ($is_use_db === '1') {
        echo esc_html('有効');
      } else {
        echo esc_html('無効');
      }
    }
    // Googleスプレッドシート書き込み
    elseif ($column_name === 'omf_google_sheets') {
      $is_use_db = get_post_meta($post_id, 'cf_omf_is_google_sheets', true);
      if ($is_use_db === '1') {
        echo esc_html('有効');
      } else {
        echo esc_html('無効');
      }
    }
    //それ以外
    else {
      // 何もしない
    }
  }

  /**
   * 送信データ投稿タイプ一覧画面のカスタマイズ
   *
   * @param array $columns
   * @return array
   */
  public function custom_data_columns(array $columns): array
  {
    //「日時」列クリア
    unset($columns['date']);

    global $wp_query;
    if (!empty($wp_query->posts)) {
      $_columns = [];
      foreach ($wp_query->posts as $post) {
        $custom_field_keys = get_post_custom_keys($post->ID);
        if (empty($custom_field_keys)) {
          continue;
        }

        foreach ($custom_field_keys as $key) {
          //隠しデータとメモ機能はスキップ
          if (preg_match("/^_/", $key) || $key === 'cf_omf_data_memo') {
            continue;
          }

          $form_slug = $this->get_form_slug_by_data_post_id($post->ID);
          $field_key = apply_filters('omf_data_custom_field_key_' . $form_slug, $key);
          $field_key = $this->replace_custom_field_default_key($field_key);

          $_columns[$key] = $field_key;
        }
      }

      $columns = array_merge($columns, $_columns);
    }

    return $columns;
  }

  /**
   * 送信データ投稿タイプ一覧画面に値を出力
   *
   * @param string $column_name
   * @param integer $post_id
   * @return void
   */
  public function add_data_column(string $column_name, int $post_id)
  {
    $value = get_post_meta($post_id, $column_name, false);
    if (!empty($value)) {
      foreach ((array)$value as $val) {
        $sanitized = sanitize_textarea_field(wp_unslash($val));
        echo '<div class="content">' . esc_html($sanitized) . '</div>';
      }
    }
  }

  /**
   * 引数の投稿タイプが送信データの投稿タイプかどうかの判定
   *
   * @param string $post_type
   * @return boolean
   */
  public function is_omf_data_post_type(string $post_type): bool
  {
    return !empty($post_type) && strncmp($post_type, OMF_Config::DBDATA, strlen(OMF_Config::DBDATA)) === 0;
  }

  /**
   * 送信データオプションページを追加
   *
   * @return void
   */
  public function add_admin_data_settings()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      '送信データ',
      '送信データ',
      'manage_options',
      'omf_data',
      [$this, 'load_admin_template']
    );
  }

  /**
   * 管理画面テンプレートの読み込み
   *
   * @return void
   */
  public function load_admin_template()
  {
    $slug = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $plugin_root_path = plugin_dir_path(__DIR__);
    $template_path = "{$plugin_root_path}templates/{$slug}.php";
    if (file_exists($template_path)) {
      require_once $template_path;
    }
  }

  /**
   * 送信データ一覧の不要な項目を削除
   *
   * @param array $actions
   * @return array
   */
  public function admin_omf_data_list_row(array $actions): array
  {

    global $post_type;
    if (empty($post_type)) {
      return $actions;
    }

    if ($this->is_omf_data_post_type($post_type)) {
      unset($actions['edit']); //編集
      unset($actions['inline hide-if-no-js']); //クイック編集
      unset($actions['trash']); //ゴミ箱
      unset($actions['view']); //プレビュー
    }
    return $actions;
  }



  /**
   * 更新オプションページを追加
   *
   * @return void
   */
  public function add_admin_update_settings()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      'プラグインの更新',
      'プラグインの更新',
      'manage_options',
      'omf_update',
      [$this, 'load_admin_template']
    );
  }

  /**
   * Google連携設定オプションページを追加
   *
   * @return void
   */
  private function add_admin_google_settings()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      'Google連携',
      'Google連携',
      'manage_options',
      'omf_google_settings',
      [$this, 'load_admin_template']
    );
    add_action('admin_init', [$this, 'register_omf_google_settings']);
  }

  /**
   * Google連携設定オプションページ 項目の登録
   *
   * @return void
   */
  public function register_omf_google_settings()
  {
    register_setting('omf-google-settings-group', 'omf_google_client_id');
    register_setting('omf-google-settings-group', 'omf_google_client_secret');
    register_setting('omf-google-settings-group', 'omf_google_redirect_uri');
  }

  /**
   * OAuth認証リダイレクト時にトークン生成・保存してリダイレクト
   *
   * @return void
   */
  private function oauth_redirect()
  {
    $is_oauth_page = isset($_GET['page']) && $_GET['page'] === 'omf_google_settings' && isset($_GET['code']);
    if (!$is_oauth_page) {
      return;
    }

    $client_id     = get_option('omf_google_client_id');
    $client_secret = get_option('omf_google_client_secret');
    $redirect_uri  = admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings');
    $access_token  = $this->decrypt_secret(get_option('_omf_google_access_token'), 'access_token');
    $refresh_token = $this->decrypt_secret(get_option('_omf_google_refresh_token'), 'refresh_token');

    $this->set_tokens($client_id, $client_secret, $redirect_uri, $access_token, $refresh_token);

    wp_redirect(admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings'));
    exit;
  }

  /**
   * OAuthを解除した後にリダイレクト
   *
   * @return void
   */
  private function disconnect_oauth_redirect()
  {
    $is_remove_oauth_page = isset($_GET['page']) && $_GET['page'] === 'omf_google_settings' && isset($_GET['remove_oauth']) && $_GET['remove_oauth'] === '1';
    if (!$is_remove_oauth_page) {
      return;
    }
    //OAuth接続を解除
    $this->remove_google_tokens();
    //OAuth接続を解除したらリダイレクト
    wp_redirect(admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings'));
    exit;
  }


  /**
   * reCAPTCHA設定オプションページを追加
   *
   * @return void
   */
  public function add_admin_recaptcha_settings()
  {
    add_submenu_page(
      'edit.php?post_type=' . OMF_Config::NAME,
      'reCAPTCHA設定',
      'reCAPTCHA設定',
      'manage_options',
      'omf_recaptcha_settings',
      [$this, 'load_admin_template']
    );
    add_action('admin_init', [$this, 'register_recaptcha_settings']);
  }

  /**
   * reCAPTCHA設定オプションページ 項目の登録
   *
   * @return void
   */
  public function register_recaptcha_settings()
  {
    register_setting('recaptcha-settings-group', 'omf_recaptcha_site_key');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_secret_key');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_score');
    register_setting('recaptcha-settings-group', 'omf_recaptcha_field_name');
  }

  /**
   * カスタムフィールドの保存
   *
   * @param integer $post_id
   * @return void
   */
  public function save_omf_custom_field(int $post_id)
  {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $this->save_single_custom_fields($post_id);
    $this->save_array_custom_fields($post_id);
    $this->save_validation_custom_fields($post_id);
  }

  /**
   * 単一データのカスタムフィールド保存
   *
   * @param integer $post_id
   * @return void
   */
  private function save_single_custom_fields(int $post_id)
  {
    //単一データのフィールド
    $update_meta_keys = [
      'cf_omf_reply_title',
      'cf_omf_reply_mail',
      'cf_omf_reply_to',
      'cf_omf_reply_from',
      'cf_omf_reply_from_name',
      'cf_omf_reply_address',
      'cf_omf_admin_title',
      'cf_omf_admin_mail',
      'cf_omf_admin_to',
      'cf_omf_admin_from',
      'cf_omf_admin_from_name',
      'cf_omf_condition_id',
      'cf_omf_select',
      'cf_omf_screen_entry',
      'cf_omf_screen_confirm',
      'cf_omf_screen_complete',
      'cf_omf_recaptcha',
      'cf_omf_save_db',
      'cf_omf_mail_id',
      'cf_omf_is_slack_notify',
      'cf_omf_slack_webhook_url',
      'cf_omf_slack_channel',
      'cf_omf_data_memo',
      'cf_omf_is_google_sheets',
      'cf_omf_google_sheets_id',
      'cf_omf_google_sheets_name',
    ];

    foreach ((array)$update_meta_keys as $key) {
      if (isset($_POST[$key])) {
        update_post_meta($post_id, $key, sanitize_textarea_field($_POST[$key]));
      }
    }
  }

  /**
   * 配列のカスタムフィールド保存
   *
   * @param integer $post_id
   * @return void
   */
  private function save_array_custom_fields(int $post_id)
  {
    //配列のフィールド
    $update_array_meta_keys = [
      'cf_omf_condition_post'
    ];
    foreach ((array)$update_array_meta_keys as $key) {
      if (isset($_POST[$key])) {
        $raw_array = $_POST[$key];
        $sanitized_array = array_map('sanitize_text_field', $raw_array);
        update_post_meta($post_id, $key, $sanitized_array);
      }
    }
  }

  /**
   * バリデーションのカスタムフィールド保存
   *
   * @param integer $post_id
   * @return void
   */
  private function save_validation_custom_fields(int $post_id)
  {
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
   *
   * @return void
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
    //データベース保存のオン・オフ
    add_meta_box('omf-metabox-save_db', 'データベース保存設定', [$this, 'save_db_meta_box_callback'], OMF_Config::NAME, 'side', 'default');
    //バリデーション
    add_meta_box('omf-metabox-validation', 'バリデーション設定', [$this, 'validation_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //メールID
    add_meta_box('omf-metabox-mail_id', 'メールID', [$this, 'mail_id_meta_box_callback'], OMF_Config::NAME, 'side', 'default');
    //slack通知
    add_meta_box('omf-metabox-slack_notify', 'Slack通知', [$this, 'slack_notify_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
    //スプレッドシート連携
    add_meta_box('omf-metabox-google_sheets', 'Googleスプレッドシート連携', [$this, 'google_sheets_meta_box_callback'], OMF_Config::NAME, 'normal', 'default');
  }

  /**
   * すべてのフォームを取得
   *
   * @return array
   */
  private function get_forms(): array
  {
    //すべてのフォームを取得
    $args = [
      'numberposts'   => -1,
      'post_type'     => OMF_Config::NAME,
      'post_status'   => 'publish',
      'no_found_rows' => true,
    ];
    $mail_forms = get_posts($args);
    return $mail_forms;
  }

  /**
   * 表示条件に合致した投稿・固定ページにメタボックスを追加する
   *
   * @return void
   */
  public function add_meta_box_posts()
  {

    //すべてのフォームを取得
    $mail_forms = $this->get_forms();
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
   *
   * @param WP_Post $post
   * @return void
   */
  public function validation_meta_box_callback(WP_Post $post)
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
   *
   * @param WP_Post $post
   * @return void
   */
  public function recaptcha_meta_box_callback(WP_Post $post)
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
   * データベース保存設定
   *
   * @param WP_Post $post
   * @return void
   */
  public function save_db_meta_box_callback(WP_Post $post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_boolean($post, '送信内容をデータベースに保存する', 'cf_omf_save_db');
      ?>
    </div>
  <?php
  }

  /**
   * 画面設定
   *
   * @param WP_Post $post
   * @return void
   */
  public function screen_meta_box_callback(WP_Post $post)
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
   *
   * @param WP_Post $post
   * @return void
   */
  public function reply_mail_meta_box_callback(WP_Post $post)
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
      $this->omf_meta_box_text($post, '送信元メールアドレス', 'cf_omf_reply_from');
      $this->omf_meta_box_text($post, '送信者', 'cf_omf_reply_from_name');
      $this->omf_meta_box_text($post, 'Reply-To（返信先メールアドレス）', 'cf_omf_reply_address');
      ?>
    </div>
  <?php
  }

  /**
   * 管理者宛メール
   *
   * @param WP_Post $post
   * @return void
   */
  public function admin_mail_meta_box_callback(WP_Post $post)
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
      $this->omf_meta_box_text($post, '送信元メールアドレス', 'cf_omf_admin_from');
      $this->omf_meta_box_text($post, '送信者', 'cf_omf_admin_from_name');
      ?>
    </div>
  <?php
  }

  /**
   * 表示条件
   *
   * @param WP_Post $post
   * @return void
   */
  public function condition_meta_box_callback(WP_Post $post)
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
   *
   * @param WP_Post $post
   * @return void
   */
  public function mail_id_meta_box_callback(WP_Post $post)
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
   * Slack通知
   *
   * @param WP_Post $post
   * @return void
   */
  public function slack_notify_meta_box_callback(WP_Post $post)
  {
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      $this->omf_meta_box_boolean($post, '送信内容をSlackに通知する', 'cf_omf_is_slack_notify');
      $this->omf_meta_box_text($post, 'Webhook URL', 'cf_omf_slack_webhook_url', 'SlackアプリのIncoming Webhookで生成されるWebhook URL');
      $this->omf_meta_box_text($post, 'チャンネル名', 'cf_omf_slack_channel', '先頭の # は不要');
      ?>
    </div>
  <?php
  }

  /**
   * スプレッドシート連携
   *
   * @param WP_Post $post
   * @return void
   */
  public function google_sheets_meta_box_callback(WP_Post $post)
  {
    $values        = $this->get_google_settings_values();
    $client_id     = $values['client_id'];
    $client_secret = $values['client_secret'];
    $redirect_uri  = $values['redirect_uri'];
    $access_token  = $values['access_token'];
    $is_credential = !empty($access_token) && !empty($client_id) && !empty($redirect_uri) && !empty($client_secret);
  ?>
    <div class="omf-metabox-wrapper">
      <?php
      if (!$is_credential) {
      ?>
        <p>スプレッドシート連携には<a href="<?php echo esc_url(admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings')) ?>">Google連携設定</a>が必要です。</p>
      <?php
      } else {
        $this->omf_meta_box_boolean($post, '送信内容をスプレッドシートに書き込む', 'cf_omf_is_google_sheets');
        $this->omf_meta_box_text($post, 'スプレッドシートID', 'cf_omf_google_sheets_id', 'シートIDはURLから取得(https://docs.google.com/spreadsheets/d/XXXXX/edit#gid=0のXXXXXの部分)');
        $this->omf_meta_box_text($post, 'シート名', 'cf_omf_google_sheets_name', '画面下部シートタブのいずれかのシートの名前を入力');
      }
      ?>
    </div>
  <?php
  }

  /**
   * メールフォームを選択
   *
   * @param WP_Post $post
   * @return void
   */
  public function select_mail_form_meta_box_callback(WP_Post $post)
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @param string $description
   * @return void
   */
  public function omf_meta_box_validation(WP_Post $post, string $title, string $meta_key, string $description = '')
  {
    $values = get_post_meta($post->ID, $meta_key, true);
  ?>
    <div class="omf-metabox omf-metabox--repeat">
      <?php
      if (!empty($values)) :
        foreach ((array)$values as $key => $value) :
          $target           = !empty($value['target']) ? sanitize_text_field(wp_unslash($value['target'])) : '';
          $min              = !empty($value['min']) ? sanitize_text_field(wp_unslash($value['min'])) : '';
          $max              = !empty($value['max']) ? sanitize_text_field(wp_unslash($value['max'])) : '';
          $required         = !empty($value['required']) ? sanitize_text_field(wp_unslash($value['required'])) : '';
          $tel              = !empty($value['tel']) ? sanitize_text_field(wp_unslash($value['tel'])) : '';
          $email            = !empty($value['email']) ? sanitize_text_field(wp_unslash($value['email'])) : '';
          $url              = !empty($value['url']) ? sanitize_text_field(wp_unslash($value['url'])) : '';
          $numeric          = !empty($value['numeric']) ? sanitize_text_field(wp_unslash($value['numeric'])) : '';
          $alpha            = !empty($value['alpha']) ? sanitize_text_field(wp_unslash($value['alpha'])) : '';
          $alphanumeric     = !empty($value['alphanumeric']) ? sanitize_text_field(wp_unslash($value['alphanumeric'])) : '';
          $katakana         = !empty($value['katakana']) ? sanitize_text_field(wp_unslash($value['katakana'])) : '';
          $hiragana         = !empty($value['hiragana']) ? sanitize_text_field(wp_unslash($value['hiragana'])) : '';
          $kana             = !empty($value['kana']) ? sanitize_text_field(wp_unslash($value['kana'])) : '';
          $throws_spam_away = !empty($value['throws_spam_away']) ? sanitize_text_field(wp_unslash($value['throws_spam_away'])) : '';
          $matching_char    = !empty($value['matching_char']) ? sanitize_text_field(wp_unslash($value['matching_char'])) : '';
          $date             = !empty($value['date']) ? sanitize_text_field(wp_unslash($value['date'])) : '';
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
                  <input type="number" name="<?php echo esc_attr("{$meta_key}[{$key}][min]") ?>" value="<?php echo esc_attr($min) ?>" min="0" max="9999" step="1">
                </span>
              </div>
              <div class="omf-metabox__row">
                <span>最大文字数</span>
                <span>
                  <input type="number" name="<?php echo esc_attr("{$meta_key}[{$key}][max]") ?>" value="<?php echo esc_attr($max) ?>" min="0" max="9999" step="1">
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
                <span>日付</span>
                <span>
                  <input type="checkbox" name="<?php echo esc_attr("{$meta_key}[{$key}][date]") ?>" value="1" <?php if ($date) echo esc_attr(' checked') ?>>
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
      <button class="omf-metabox__add-button" type="button" id="js-omf-repeat-add-button">項目を追加</button>
    </div>
  <?php
  }


  /**
   * テキストエリアのメタボックス
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @param string $description
   * @return void
   */
  public function omf_meta_box_textarea(WP_Post $post, string $title, string $meta_key, string $description = '')
  {
    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_textarea_field(wp_unslash($value)) : '';
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @return void
   */
  public function omf_meta_box_boolean(WP_Post $post, string $title, string $meta_key)
  {
    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @param string $description
   * @return void
   */
  public function omf_meta_box_text(WP_Post $post, string $title, string $meta_key, string $description = '')
  {
    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @return void
   */
  public function omf_meta_box_post_types(WP_Post $post, string $title, string $meta_key)
  {

    $value = get_post_meta($post->ID, $meta_key, true);
    if (!empty($value)) {
      $value = array_map(function ($val) {
        if (empty($val)) {
          return $val;
        }
        return sanitize_text_field(wp_unslash($val));
      }, $value);
    }

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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @param string $description
   * @return void
   */
  public function omf_meta_box_number(WP_Post $post, string $title, string $meta_key, string $description = '')
  {
    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @param string $description
   * @return void
   */
  public function omf_meta_box_side_text(WP_Post $post, string $title, string $meta_key, string $description = '')
  {
    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';
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
   *
   * @param WP_Post $post
   * @param string $title
   * @param string $meta_key
   * @return void
   */
  public function omf_meta_box_select_mail_forms(WP_Post $post, string $title, string $meta_key)
  {

    $mail_forms = $this->get_forms();
    if (empty($mail_forms)) {
      return;
    }

    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';

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
