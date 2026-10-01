<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** ワーカーではブラウザのセッションと現在の画面に依存しない。 */
class OMF_Delivery_Mailer
{
  use OMF_Trait_Send;
  public int $delivery_receipt_id = 0;
  public function prepare(array $tags, int $form_id, array $attachments, array $ids): array
  {
    return ['reply_info'=>$this->get_reply_mail_info($form_id,$tags),'admin_info'=>$this->get_admin_mail_info($form_id,$tags),
      'attachments'=>$attachments,'attachment_ids'=>$ids,'disabled'=>$this->is_disable_reply_mail($form_id)];
  }
  public function send(array $payload, array $row, string $kind): bool
  {
    $info=$payload[$kind.'_info'];
    $info['tag_to_text']['omf_reply_mail_sended']=$this->reply_label($row);
    $attachments=$kind==='admin' ? $payload['attachments'] : [];
    // 受付時に保存した非公開添付だけを使用する。
    if ($kind==='admin') {
      $paths=array_map([OMF_Uploads::class,'path'],$payload['attachment_ids']);
      if (in_array('', $paths,true) || $paths!==$attachments) { return false; }
    }
    if ($kind==='reply') {
      do_action('omf_before_send_reply_mail',$info['tag_to_text'],$info['mail_to'],$info['form_title'],$info['mail_template'],$info['mail_from'],$info['from_name'],[]);
      $mail=$this->create_reply_mail($info,[]);
    } else {
      do_action('omf_before_send_admin_mail',$info['tag_to_text'],$info['mail_to'],$info['form_title'],$info['mail_template'],$info['mail_from'],$info['from_name']);
      $mail=$this->create_admin_mail($info,$attachments);
    }
    if (empty($mail['mailaddress'])) { return false; }
    return OMF_Mail_Transport::send($kind,(int)$row['form_id'],$mail['mailaddress'],$mail['subject'],$mail['message'],$mail['headers'],$mail['attachments']);
  }
  public function sent_hook(array $payload,array $row,string $kind): void
  {
    $info=$payload[$kind.'_info']; $info['tag_to_text']['omf_reply_mail_sended']=$this->reply_label($row);
    $mail=$kind==='reply' ? $this->create_reply_mail($info,[]) : $this->create_admin_mail($info,$payload['attachments']);
    do_action('omf_after_send_'.$kind.'_mail',$info['tag_to_text'],$mail['mailaddress'],$mail['subject'],$mail['message'],$mail['headers'],$mail['attachments']);
  }
  public function finish(array $payload,array $row): void
  {
    $form=get_post((int)$row['form_id']); if (!$form) { return; }
    $this->delivery_receipt_id=(int)$row['id'];
    $info=$payload['admin_info']; $info['tag_to_text']['omf_reply_mail_sended']=$this->reply_label($row);
    $mail=$this->create_admin_mail($info,$payload['attachments']);
    $this->after_send_admin($form,$info,$mail,$row['admin']==='sent');
    if (in_array($row['mode'], ['wp_async','server_cron'], true)) { do_action('omf_after_send_mail', $info['tag_to_text'], $form, (int)$payload['post_id']); }
    do_action('omf_delivery_completed',(int)$row['id'],(int)$row['form_id'],$row['reply'],$row['admin']);
  }
  private function reply_label(array $row): string
  { return $row['reply']==='skipped' ? '【自動返信】無効' : ($row['reply']==='sent' ? '【自動返信】送信成功' : '【自動返信】送信失敗'); }
}
