<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Trait_Slack
{
  /**
   * Slackに送信内容を通知
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
    $form_title = OMF_Utils::custom_escape($form_title);

    $notify_data = [
      "channel"     => "#{$channel}", //チャンネル名
      "username"    => "メールフォーム", //BOT名
      "icon_emoji"  => ":seal:", //アイコン
      "attachments" => [
        [
          "pretext"     => "▼通知：{$form_title}",
          "color"       => "#10afaa",
          "title"       => $subject,
          "text"        => "───────────────────\n{$message}",
          "footer"      => !empty($post_data['site_name']) ? $post_data['site_name'] : '',
          "footer_icon" => get_site_icon_url(),
        ]
      ]
    ];

    OMF_Utils::curl_post($webhook_url, $notify_data);
  }

  /**
   * Slack送信用の配列を作成
   *
   * @return array
   */
  private function create_send_slack_data(WP_Post $form, array $info, array $mail, bool $is_sended_admin): array
  {
    return [
      'subject'        => $mail['subject'] ?? '',
      'message'        => $mail['message'] ?? '',
      'post_data'      => $info['tag_to_text'] ?? '',
      'form'           => $form,
      'is_mail_sended' => $is_sended_admin
    ];
  }
}
