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
  const META_DATA_MEMO = 'cf_omf_data_memo';
  const META_SELECT = 'cf_omf_select';
  const META_SLACK_WEBHOOK = 'cf_omf_slack_webhook_url';
  const FORM_STATUSES = ['publish', 'draft', 'pending', 'private'];

  /** 常に書き出す一般オプション。 */
  const GENERAL_OPTIONS = ['omf_is_rest_api', 'omf_retention_days', 'omf_recaptcha_score', 'omf_recaptcha_field_name'];

  /** 秘密情報のオプション。書き出さず、取り込みでも受け付けない（サイトキーとシークレットの組）。 */
  const SECRET_OPTION_PAIRS = [
    ['omf_recaptcha_site_key', 'omf_recaptcha_secret_key'],
    ['omf_turnstile_site_key', 'omf_turnstile_secret_key'],
    ['omf_google_client_id', 'omf_google_client_secret'],
  ];

  /** 項目（parts）。書き出し・取り込みの単位。表示順もこの順。 */
  const PARTS = [
    'info' => ['label' => 'フォーム情報', 'description' => 'タイトル・公開状態・入力方式・項目の定義'],
    'validation' => ['label' => 'バリデーション', 'description' => 'コード方式の検証設定・フロント側の入力チェック'],
    'screen' => ['label' => '画面設定', 'description' => '入力・確認・完了画面、完了メッセージ、表示条件、連携ページ'],
    'mail' => ['label' => 'メール文面', 'description' => '通知メール・自動返信メールの宛先・件名・本文'],
    'other' => ['label' => 'その他の設定', 'description' => 'reCAPTCHA／Turnstile、DB保存、送信方法、Slack、スプレッドシート連携'],
  ];

  /** parts ごとのメタキー。other は、どれにも当てはまらない cf_omf_ メタすべて。 */
  const PART_META = [
    'info' => ['cf_omf_form_mode', 'cf_omf_field_schema', 'cf_omf_render_enabled'],
    'validation' => ['cf_omf_validation', 'cf_omf_front_validation'],
    'screen' => ['cf_omf_screen_entry', 'cf_omf_screen_confirm', 'cf_omf_screen_complete', 'cf_omf_skip_confirm', 'cf_omf_complete_message', 'cf_omf_condition_post', self::META_CONDITION_ID],
    'mail' => [
      'cf_omf_admin_to', 'cf_omf_admin_from', 'cf_omf_admin_from_name', 'cf_omf_admin_reply_to', 'cf_omf_admin_title', 'cf_omf_admin_mail',
      'cf_omf_reply_to', 'cf_omf_reply_from', 'cf_omf_reply_from_name', 'cf_omf_reply_address', 'cf_omf_reply_title', 'cf_omf_reply_mail',
      'cf_omf_disable_reply_mail',
    ],
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
   * 取り込みを受け付けるオプション名すべて（秘密情報は含まない）。
   *
   * @return string[]
   */
  public static function allowed_options(): array
  {
    return self::GENERAL_OPTIONS;
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

  /**
   * 書き出さず、取り込みでも常に取り込み先の値を残すメタキー（メモ・秘密情報）。
   *
   * @return string[]
   */
  public static function excluded_meta_keys(): array
  {
    return array_merge([self::META_DATA_MEMO], self::secret_meta_keys());
  }

  /**
   * 項目（parts）のキーを表示順で返す。
   *
   * @return string[]
   */
  public static function part_keys(): array
  {
    return array_keys(self::PARTS);
  }

  /**
   * 指定した項目のうち既知のものだけを、表示順で返す。
   *
   * @param array $parts
   * @return string[]
   */
  public static function normalize_parts(array $parts): array
  {
    return array_values(array_intersect(self::part_keys(), $parts));
  }

  /**
   * 項目の日本語名を「・」でつないで返す。
   *
   * @param string[] $parts
   * @return string
   */
  public static function part_labels(array $parts): string
  {
    return implode('・', array_map(static fn($part) => self::PARTS[$part]['label'], self::normalize_parts($parts)));
  }

  /**
   * メタキーが属する項目。cf_omf_ メタで定義に無いものは other。
   *
   * @param string $key
   * @return string
   */
  public static function part_of(string $key): string
  {
    foreach (self::PART_META as $part => $keys) {
      if (in_array($key, $keys, true)) {
        return $part;
      }
    }
    return 'other';
  }

  /**
   * 指定した項目の範囲で、書き出し・上書き・削除の対象になるメタキーか。
   * cf_omf_ 以外、取り込み先で決めるキー、メモ・秘密情報は対象外。
   *
   * @param string $key
   * @param string[] $parts
   * @return bool
   */
  public static function is_part_meta(string $key, array $parts): bool
  {
    return str_starts_with($key, self::META_PREFIX)
      && !in_array($key, self::managed_meta_keys(), true)
      && !in_array($key, self::excluded_meta_keys(), true)
      && in_array(self::part_of($key), $parts, true);
  }
}
