<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** メールの共通拡張点と、個人情報を含まない時間計測。 */
class OMF_Mail_Transport
{
  public static function send(string $kind, int $form_id, $to, $subject, $message, $headers, $attachments): bool
  {
    $args = compact('to', 'subject', 'message', 'headers', 'attachments');
    $filtered = apply_filters('omf_mail_args', $args, $kind, $form_id);
    if (!is_array($filtered)) { return false; }
    $args = array_replace($args, array_intersect_key($filtered, $args));
    // フックから追加のサーバーファイルを添付できない。
    $args['attachments'] = array_values(array_intersect((array) $args['attachments'], (array) $attachments));
    $start = microtime(true);
    $ok = false;
    try { $ok = wp_mail($args['to'], $args['subject'], $args['message'], $args['headers'], $args['attachments']); }
    finally {
      do_action('omf_operation_timing', 'mail_' . $kind, (microtime(true) - $start) * 1000, $form_id);
      do_action('omf_mail_result', $ok, $kind, $form_id);
    }
    return $ok;
  }
}
