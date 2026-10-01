<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** Page/RESTで共通の検証順序と、部分成功時の再送制御。 */
trait OMF_Trait_Submission
{
  private function validate_submission(array &$data, int $post_id = 0): array
  {
    $form = $this->get_form($post_id);
    if (empty($form)) { return ['undefined' => ['フォームが見つかりません。']]; }
    // 未保存の必須ファイルは存在だけを仮置きし、他項目とCAPTCHAの検証後に実体を検証する。
    $check = $data;
    foreach ($this->get_file_field_targets($form->ID) as $target) {
      if (isset($_FILES[$target]['error']) && $_FILES[$target]['error'] !== UPLOAD_ERR_NO_FILE) {
        $check[$target] = ['pending' => true];
      }
    }
    $errors = $this->validate_mail_form_data($check, $post_id);
    if ($errors !== []) { return $errors; }
    $data = $this->process_uploaded_files($data, $post_id);
    return $this->validate_mail_form_data($data, $post_id);
  }

  private function restore_uploaded_files(array $data, int|string|null $post_id = null): array
  {
    $form = $this->get_form($post_id);
    if (empty($form)) { return []; }
    $key = OMF_Embed_Context::prefix($form->post_name) . '_uploaded_files';
    if (OMF_Field_Schema::mode($form->ID) === 'code') {
      // POSTの辞書を旧添付と見なさず、更新前にサーバーが保管したセッションだけを移行する。
      $old_data = $_SESSION[OMF_Embed_Context::prefix($form->post_name) . '_data'] ?? [];
      foreach ($old_data as $target => $file) {
        if (!is_array($file) || empty($file['attachment_id']) || !empty($file['upload_id']) || isset($_SESSION[$key][$target])) { continue; }
        try { $imported = OMF_Legacy_Uploads::import($file, $form->ID); }
        catch (\Throwable $e) { $imported = []; }
        if ($imported !== []) { $_SESSION[$key][$target] = $imported; }
        else {
          unset($data[$target]);
          $this->extra_errors[$target][] = '添付ファイルを引き継げませんでした。入力画面でもう一度添付してください。';
        }
      }
    }
    foreach ($this->get_file_field_targets($form->ID) as $target) {
      unset($data[$target]);
      $file = $_SESSION[$key][$target] ?? [];
      if (!empty($file['upload_id']) && OMF_Uploads::path($file['upload_id']) !== '') { $data[$target] = $file; }
    }
    return $data;
  }

  private function deliver_submission(array $data, int $form_id, int $post_id): array
  {
    if (class_exists(OMF_Delivery::class) && OMF_Delivery::mode($form_id) !== 'serial') {
      return OMF_Delivery::accept($data, $form_id, $post_id, $this->convert_attachments($data));
    }
    $form = $this->get_form($post_id);
    $key = OMF_Embed_Context::prefix($form->post_name) . '_delivery';
    $fingerprint_data = $data;
    unset($fingerprint_data['mail_id']);
    $hash = hash('sha256', serialize($fingerprint_data));
    $state = $_SESSION[$key] ?? [];
    if (($state['hash'] ?? '') !== $hash) {
      $state = ['hash' => $hash, 'reply' => false, 'admin' => false];
    }
    $converted = $this->convert_attachments($data);
    $tags = $converted['tags'];
    $disabled = $this->is_disable_reply_mail($form_id);
    if ($disabled) { $state['reply'] = true; }
    if (!$state['reply']) {
      $reply = $this->send_reply_mail($tags, $post_id);
      $state['reply'] = $reply === true || $reply === 'no-reply';
      $state['reply_skipped'] = $reply === 'no-reply';
    }
    $tags['omf_reply_mail_sended'] = $disabled ? '【自動返信】無効' : (!empty($state['reply_skipped']) ? '【自動返信】スキップ（宛先なし）' : ($state['reply'] ? '【自動返信】送信成功' : '【自動返信】送信失敗'));
    if (!$state['admin']) {
      $state['admin'] = $this->send_admin_mail($tags, $post_id, $converted['attachment_paths']);
    } else {
      // 通知済みの場合も、再試行した自動返信の結果を保存データへ反映する。
      $this->update_saved_reply_result($form, $tags['omf_reply_mail_sended']);
    }
    $_SESSION[$key] = $state;
    $success = $state['reply'] && $state['admin'];
    if ($success) {
      OMF_Legacy_Uploads::retain($converted['attachment_ids']);
      foreach ($converted['attachment_ids'] as $id) { OMF_Uploads::remove($id); }
      unset($_SESSION[OMF_Embed_Context::prefix($form->post_name) . '_uploaded_files']);
      unset($_SESSION[OMF_Embed_Context::prefix($form->post_name) . '_captcha']);
    }
    return ['is_sended' => $success, 'is_sended_reply' => $state['reply'], 'is_sended_admin' => $state['admin']];
  }
}
