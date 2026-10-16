<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポート／インポートで扱う項目の定義（許可リスト）。
 */
class OMF_Port_Schema
{
  const FORMAT = 'omf-export';
  const VERSION = 1;
  const MAX_BYTES = 2097152;
  const META_PREFIX = 'cf_omf_';
  const META_MAIL_ID = 'cf_omf_mail_id';
  const META_CONDITION_ID = 'cf_omf_condition_id';
  const META_SELECT = 'cf_omf_select';
  const META_SLACK_WEBHOOK = 'cf_omf_slack_webhook_url';
  const FORM_STATUSES = ['publish', 'draft', 'pending', 'private'];

  /** 常に書き出す一般オプション。 */
  const GENERAL_OPTIONS = ['omf_is_rest_api', 'omf_retention_days', 'omf_recaptcha_score', 'omf_recaptcha_field_name'];

  /** 秘密情報のオプション。サイトキーとシークレットは組で扱う。 */
  const SECRET_OPTION_PAIRS = [
    ['omf_recaptcha_site_key', 'omf_recaptcha_secret_key'],
    ['omf_turnstile_site_key', 'omf_turnstile_secret_key'],
    ['omf_google_client_id', 'omf_google_client_secret'],
  ];

  /**
   * 秘密情報のオプション名を平らな配列で返す。
   *
   * @return string[]
   */
  public static function secret_options(): array
  {
    return array_merge(...self::SECRET_OPTION_PAIRS);
  }

  /**
   * 取り込みを受け付けるオプション名すべて。
   *
   * @return string[]
   */
  public static function allowed_options(): array
  {
    return array_merge(self::GENERAL_OPTIONS, self::secret_options());
  }

  /**
   * フォームのメタ値のうち、秘密情報として扱うキー。
   *
   * @return string[]
   */
  public static function secret_meta_keys(): array
  {
    return [self::META_SLACK_WEBHOOK];
  }

  /**
   * ファイルの値を使わず、取り込み先で決めるメタキー。
   *
   * @return string[]
   */
  public static function managed_meta_keys(): array
  {
    return [self::META_MAIL_ID, self::META_CONDITION_ID];
  }
}
