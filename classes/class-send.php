<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Send
{
  use OMF_Trait_Google_Sheets, OMF_Trait_Slack, OMF_Trait_Save_Db;
  /**
   * 自動返信メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @return boolean
   */
  private function send_reply_mail(array $post_data, int $post_id = null): bool
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
    do_action('omf_before_send_reply_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name);

    $mail              = $this->create_reply_mail($info);
    $reply_mailaddress = $mail['mailaddress'];
    $reply_subject     = $mail['subject'];
    $reply_message     = $mail['message'];
    $reply_headers     = $mail['headers'];

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
   * @param int $post_id フォームを設置したページのID
   * @return boolean
   */
  private function send_admin_mail(array $post_data, int $post_id = null): bool
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

    $mail              = $this->create_admin_mail($info);
    $admin_mailaddress = $mail['mailaddress'];
    $admin_subject     = $mail['subject'];
    $admin_message     = $mail['message'];
    $admin_headers     = $mail['headers'];

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
   * @return array
   */
  private function create_reply_mail(array $info): array
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
      'headers'     => $headers
    ];
  }


  /**
   * 管理者通知メール作成用の基本情報をDBから取得
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
   * 管理者通知メールを作成
   *
   * @param array $info
   * @return array
   */
  private function create_admin_mail(array $info): array
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
      'headers'     => $headers
    ];
  }

  /**
   * メールタグのif文を置換
   * @param string $text
   * @param array $tags
   * @return string
   */
  private function replace_form_mail_if_tags($text, $tags): string
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
   * 管理者通知メール送信後に実行
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

    //slack送信用データを作成
    $slack_data = $this->create_send_slack_data($form, $info, $mail, $is_sended_admin);
    //Slack通知
    $this->send_slack($slack_data);

    //スプレッドシート書き込み
    $this->send_google_sheets($form, $data_to_save);
  }
}
