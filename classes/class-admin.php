<?php
class OMF_Admin
{

  public function __construct()
  {
    //管理画面
    add_action('init', [$this, 'create_post_type']);
    add_filter('manage_edit-' . OMF_Config::NAME . '_columns', [$this, 'custom_posts_columns']);
    add_action('manage_' . OMF_Config::NAME . '_posts_custom_column', [$this, 'add_column'], 10, 2);
    add_action('admin_menu', [$this, 'add_admin_setting_menu']);
    add_action('admin_menu', [$this, 'add_admin_recaptcha_menu']);
    add_action('add_meta_boxes_' . OMF_Config::NAME, [$this, 'add_meta_box_omf']);
    add_action('add_meta_boxes', [$this, 'add_meta_box_posts']);
    add_action('save_post', [$this, 'save_omf_custom_field']);
    add_action('admin_enqueue_scripts', [$this, 'add_omf_scripts']);
    add_action('admin_enqueue_scripts', [$this, 'add_omf_styles']);
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
  }

  /**
   * 投稿タイプ一覧画面のカスタマイズ
   *
   * @param [type] $columns
   * @return void
   */
  public function custom_posts_columns($columns)
  {
    unset($columns['author']);
    //「日時」列を最後に持ってくる為、一旦クリア
    if (isset($columns['date'])) {
      $date = $columns['date'];
      unset($columns['date']);
    }

    $columns['slug'] = "スラッグ";
    $columns['entry'] = "フォーム入力画面";
    $columns['recaptcha'] = "reCAPTCHA設定";

    // 「日時」列を再セット
    if (isset($date)) {
      $columns['date']   = $date;
    }

    return $columns;
  }


  /**
   * 投稿タイプ一覧画面に値を出力
   *
   * @param [type] $column_name
   * @param [type] $post_id
   * @return void
   */
  public function add_column($column_name, $post_id)
  {
    //スラッグ
    if ($column_name === 'slug') {
      $post = get_post($post_id);
      if (!empty($post)) {
        echo esc_html($post->post_name);
      }
    }
    //入力画面
    elseif ($column_name === 'entry') {
      $entry_page = get_post_meta($post_id, 'cf_omf_screen_entry', true);
      echo esc_html($entry_page);
    }
    // reCAPTCHA設定
    elseif ($column_name === 'recaptcha') {
      $is_recaptcha = get_post_meta($post_id, 'cf_omf_recaptcha', true);
      if (!empty($is_recaptcha) && $is_recaptcha == 1) {
        echo esc_html('有効');
      } else {
        echo esc_html('無効');
      }
    }
    //それ以外
    else {
      return;
    }
  }

  /**
   * CSSの追加
   */
  public function add_omf_styles()
  {
    wp_enqueue_style('omf-admin-style', plugins_url('../dist/css/style.css', __FILE__));
  }

  /**
   * JSを追加
   *
   * @return void
   */
  public function add_omf_scripts()
  {
    global $post_type;
    if ($post_type === OMF_Config::NAME) {
      wp_enqueue_script('omf-script', plugins_url('../dist/js/main.js', __FILE__), [], '1.0', true);
    }
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
?>
    <div class="wrap">
      <h1>プラグインの更新</h1>
      <?php
      if (filter_input(INPUT_POST, 'update_omf', FILTER_SANITIZE_NUMBER_INT) === '1') {
        $this->update_plugin_from_github();
      }
      ?>
      <div class="admin_optional">
        <form method="post" action="">
          <p>Github上で管理している最新のmasterブランチのファイルに更新します。</p>
          <p><a href="https://github.com/inos3910/original-mail-form" target="_blank" rel="noopener">GitHubリポジトリはこちら →</a></p>
          <button class="button" type="submit" name="update_omf" value="1">更新開始</button>
        </form>
      </div>
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
    //WP_Filesystem
    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $github_repo_url = 'https://github.com/inos3910/original-mail-form/archive/master.zip';
    $plugin_dir = plugin_dir_path(__FILE__) . '../';

    $response = wp_safe_remote_get($github_repo_url);

    if (is_wp_error($response)) {
      echo '<div class="error"><p>GitHubからファイルを取得する際にエラーが発生しました。</p></div>';
    }

    $zip_content = wp_remote_retrieve_body($response);
    $temp_zip_path = sys_get_temp_dir() . '/github-update.zip';

    if (WP_Filesystem()) {
      global $wp_filesystem;
      $is_saved_tmp = $wp_filesystem->put_contents($temp_zip_path, $zip_content);
      if (!$is_saved_tmp) {
        echo '<div class="error"><p>一時ファイルの保存に失敗しました。</p></div>';
      }
    }

    // ZIPファイルの中身をプラグインディレクトリにコピー
    $zip = new ZipArchive;
    if ($zip->open($temp_zip_path) === true) {
      //除外ファイル
      $excluded_files = ['.gitignore', 'package.json', 'package-lock.json', 'yarn.lock', 'webpack.config.js', 'readme.md'];
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

    //一時ファイルを削除
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
    <div class="wrap">
      <h1>reCAPTCHA設定</h1>
      <div class="admin_optional">
        <form method="post" action="options.php">
          <?php
          settings_fields('recaptcha-settings-group');
          do_settings_sections('recaptcha-settings-group');

          $recaptcha_site_key = !empty(get_option('omf_recaptcha_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_site_key'))) : '';
          $recaptcha_secret_key = !empty(get_option('omf_recaptcha_secret_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_secret_key'))) : '';
          $recaptcha_score = !empty(get_option('omf_recaptcha_score')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_score'))) : '';
          $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
          ?>
          <p><a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">reCAPTCHA v3 コンソールでキーを取得 →</a></p>
          <table class="form-table">
            <tr>
              <th scope="row">reCAPTCHA v3 サイトキー</th>
              <td>
                <p>
                  <input class="regular-text code" type="text" name="omf_recaptcha_site_key" value="<?php echo esc_attr($recaptcha_site_key); ?>">
                </p>
              </td>
            </tr>
            <tr>
              <th scope="row">reCAPTCHA v3 シークレットキー</th>
              <td>
                <p>
                  <input class="regular-text code" type="text" name="omf_recaptcha_secret_key" value="<?php echo esc_attr($recaptcha_secret_key); ?>">
                </p>
              </td>
            </tr>
            <tr>
              <th scope="row">しきい値（0.0 - 1.0）</th>
              <td>
                <p>
                  <input class="small-text" type="number" pattern="\d*" min="0.0" max="1.0" step="0.1" name="omf_recaptcha_score" value="<?php echo esc_attr($recaptcha_score) ?>">
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
    </div>
  <?php
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
      'cf_omf_reply_from_name',
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
      $this->omf_meta_box_text($post, '送信元メールアドレス', 'cf_omf_reply_from');
      $this->omf_meta_box_text($post, '送信者', 'cf_omf_reply_from_name');
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
      $this->omf_meta_box_text($post, '送信元メールアドレス', 'cf_omf_admin_from');
      $this->omf_meta_box_text($post, '送信者', 'cf_omf_admin_from_name');
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
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_textarea($post, $title, $meta_key, $description = null)
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
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_text($post, $title, $meta_key, $description = null)
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
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   */
  public function omf_meta_box_post_types($post, $title, $meta_key)
  {

    $value = get_post_meta($post->ID, $meta_key, true);
    $value = !empty($value) ? sanitize_text_field(wp_unslash($value)) : '';

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
   * @param  WP_Post $post
   * @param  string $title       タイトル
   * @param  string $meta_key    カスタムフィールド名
   * @param  string $description 説明
   */
  public function omf_meta_box_side_text($post, $title, $meta_key, $description = null)
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
