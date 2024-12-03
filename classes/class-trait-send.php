<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Trait_Send
{
  use OMF_Trait_Google_Sheets, OMF_Trait_Slack, OMF_Trait_Save_Db;


  /**
   * 自動返信メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @param array $attachments 添付ファイル
   * @return boolean
   */
  private function send_reply_mail(array $post_data, int $post_id, array $attachments = []): bool
  {

    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    //自動返信メール情報を取得
    $info           = $this->get_reply_mail_info($form->ID, $post_data);
    $form_title     = $info['form_title'];
    $mail_to        = $info['mail_to'];
    $mail_template  = $info['mail_template'];
    $mail_from      = $info['mail_from'];
    $from_name      = $info['from_name'];
    $tag_to_text    = $info['tag_to_text'];

    //送信前のフック
    do_action('omf_before_send_reply_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name, $attachments);

    $mail                = $this->create_reply_mail($info, $attachments);
    $reply_mailaddress   = $mail['mailaddress'];
    $reply_subject       = $mail['subject'];
    $reply_message       = $mail['message'];
    $reply_headers       = $mail['headers'];
    $attachments         = $mail['attachments'];

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
      $reply_headers,
      //添付ファイル
      $attachments
    );

    if ($is_sended_reply) {
      //送信後のフック
      do_action('omf_after_send_reply_mail', $tag_to_text, $reply_mailaddress, $reply_subject, $reply_message, $reply_headers, $attachments);
    }

    return $is_sended_reply;
  }

  /**
   * 通知メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @param array $attachments 添付ファイル
   * @return boolean
   */
  private function send_admin_mail(array $post_data, int $post_id, array $attachments = []): bool
  {
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    //メール情報を取得
    $info          = $this->get_admin_mail_info($form->ID, $post_data);
    $form_title    = $info['form_title'];
    $mail_to       = $info['mail_to'];
    $mail_template = $info['mail_template'];
    $mail_from     = $info['mail_from'];
    $from_name     = $info['from_name'];
    $tag_to_text   = $info['tag_to_text'];

    //送信前のフック
    do_action('omf_before_send_admin_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name);

    $mail                = $this->create_admin_mail($info, $attachments);
    $admin_mailaddress   = $mail['mailaddress'];
    $admin_subject       = $mail['subject'];
    $admin_message       = $mail['message'];
    $admin_headers       = $mail['headers'];
    $attachments         = $mail['attachments'];

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
      $admin_headers,
      //添付ファイル
      $attachments
    );

    //メール送信成功時
    if ($is_sended_admin) {
      //送信後のフック
      do_action('omf_after_send_admin_mail', $tag_to_text, $admin_mailaddress, $admin_subject, $admin_message, $admin_headers, $attachments);
    }

    //送信後に実行するオプション
    $this->after_send_admin($form, $info, $mail, $is_sended_admin);

    return $is_sended_admin;
  }

  /**
   * 自動返信メール作成用の基本情報をDBから取得
   *
   * @param integer $form_id
   * @param array $post_data
   * @return array
   */
  private function get_reply_mail_info(int $form_id, array $post_data): array
  {
    $form_title    = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_title', true));
    $mail_to       = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_to', true));
    $mail_template = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_mail', true), true);
    $mail_from     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_from', true));
    $mail_from     = !empty($mail_from) ? str_replace(PHP_EOL, '', $mail_from) : '';
    $from_name     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(PHP_EOL, '', $from_name) : '';
    $mail_reply_to = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_address', true));

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html(OMF_Utils::get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/'))
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    return [
      'form_title'    => $form_title,
      'mail_to'       => $mail_to,
      'mail_template' => $mail_template,
      'mail_from'     => $mail_from,
      'from_name'     => $from_name,
      'mail_reply_to' => $mail_reply_to,
      'tag_to_text'   => $tag_to_text
    ];
  }

  /**
   * 自動返信メールを作成
   *
   * @param array $info
   * @param array $attachments
   * @return array
   */
  private function create_reply_mail(array $info, array $attachments): array
  {

    if (empty($info['tag_to_text'])) {
      return [];
    }

    $tag_to_text = $info['tag_to_text'];

    //宛先
    $mailaddress = $this->replace_form_mail_tags($info['mail_to'], $tag_to_text);
    //件名
    $subject = $this->replace_form_mail_tags($info['form_title'], $tag_to_text);
    //メール本文のifタグを置換
    $mail_template = $this->replace_form_mail_if_tags($info['mail_template'], $tag_to_text);
    //メールタグを置換
    $message = $this->replace_form_mail_tags($mail_template, $tag_to_text);

    //フィルターを通す
    $message = apply_filters('omf_reply_mail', $message, $tag_to_text);

    //メールヘッダー
    $headers = [];
    if (!empty($info['from_name'] && !empty($info['mail_from']))) {
      $headers[]   = "From: {$info['from_name']} <{$info['mail_from']}>";

      $reply_to = !empty($info['mail_reply_to']) ? $info['mail_reply_to'] : $info['mail_from'];
      $headers[]   = "Reply-To: {$info['from_name']} <{$reply_to}>";

      $headers     = implode(PHP_EOL, $headers);
    }

    return [
      'mailaddress' => $mailaddress,
      'subject'     => $subject,
      'message'     => $message,
      'headers'     => $headers,
      'attachments' => $attachments,
    ];
  }


  /**
   * 通知メール作成用の基本情報をDBから取得
   *
   * @param integer $form_id
   * @param array $post_data
   * @return array
   */
  private function get_admin_mail_info(int $form_id, array $post_data): array
  {
    $form_title    = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_title', true));
    $mail_to       = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_to', true));
    $mail_template = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_mail', true), true);
    $mail_from     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_from', true));
    $mail_from     = !empty($mail_from) ? str_replace(PHP_EOL, '', $mail_from) : '';
    $from_name     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(PHP_EOL, '', $from_name) : '';

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html(OMF_Utils::get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/')),
      'user_agent'    => $_SERVER["HTTP_USER_AGENT"],
      'user_ip'       => $_SERVER["REMOTE_ADDR"],
      'host'          => gethostbyaddr($_SERVER["REMOTE_ADDR"])
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    return [
      'form_title'    => $form_title,
      'mail_to'       => $mail_to,
      'mail_template' => $mail_template,
      'mail_from'     => $mail_from,
      'from_name'     => $from_name,
      'tag_to_text'   => $tag_to_text
    ];
  }

  /**
   * 通知メールを作成
   *
   * @param array $info
   * @param array $attachments
   * @return array
   */
  private function create_admin_mail(array $info, array $attachments): array
  {
    if (empty($info['tag_to_text'])) {
      return [];
    }

    $tag_to_text = $info['tag_to_text'];

    //宛先
    $mailaddress = $this->replace_form_mail_tags($info['mail_to'], $tag_to_text);
    //件名
    $subject = $this->replace_form_mail_tags($info['form_title'], $tag_to_text);

    //メール本文のifタグを置換
    $mail_template = $this->replace_form_mail_if_tags($info['mail_template'], $tag_to_text);

    //メールタグを置換
    $message = $this->replace_form_mail_tags($mail_template, $tag_to_text);

    //フィルターを通す
    $message = apply_filters('omf_admin_mail', $message, $tag_to_text);

    //メールヘッダー
    $headers = [];
    if (!empty($info['from_name'] && !empty($info['mail_from']))) {
      $headers[]   = "From: {$info['from_name']} <{$info['mail_from']}>";
      $headers[]   = "Reply-To: {$info['from_name']} <{$info['mail_from']}>";
      $headers     = implode(PHP_EOL, $headers);
    }

    return [
      'mailaddress' => $mailaddress,
      'subject'     => $subject,
      'message'     => $message,
      'headers'     => $headers,
      'attachments' => $attachments
    ];
  }

  /**
   * アップロードファイルを配列に追加する
   *
   * @param array $post_data
   * @return array
   */
  private function add_uploaded_files(array $post_data): array
  {
    if (empty($_FILES)) {
      return $post_data;
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');



    $new_data = [];

    foreach ($_FILES as $input_name => $file) {
      if (!is_uploaded_file($file['tmp_name'])) {
        $new_data[$input_name] = [];
        continue;
      }

      // ファイルがアップロードされたかどうかをチェック
      if ($file['error'] === UPLOAD_ERR_OK) {

        // ファイル内容を読み取る
        if (WP_Filesystem()) {
          global $wp_filesystem;
          $file_contents = $wp_filesystem->get_contents($file['tmp_name']);
        } else {
          $file_contents = file_get_contents($file['tmp_name']);
        }

        //ファイルを保存
        $file_data = [
          'name'     => $file['name'],
          'tmp_name' => $file['tmp_name'],
          'type'     => $file['type'],
          'size'     => $file['size'],
          'contents' => $file_contents,
        ];


        //ファイルを保存
        $attachment_id = $this->save_file($file_data);
        if (empty($attachment_id)) {
          continue;
        }

        $new_data[$input_name] = $attachment_id;

        //画像の場合
        $image = wp_get_attachment_image_src($attachment_id, 'medium');

        // ファイル情報をセッションに保存
        $new_data[$input_name] = !empty($image) ? [
          'name'             => $file['name'],
          'tmp_name'         => $file['tmp_name'],
          'type'             => $file['type'],
          'size'             => $file['size'],
          'attachment_id'    => $attachment_id,
          'image'            => [
            'src'    => $image[0],
            'width'  => $image[1],
            'height' => $image[2]
          ]
        ] : [
          'name'             => $file['name'],
          'tmp_name'         => $file['tmp_name'],
          'type'             => $file['type'],
          'size'             => $file['size'],
          'attachment_id'    => $attachment_id,
        ];
      }
    }

    return array_merge($post_data, $new_data);
  }

  /**
   * タグの中から添付ファイル一覧を作成・WPにファイルを保存
   *
   * @param array $tags
   * @return array
   */
  private function convert_attachments(array $tags): array
  {

    if (empty($tags)) {
      return [];
    }

    $attachment_paths = [];
    $attachment_ids   = [];
    $new_tags         = [];

    foreach ((array)$tags as $key => $tag) {
      if (
        is_array($tag) &&
        !empty($tag['name']) &&
        !empty($tag['type']) &&
        !empty($tag['attachment_id'])
      ) {

        $attachment_id      = $tag['attachment_id'];
        $attachment_paths[] = get_attached_file($attachment_id);
        $attachment_ids[]   = $attachment_id;
        $image              = !empty($tag['image']) ? $tag['image'] : null;
        $url                = wp_get_attachment_url($attachment_id);

        $new_tags[$key] = !empty($image) ? [
          'id'     => $attachment_id,
          'name'   => $tag['name'],
          'url'    => $url,
          'src'    => $image['src'],
          'width'  => $image['width'],
          'height' => $image['height']
        ] : [
          'id'     => $attachment_id,
          'name'   => $tag['name'],
          'url'    => $url,
        ];
      } else {
        $new_tags[$key] = $tag;
      }
    }

    return [
      'attachment_ids'   => $attachment_ids,
      'attachment_paths' => $attachment_paths,
      'tags'             => $new_tags
    ];
  }

  /**
   * ファイルを保存
   *
   * @param array $file
   * @return integer|string
   */
  private function save_file(array $file): int|string
  {
    $empty_path = '';

    if (empty($file)) {
      return $empty_path;
    }

    $name = $file['name'] ?? '';
    $type = $file['type'] ?? '';
    $contents = $file['contents'] ?? '';

    if (empty($name) || empty($type) || empty($contents)) {
      return $empty_path;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $wp_upload_dir = wp_upload_dir();
    $wp_upload_dir_path = trailingslashit($wp_upload_dir['path']);

    // アップロード用ディレクトリがなければ作成
    if (!file_exists($wp_upload_dir_path)) {
      mkdir($wp_upload_dir_path, 0755, true);
    }

    $file_info = pathinfo($name);
    $filename = sanitize_file_name(basename($name, '.' . $file_info['extension']));
    $extension = $file_info['extension'];

    // 重複するファイル名を避ける
    $filename = wp_unique_filename($wp_upload_dir_path, $filename . '.' . $extension);
    $file_path = $wp_upload_dir_path . $filename;

    // WP_Filesystemを使ってファイル書き込み
    $saved_file = false;
    if (WP_Filesystem()) {
      global $wp_filesystem;
      $saved_file = $wp_filesystem->put_contents($file_path, $contents);
    }

    if (!$saved_file) {
      return $empty_path;
    }

    $attachment = [
      'guid'           => $wp_upload_dir['url'] . '/' . basename($file_path),
      'post_mime_type' => $type,
      'post_title'     => sanitize_file_name(pathinfo($file_path, PATHINFO_FILENAME)),
      'post_content'   => '',
      'post_status'    => 'inherit'
    ];

    $attachment_id = wp_insert_attachment($attachment, $file_path);

    if (is_wp_error($attachment_id) || empty($attachment_id)) {
      return $empty_path;
    }

    $metadata = [
      'file'       => basename($file_path), // ファイル名
      'sizes'      => [], // 生成されたサムネイルの情報（空の配列）
      'image_meta' => [] // 画像のメタ情報（空の配列）
    ];

    //画像の場合のみ
    if (strpos($type, 'image/') === 0) {
      // 画像のメタデータを取得
      list($width, $height) = getimagesize($file_path);
      // メタデータを手動で生成
      $metadata['width'] = $width;
      $metadata['height'] = $height;
    }
    wp_update_attachment_metadata($attachment_id, $metadata);

    //サムネイルの生成
    $this->generate_medium_thumbnail($attachment_id, $file_path);

    // 一時タグを追加
    wp_set_object_terms($attachment_id, 'temporary', 'media_tag');

    return $attachment_id;
  }

  /**
   * サムネイル画像の生成
   *
   * @param integer|string $attachment_id
   * @param string $file_path
   * @return void
   */
  private function generate_medium_thumbnail(int|string $attachment_id, string $file_path)
  {
    // 画像エディタオブジェクトを取得
    $image_editor = wp_get_image_editor($file_path);

    // ファイルが画像でない場合、エラーを返す
    if (is_wp_error($image_editor)) {
      return false;
    }

    // WordPressの設定から 'medium' サイズを取得
    $size = [
      'width'  => !empty(get_option('medium_size_w')) ? get_option('medium_size_w') : 300,
      'height' => !empty(get_option('medium_size_h')) ? get_option('medium_size_h') : 300,
      'crop'   => get_option('medium_crop'),
    ];

    // 画像をリサイズ
    $resized = $image_editor->resize($size['width'], $size['height'], $size['crop']);

    // リサイズ中にエラーが発生した場合、処理を中断
    if (is_wp_error($resized)) {
      return $resized;
    }

    // 保存先のパスを設定
    $destination = $image_editor->generate_filename('medium', null, null);
    $saved = $image_editor->save($destination);

    // 保存中にエラーが発生した場合、処理を中断
    if (is_wp_error($saved)) {
      return $saved;
    }

    // 既存のメタデータを取得
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata)) {
      // 'medium' サイズのメタデータを作成
      $metadata['sizes']['medium'] = [
        'file'      => basename($destination),
        'width'     => $saved['width'],
        'height'    => $saved['height'],
        'mime-type' => $saved['mime-type'],
      ];
      // メタデータを更新
      wp_update_attachment_metadata($attachment_id, $metadata);
      return true;
    }

    return false;
  }


  /**
   * 添付ファイルの一時タグを削除
   *
   * @param array $attachment_ids
   * @return void
   */
  private function remove_temporary_media_tag(array $attachment_ids)
  {
    if (empty($attachment_ids)) {
      return;
    }

    foreach ($attachment_ids as $attachment_id) {
      // 一時タグを削除
      wp_remove_object_terms($attachment_id, 'temporary', 'media_tag');
    }
  }

  /**
   * メールタグのif文を置換
   * @param string $text
   * @param array $tags
   * @return string
   */
  private function replace_form_mail_if_tags(string $text, array $tags): string
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
   * @param  string|null $text
   * @param  array $tag_to_text
   * @return string
   */
  private function replace_form_mail_tags(string|null $text, array  $tag_to_text): string
  {
    if (empty($text)) {
      return '';
    }

    preg_match_all('/\{(.+?)\}/', $text, $matches);

    if (!empty($matches[1])) {
      foreach ($matches[1] as $tag) {
        $replacement_text = isset($tag_to_text[$tag]) ? $tag_to_text[$tag] : '';

        //タグの中身がファイル（配列）の場合は空にする　
        if (is_array($replacement_text) && !empty($replacement_text['name'])) {
          $replacement_text = $replacement_text['name'];
        }

        $replacement_text = apply_filters('omf_mail_tag', $replacement_text, $tag);
        if (empty($replacement_text)) {
          $replacement_text = '';
        }

        $text = str_replace("{" . $tag . "}", $replacement_text, $text);
      }
    }

    return $text;
  }

  /**
   * 通知メール送信後に実行
   *
   * @param WP_Post $form
   * @param array $info
   * @param array $mail
   * @param boolean $is_sended_admin
   * @return void
   */
  private function after_send_admin(WP_Post $form, array $info, array $mail, bool $is_sended_admin)
  {
    //保存用の配列を生成
    $data_to_save = $this->create_save_data($info, $mail, $is_sended_admin);

    // DB保存
    $this->save_data($form, $data_to_save);

    //API送信データをまとめる
    $webhook_data = [];

    //Slack通知
    $webhook_data[] = $this->get_send_slack_params($form, $info, $mail, $is_sended_admin);

    //スプレッドシート書き込み
    $webhook_data[] = $this->get_google_sheets_params($form, $data_to_save);

    //一括でまとめて送信
    OMF_Utils::curl_multi_posts($webhook_data);
  }
}
