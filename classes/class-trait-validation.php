<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use ThrowsSpamAway;
use DateTime;
// use finfo;

trait OMF_Trait_Validation
{
  use OMF_Trait_Form, OMF_Trait_Captcha;

  /**
   * POSTの不正な値の検証エラーやファイルアップロードの検証エラーを一時的に保持する
   * @var array
   */
  protected array $extra_errors = [];

  /**
   * フォームデータの検証
   *
   * @param array $post_data
   * @param integer|null $post_id
   * @return array
   */
  public function validate_mail_form_data(array $post_data, int $post_id = 0): array
  {
    $errors = [];

    //連携しているメールフォームを取得
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return ['undefined' => ['メールフォームにエラーが起きました']];
    }

    //バリデーション設定を取得
    $validations = OMF_Field_Schema::rules($form->ID);
    if (is_wp_error($validations)) {
      return ['undefined' => ['フォームの項目設定が不正です。管理者にお問い合わせください。']];
    }
    if (!empty($validations)) {
      //バリデーション設定
      foreach ((array)$validations as $val) {
        $val = array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $val);
        $error_message = $this->validate($post_data, $val);
        if (!empty($error_message)) {
          $errors[$val['target']] = $error_message;
        }
      }
    }
    if (OMF_Field_Schema::mode($form->ID) === 'builder') {
      $schema = OMF_Field_Schema::read($form->ID);
      if (is_wp_error($schema)) { return ['undefined' => ['フォームの項目設定が不正です。管理者にお問い合わせください。']]; }
      foreach ($schema['fields'] as $field) {
        $condition = $field['required_if'];
        if ($condition === null || ($post_data[$condition['key']] ?? '') !== $condition['value']) { continue; }
        $message = $this->validate_required($post_data[$field['key']] ?? '', 1);
        if ($message !== '') { $errors[$field['key']][] = $message; }
      }
    }

    //不正な値の送信・ファイルアップロードの検証エラーをマージ
    foreach ($this->extra_errors as $target => $messages) {
      $errors[$target] = array_merge($errors[$target] ?? [], $messages);
    }
    $this->extra_errors = [];

    if ($errors === []) {
      $errors = $this->validate_captcha($post_data, $form);
    }

    // 独自検証で組み込みの検証・CAPTCHAエラーを消すことはできない。
    $custom = apply_filters('omf_validation_errors', [], $post_data, $form->ID, $post_id);
    if (is_array($custom)) {
      foreach ($custom as $key => $messages) {
        foreach ((array) $messages as $message) {
          if (is_string($message) && $message !== '') { $errors[$key][] = $message; }
        }
      }
    }
    return $errors;
  }

  /**
   * バリデーション設定を取得（custom_escape適用済み）
   *
   * @param integer $form_id フォーム（original_mail_forms）の投稿ID
   * @return array
   */
  private function get_validation_settings(int $form_id): array
  {
    $validations = OMF_Field_Schema::rules($form_id);
    if (is_wp_error($validations)) { return []; }
    if (empty($validations)) {
      return [];
    }

    $settings = [];
    foreach ((array)$validations as $val) {
      $settings[] = array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $val);
    }

    return $settings;
  }

  /**
   * フォーム設定でfile型として定義された項目名の一覧を取得
   *
   * バリデーション設定の管理画面は、すべての項目（テキスト項目を含む）に対して
   * 「添付ファイルサイズ上限」のセレクトを常に表示・保存するため、file_sizeの
   * 有無はfile型の判定に使えない（全項目に既定値が入ってしまう）。
   * そのため、拡張子（extension）が1つ以上指定されている項目だけをfile型とみなす。
   *
   * @param integer $form_id フォーム（original_mail_forms）の投稿ID
   * @return array
   */
  public function get_file_field_targets(int $form_id): array
  {
    $targets = [];
    foreach ($this->get_validation_settings($form_id) as $val) {
      if (empty($val['target'])) {
        continue;
      }
      if (($val['type'] ?? '') === 'file' || (!empty($val['extension']) && is_array($val['extension']))) {
        $targets[] = $val['target'];
      }
    }

    return array_unique($targets);
  }

  /**
   * 指定した項目名のバリデーション設定を取得
   *
   * @param integer $form_id フォーム（original_mail_forms）の投稿ID
   * @param string $target
   * @return array
   */
  public function get_field_validation_rule(int $form_id, string $target): array
  {
    foreach ($this->get_validation_settings($form_id) as $val) {
      if (!empty($val['target']) && $val['target'] === $target) {
        return $val;
      }
    }

    return [];
  }

  /**
   * バリデーション設定に定義されたすべての項目名（target）の一覧を取得
   *
   * @param integer $form_id フォーム（original_mail_forms）の投稿ID
   * @return array
   */
  private function get_validation_targets(int $form_id): array
  {
    $targets = [];
    foreach ($this->get_validation_settings($form_id) as $val) {
      if (!empty($val['target'])) {
        $targets[] = $val['target'];
      }
    }

    return array_unique($targets);
  }

  /**
   * 定義済み項目だけを受け付け、単一値と複数選択の型を検証する。
   * ファイル情報はPOSTから受け付けず、サーバー側の保管情報を使用する。
   *
   * @param array $posts
   * @param integer|string|null $post_id ページ（固定ページ・投稿）のID
   * @return array
   */
  protected function restrict_array_values(array $posts, int|string|null $post_id = null): array
  {
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return [];
    }
    $result = [];
    foreach ($this->get_validation_settings($form->ID) as $rule) {
      $key = $rule['target'] ?? '';
      if ($key === '' || !array_key_exists($key, $posts)) {
        continue;
      }
      // ファイル情報は後でサーバー側の保管情報から復元する。
      if (in_array($key, $this->get_file_field_targets($form->ID), true)) {
        continue;
      }
      $value = $posts[$key];
      $multiple = ($rule['type'] ?? '') === 'multiple';
      // 既存の複数選択は、メール等の単一値検証がない場合に限り維持する。
      $legacy_multiple = empty($rule['type']);
      foreach (['email', 'tel', 'url', 'numeric', 'alpha', 'alphanumeric', 'katakana', 'hiragana', 'kana', 'date', 'postal_code'] as $single) {
        if (!empty($rule[$single])) { $legacy_multiple = false; }
      }
      $valid_list = is_array($value) && array_is_list($value) && count($value) <= 100;
      if ($valid_list) {
        foreach ($value as $item) {
          $valid_list = $valid_list && is_string($item);
        }
      }
      if (($multiple && !$valid_list) || (!is_string($value) && !(($multiple || $legacy_multiple) && $valid_list))) {
        $this->extra_errors[$key][] = '不正な値が送信されました';
        continue;
      }
      $result[$key] = OMF_Utils::custom_escape($value);
    }
    return $result;
  }

  /**
   * reCAPTCHA設定の有無を判定
   *
   * @param integer|null $post_id
   * @return boolean
   */
  public function can_use_recaptcha(int|null $post_id = null): bool
  {
    //reCAPTCHAのキーを確認
    if (empty(get_option('omf_recaptcha_secret_key')) || empty(get_option('omf_recaptcha_site_key'))) {
      return false;
    }

    //reCAPTCHA設定を確認
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    $is_recaptcha = OMF_Utils::custom_escape(get_post_meta($form->ID, 'cf_omf_recaptcha', true));
    if (empty($is_recaptcha)) {
      return false;
    }

    return $is_recaptcha;
  }

  /**
   * reCAPTCHA認証処理
   * @return boolean
   */
  private function verify_google_recaptcha(): bool
  {
    $field = (string) get_option('omf_recaptcha_field_name', 'g-recaptcha-response');
    $result = $this->verify_captcha_response('https://www.google.com/recaptcha/api/siteverify', 'omf_recaptcha_secret_key', $field);
    $threshold = (float) get_option('omf_recaptcha_score', 0.5);
    return !empty($result['success']) && ($result['score'] ?? 0) >= $threshold;
  }

  /**
   * Cloudflare Turnstile設定の有無を判定
   *
   * @param integer|null $post_id
   * @return boolean
   */
  public function can_use_turnstile(int|null $post_id = null): bool
  {
    //reCAPTCHAのキーを確認
    if (empty(get_option('omf_turnstile_secret_key')) || empty(get_option('omf_turnstile_site_key'))) {
      return false;
    }

    //Cloudflare Turnstile設定を確認
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    $is_turnstile = OMF_Utils::custom_escape(get_post_meta($form->ID, 'cf_omf_turnstile', true));
    if (empty($is_turnstile)) {
      return false;
    }

    return $is_turnstile;
  }

  /**
   * Cloudflare Turnstile認証処理
   * @return boolean
   */
  private function verify_cloudflare_turnstile(): bool
  {
    $result = $this->verify_captcha_response('https://challenges.cloudflare.com/turnstile/v0/siteverify', 'omf_turnstile_secret_key', 'cf-turnstile-response');
    return !empty($result['success']);
  }

  /**
   * データを検証
   * @param  array $post_data 検証するデータ
   * @param  array $validation 検証条件
   * @return array エラー文
   */
  private function validate(array $post_data, array $validation): array
  {
    $errors = [];
    $target = $validation['target'] ?? '';
    $input = $post_data[$target] ?? '';
    if (is_array($input) && array_is_list($input)) {
      if ($input === []) {
        $error = $this->validate_required($input, $validation['required'] ?? 0);
        return $error === '' ? [] : [$error];
      }
      foreach ($input as $item) {
        $errors = array_merge($errors, $this->validate([$target => $item], $validation));
      }
      return array_values(array_unique($errors));
    }


    if (empty($validation)) {
      return $errors;
    }

    //検証するデータ
    $post_key = $validation['target'];
    $data = $post_data[$post_key] ?? '';

    // 管理画面方式はカンマ区切りに変換せず、選択値を完全一致で検証する。
    if (isset($validation['allowed_values']) && $data !== '' && !in_array($data, $validation['allowed_values'], true)) {
      $errors[] = '選択肢にない値が送信されました。';
    }

    if (isset($validation['builder_checks']) && is_string($data) && ($data !== '' || !empty($validation['required']))) {
      $errors = array_merge($errors, OMF_Field_Schema::validate_checks($data, $validation['builder_checks']));
    }
    $validators = [
      'min' => 'validate_min', 'max' => 'validate_max', 'required' => 'validate_required',
      'tel' => 'validate_tel', 'email' => 'validate_email', 'url' => 'validate_url',
      'numeric' => 'validate_numeric', 'alpha' => 'validate_alpha', 'alphanumeric' => 'validate_alpha_numeric',
      'katakana' => 'validate_katakana', 'hiragana' => 'validate_hiragana', 'kana' => 'validate_kana',
      'date' => 'validate_date', 'postal_code' => 'validate_postal_code', 'throws_spam_away' => 'validate_throws_spam_away',
      'matching_char' => 'validate_matching_char', 'file_size' => 'validate_file_size', 'extension' => 'validate_file_extension',
    ];
    foreach ($validators as $key => $method) {
      if (isset($validation['builder_checks']) && in_array($key, ['min', 'max', 'email', 'tel', 'url'], true)) { continue; }
      if (!array_key_exists($key, $validation)) { continue; }
      $value = $validation[$key];
      if ($key === 'extension' && !is_array($value)) { continue; }
      if ($key !== 'extension' && !is_string($value) && !is_int($value)) { continue; }
      if ($key === 'postal_code' && (int) $value !== 1) { continue; }
      $message = $this->$method($data, $value);
      if ($message !== '') { $errors[] = $message; }
    }
    return $errors;
  }

  /**
   * 最小文字数を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string エラーメッセージ
   */
  private function validate_min(mixed $data, int|string $value): string
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (!is_string($data)) {
      return $error;
    }

    if (mb_strlen($data, 'UTF-8') < intval($value)) {
      $error = "{$value}文字以上入力してください";
    }
    return $error;
  }

  /**
   * 最大文字数を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_max(mixed $data, int|string $value): string
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (!is_string($data)) {
      return $error;
    }

    if (mb_strlen($data, 'UTF-8') > intval($value)) {
      $error = "{$value}文字以内で入力してください";
    }
    return $error;
  }

  /**
   * 必須項目を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string|array $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_required(mixed $data, int|string|array $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || $data === null || $data === []) {
      $error = "必須項目です";
    }

    return $error;
  }

  /**
   * 電話番号を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_tel(mixed $data, int|string $value): string
  {
    if ((int) $value !== 1 || $data === '') { return ''; }
    if (!is_string($data)) { return '電話番号の形式で入力してください'; }
    $number = str_replace('-', '', mb_convert_kana($data, 'n', 'UTF-8'));
    return preg_match('/^0[0-9]{9,10}$/D', $number) ? '' : '電話番号の形式で入力してください';
  }

  /**
   * メールアドレスを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_email(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_email = !empty($data) ? filter_var($data, FILTER_VALIDATE_EMAIL) : false;
    if (!$is_email) {
      $error = "正しいメールアドレスを入力してください";
    }

    return $error;
  }

  /**
   * URLを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_url(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_url = !empty($data) ? filter_var($data, FILTER_VALIDATE_URL) : false;
    if (!$is_url) {
      $error = "URLの形式で入力してください";
    }

    return $error;
  }

  /**
   * 半角数字を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_numeric(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || $data === null || $data === []) {
      return $error;
    }

    $is_numeric = is_string($data) && is_numeric($data) ? preg_match('/^[0-9]+$/', $data) === 1 : false;
    if (!$is_numeric) {
      $error = "半角数字で入力してください";
    }

    return $error;
  }

  /**
   * 半角英字を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_alpha(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_alpha = !empty($data) ? preg_match('/^[a-zA-Z]+$/', $data) === 1 : false;
    if (!$is_alpha) {
      $error = "半角英字で入力してください";
    }

    return $error;
  }

  /**
   * 半角英数字を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_alpha_numeric(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_alpha_numeric = !empty($data) ? preg_match('/^[a-zA-Z0-9]+$/', $data) === 1 : false;
    if (!$is_alpha_numeric) {
      $error = "半角英数字で入力してください";
    }

    return $error;
  }

  /**
   * カタカナを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_katakana(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_katakana = !empty($data) ? preg_match('/^[ァ-ヶー\s　]+$/u', $data) === 1 : false;
    if (!$is_katakana) {
      $error = "全角カタカナで入力してください";
    }

    return $error;
  }

  /**
   * ひらがなを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_hiragana(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_hiragana = !empty($data) ? preg_match('/^[ぁ-んー\s　]+$/u', $data) === 1 : false;
    if (!$is_hiragana) {
      $error = "ひらがなで入力してください";
    }

    return $error;
  }


  /**
   * カタカナ or ひらがなを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_kana(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $is_kana = !empty($data) ? preg_match('/^[ァ-ヾぁ-んー]+$/u', $data) === 1 : false;
    if (!$is_kana) {
      $error = "全角カタカナもしくはひらがなで入力してください";
    }

    return $error;
  }

  /**
   * 日付を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_date(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    //エラーフラグ
    $is_error = true;

    $date_formats = [
      'Y#m#d',
      'Y#m#d（???）',
      'Y年m月d日',
      'Y年m月d日（???）',
      'Y#n#j',
      'Y#n#j（???）',
      'Y年n月j日',
      'Y年n月j日（???）'
    ];

    foreach ($date_formats as $format) {
      $dateTime = DateTime::createFromFormat($format, $data);
      if ($dateTime) {
        //条件と合致した時点で終了
        $is_error = false;
        break;
      }
    }

    if ($is_error) {
      $error = "日付の形式で入力してください";
    }

    return $error;
  }

  /**
   * 郵便番号（ハイフンあり／なし）の形式を検証する
   * @param  mixed $data 検証するデータ
   * @return string エラーメッセージ
   */
  private function validate_postal_code(mixed $data): string
  {
    $error = '';

    if (!is_string($data) || $data === '') {
      return $error;
    }

    // ハイフンあり（123-4567）または ハイフンなし（1234567）のどちらか
    if (!preg_match('/^\d{3}-\d{4}$/', $data) && !preg_match('/^\d{7}$/', $data)) {
      $error = '郵便番号は「123-4567」または「1234567」の形式で入力してください';
    }

    return $error;
  }


  /**
   * Throws SPAM Awayを検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_throws_spam_away(mixed $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if ($data === '' || !is_string($data)) {
      return $error;
    }

    $check_spam = $this->throws_spam_away($data);

    //スパム判定された場合
    if ($check_spam['valid'] === false) {
      $error = $check_spam['message'];
    }

    return $error;
  }


  /**
   * 一致する文字列を検証する
   * @param  mixed $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string エラーメッセージ
   */
  private function validate_matching_char(mixed $data, int|string $value): string
  {
    if ((string) $value === '' || $data === '') {
      return '';
    }
    $words = preg_split('/\s*,\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_string($data) && in_array($data, $words, true) ? '' : '選択肢にない値が送信されました。';
  }

  /**
   * Throws SPAM Awayプラグインの検証処理
   * @param  string $value 検証する文字列
   * @return array 検証結果
   */
  private function throws_spam_away(string $value): array
  {
    //ファイルの存在確認
    $filename = WP_PLUGIN_DIR . '/throws-spam-away/throws_spam_away.class.php';

    $result['valid'] = true;

    if (!file_exists($filename)) {
      return $result;
    }

    //ファイルが存在する場合は読み込み
    include_once($filename);

    //クラスが存在しない場合
    if (!class_exists('ThrowsSpamAway')) {
      return $result;
    }

    $throwsSpamAway = new ThrowsSpamAway();

    $args = [];
    $value = esc_attr($value);

    if (!empty($value)) {

      // IPアドレスチェック
      $ip = $_SERVER['REMOTE_ADDR'];
      // 許可リスト判定
      // $white_ip_check = !$throwsSpamAway->white_ip_check( $ip );

      // 拒否リスト判定
      $chk_ip = $throwsSpamAway->ip_check($ip);

      // 許可リストに入っていないまたは拒否リストに入っている場合はエラー
      // if ( ! $white_ip_check || ! $chk_ip ) {

      // 許可リストに入っていない場合はエラー
      //  if ( ! $white_ip_check ) {

      // 拒否リストに入っている場合はエラー
      if (!$chk_ip) {
        $result['valid']  = false;
        $result['message'] = '不明なエラーで送信できません';
        return $result;
      }

      // IPアドレスチェックを超えた場合は通常のスパムチェックが入ります。
      $chk_result = $throwsSpamAway->validate_comment("", $value, $args);

      // エラーがあればエラー文言返却
      if (!$chk_result) {
        // エラータイプを取得
        $error_type = $throwsSpamAway->error_type;
        $message_str = "";
        /**
         * エラー種類
         *'must_word'         必須キーワード
         *'ng_word'           NGキーワード
         *'url_count_over'    リンク数オーバー
         *'not_japanese'      日本語不足
         */
        switch ($error_type) {
          case "must_word":
            $message_str = "必須キーワードが入っていないため送信出来ません ";
            break;
          case "ng_word":
            $message_str = "NGキーワードが含まれているため送信できません ";
            break;
          case "url_count_over":
            $message_str = "リンクが多すぎます ";
            break;
          case "not_japanese":
            $message_str = "日本語が含まれないか日本語文字数が少ないため送信出来ません ";
            break;
          default:
            $message_str = "エラーが発生しました:" . $error_type;
        }
        $result['valid'] = false;
        $result['message'] = $message_str;
        return $result;
      }
    }

    return $result;
  }

  /**
   * 添付ファイルサイズを検証
   * @param  mixed $file  検証するデータ
   * @param  integer|string $size ファイルサイズ（バイト）
   * @return string エラーメッセージ
   */
  private function validate_file_size(mixed $file, int|string $size): string
  {
    if (!is_array($file) || !isset($file['size']) || (int) $size <= 0) { return ''; }
    return (int) $file['size'] > (int) $size ? 'ファイルサイズは' . size_format((int) $size) . '以内にしてください。' : '';
  }

  private function validate_file_extension(mixed $file, array $extensions): string
  {
    if (!is_array($file) || empty($file['upload_id']) || $extensions === []) { return ''; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    return in_array($ext, array_map('strtolower', $extensions), true) ? '' : '許可されていないファイル形式です。';
  }
}
