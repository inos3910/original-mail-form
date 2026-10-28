<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 添付保存と保存先変更が同時に走ってファイルを見失うことを防ぐ。 */
class OMF_Upload_Lock
{
  const OPTION = 'omf_private_upload_lock';

  public static function run(callable $operation): mixed
  {
    $token = (time() + 300) . ':' . bin2hex(random_bytes(16));
    $previous = get_option(self::OPTION, '');
    if (is_string($previous) && $previous !== '' && (int) $previous < time()) { self::release($previous); }
    if (!add_option(self::OPTION, $token, '', false)) {
      throw new \RuntimeException('別の添付保存・保存先変更を処理中です。時間をおいて再度お試しください。');
    }
    try { return $operation(); }
    finally { self::release($token); }
  }

  private static function release(string $token): void
  {
    global $wpdb;
    // 期限切れの処理が、新しい処理のロックを解除しないよう値も照合する。
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $token));
    wp_cache_delete(self::OPTION, 'options');
    wp_cache_delete('notoptions', 'options');
  }
}
