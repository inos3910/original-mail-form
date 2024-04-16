<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;
use WP_Error;

trait OMF_Trait_Save_Db
{
  use OMF_Trait_Form;
  /**
   * 保存用のデータを生成
   *
   * @param array $info
   * @param array $mail
   * @param boolean $is_sended_admin 管理者宛メールの送信フラグ
   * @return array
   */
  private function create_save_data(array $info, array $mail, bool $is_sended_admin): array
  {
    if (
      empty($info) ||
      empty($mail) ||
      empty($info['tag_to_text']) ||
      empty($mail['subject']) ||
      empty($mail['mailaddress'])
    ) {
      return [];
    }

    $data = array_merge([
      'omf_mail_title'        => $mail['subject'],
      'omf_mail_to'           => $mail['mailaddress'],
    ], $info['tag_to_text']);

    $data = OMF_Utils::add_after_key($data, 'omf_reply_mail_sended', $is_sended_admin ? '送信成功' : '送信失敗', 'omf_admin_mail_sended');

    return $data;
  }

  /**
   * *DB保存
   *
   * @param WP_Post $form
   * @param array $data_to_save
   * @return void
   */
  private function save_data(WP_Post $form, array $data_to_save)
  {
    if (empty($form) || empty($data_to_save)) {
      return;
    }

    $is_use_db = get_post_meta($form->ID, 'cf_omf_save_db', true) === '1';
    if (!$is_use_db) {
      return;
    }

    $data_post_type = $this->get_data_post_type_by_id($form->ID);
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
}
