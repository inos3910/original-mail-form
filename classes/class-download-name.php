<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * ダウンロードさせるファイルの名前を作る
 *
 * sanitize_file_name() は保存用のため、WP Multibyte Patch などが有効だと
 * 日本語名を md5 に置き換える。ダウンロード名には使わず、ここで最小限の整形だけ行う。
 */
class OMF_Download_Name
{
  /**
   * ファイル名に使えない文字・制御文字を除いて整える
   *
   * @param string $name 拡張子を含むファイル名
   * @param string $default 整形後に空になった場合の名前
   * @return string
   */
  public static function clean(string $name, string $default = 'download'): string
  {
    $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = trim($name, '. ');

    return $name === '' ? $default : $name;
  }

  /**
   * Content-Disposition ヘッダーの値
   * 日本語名は filename* で UTF-8 のまま渡し、filename には英数字だけの予備の名前を入れる
   *
   * @param string $name 表示したいファイル名（日本語可）
   * @param string $fallback 英数字だけの予備のファイル名
   * @return string
   */
  public static function content_disposition(string $name, string $fallback): string
  {
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fallback) ?? '';

    return 'attachment; filename="' . self::clean($fallback) . '"; filename*=UTF-8\'\'' . rawurlencode(self::clean($name));
  }
}
