<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Trait_Save_Db
{
  use OMF_Trait_Form;

  /** 通知を再送せず、同じ受付の自動返信結果だけを更新する。 */
  private function update_saved_reply_result(WP_Post $form, string $result): void
  {
    $key = OMF_Embed_Context::prefix($form->post_name) . '_saved_data';
    $post_id = (int) ($_SESSION[$key] ?? 0);
    if ($post_id && get_post_type($post_id) === $this->get_data_post_type_by_id($form->ID)) {
      update_post_meta($post_id, 'omf_reply_mail_sended', $result);
    }
  }
  /**
   * 保存用のデータを生成
   *
   * @param array $info
   * @param array $mail
   * @param boolean $is_sended_admin 通知メールの送信フラグ
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

    $data = OMF_Utils::add_after_key($data, 'omf_reply_mail_sended', $is_sended_admin ? '【通知】送信成功' : '【通知】送信失敗', 'omf_admin_mail_sended');

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

    $key = OMF_Embed_Context::prefix($form->post_name) . '_saved_data';
    $receipt_id = (int) ($this->delivery_receipt_id ?? 0);
    $existing_id = $receipt_id ? (int) (get_posts(['post_type'=>$data_post_type,'fields'=>'ids','posts_per_page'=>1,'meta_key'=>'_omf_receipt_id','meta_value'=>$receipt_id])[0] ?? 0) : (int) ($_SESSION[$key] ?? 0);
    if ($receipt_id) { $data_to_save['_omf_receipt_id'] = $receipt_id; }
    $post_id = wp_insert_post([
      'ID' => $existing_id && get_post_type($existing_id) === $data_post_type ? $existing_id : 0,
      'post_type'   => $data_post_type,
      'post_title'  => $data_to_save['omf_mail_title'],
      'post_status' => 'publish',
      'meta_input'  => $data_to_save
    ]);

    if ($receipt_id && (empty($post_id) || is_wp_error($post_id))) { throw new \RuntimeException('受付記録のDB保存に失敗しました。'); }
    if (!empty($post_id) && !is_wp_error($post_id)) {
      if (!$receipt_id) { $_SESSION[$key] = $post_id; }
      //スラッグを重複回避でIDにしておく
      wp_update_post([
        'ID'        => $post_id,
        'post_name' => $post_id
      ]);
    }
  }
}
