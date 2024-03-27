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
}
