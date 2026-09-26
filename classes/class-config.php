<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

class OMF_Config
{
  /**
   * ID
   * @var string
   */
  const NAME = 'original_mail_forms';

  /**
   * prefix
   * @var string
   */
  const PREFIX = 'omf_';

  /**
   * 問い合わせデータの投稿タイプ接頭辞
   * @var string
   */
  const DBDATA = 'omf_db_';

  /**
   * 扱うMIMEタイプの一覧
   * @var array
   */
  const ALLOWED_TYPES =  [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'zip'  => 'application/zip',
    'rar'  => 'application/x-rar-compressed',
    'txt'  => 'text/plain',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'avi'  => 'video/x-msvideo',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime'
  ];

  /**
   * 添付ファイルの拡張子指定がない場合に許可する拡張子（`omf_allowed_file_types`フィルターで変更可）
   * @var array
   */
  const DEFAULT_ALLOWED_FILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

  /**
   * 許可リストの設定に関わらず常に拒否する拡張子（実行可能ファイル・HTML・SVGなど）
   * @var array
   */
  const BLOCKED_FILE_EXTENSIONS = [
    'php',
    'php2',
    'php3',
    'php4',
    'php5',
    'php7',
    'php8',
    'phtml',
    'pht',
    'phar',
    'cgi',
    'pl',
    'py',
    'rb',
    'asp',
    'aspx',
    'jsp',
    'jspx',
    'sh',
    'exe',
    'dll',
    'com',
    'bat',
    'cmd',
    'html',
    'htm',
    'shtml',
    'svg',
    'svgz'
  ];
}
