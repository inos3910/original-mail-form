<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use ThrowsSpamAway;
use DateTime;

trait OMF_Trait_Validation
{
  use OMF_Trait_Form;

  /**
   * フォームデータの検証
   *
   * @param array $post_data
   * @param integer|null $post_id
   * @return array
   */
  public function validate_mail_form_data(array $post_data, int $post_id = null): array
  {
    $errors = [];

    //連携しているメールフォームを取得
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return $errors['undefined'] = ['メールフォームにエラーが起きました'];
    }

    //バリデーション設定を取得
    $validations = get_post_meta($form->ID, 'cf_omf_validation', true);
    if (empty($validations)) {
      return $errors;
    }

    //バリデーション設定
    foreach ((array)$validations as $valid) {
      $valid = array_map([__NAMESPACE__ . '\OMF_Utils', 'custom_escape'], $valid);
      $error_message = $this->validate($post_data, $valid);
      if (!empty($error_message)) {
        $errors[$valid['target']] = $error_message;
      }
    }

    //reCAPTCHA
    $is_recaptcha = $this->can_use_recaptcha($post_id);
    if ($is_recaptcha) {
      $recaptcha = $this->verify_google_recaptcha();
      if (!$recaptcha) {
        $errors['recaptcha'] = ['フォーム認証エラーのためもう一度送信してください。'];
      }
    }

    return $errors;
  }

  /**
   * reCAPTCHA設定の有無を判定
   *
   * @param integer|null $post_id
   * @return boolean
   */
  public function can_use_recaptcha(int $post_id = null): bool
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

    //入力画面のみ
    $current_page_id = get_the_ID();
    $page_ids        = $this->get_form_page_ids($form);
    if ($page_ids['entry'] !== $current_page_id) {
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
    $recaptcha_secret = !empty(get_option('omf_recaptcha_secret_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_secret_key'))) : '';
    $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
    $recaptcha_response = !empty(filter_input(INPUT_POST, $recaptcha_field_name)) ? sanitize_text_field(wp_unslash(filter_input(INPUT_POST, $recaptcha_field_name))) : '';

    if (empty($recaptcha_secret) || empty($recaptcha_response)) {
      return false;
    }

    // APIリクエスト
    $recaptch_url = 'https://www.google.com/recaptcha/api/siteverify';
    $recaptcha_params = [
      'secret' => $recaptcha_secret,
      'response' => $recaptcha_response,
    ];
    $request_params_query =  http_build_query($recaptcha_params);
    $endpoint = "{$recaptch_url}?{$request_params_query}";

    $verify_response = OMF_Utils::curl_get($endpoint);
    //curl取得エラーの場合
    if (empty($verify_response) || is_wp_error($verify_response)) {
      return false;
    }

    // APIレスポンス確認
    $response_data = json_decode($verify_response);

    return !empty($response_data) && $response_data->success && $response_data->score >= 0.5;
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

    if (empty($validation)) {
      return $errors;
    }

    //POSTにキーがない場合は未送信なのでスキップ
    $post_key = $validation['target'];
    if (!isset($post_data[$post_key])) {
      return $errors;
    }

    //検証するデータ
    $data = !empty($post_data[$post_key]) ? $post_data[$post_key] : '';

    foreach ((array)$validation as $key => $value) {
      //最小文字数
      if ($key === 'min') {
        $error_message = $this->validate_min($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //最大文字数
      elseif ($key === 'max') {
        $error_message = $this->validate_max($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //必須
      elseif ($key === 'required') {
        $error_message = $this->validate_required($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //電話番号
      elseif ($key === 'tel') {
        $error_message = $this->validate_tel($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //メールアドレス
      elseif ($key === 'email') {
        $error_message = $this->validate_email($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //URL
      elseif ($key === 'url') {
        $error_message = $this->validate_url($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角数字
      elseif ($key === 'numeric') {
        $error_message = $this->validate_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英字
      elseif ($key === 'alpha') {
        $error_message = $this->validate_alpha($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英数字
      elseif ($key === 'alphanumeric') {
        $error_message = $this->validate_alpha_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ
      elseif ($key === 'katakana') {
        $error_message = $this->validate_katakana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ひらがな
      elseif ($key === 'hiragana') {
        $error_message = $this->validate_hiragana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ or ひらがな
      elseif ($key === 'kana') {
        $error_message = $this->validate_kana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //日付
      elseif ($key === 'date') {
        $error_message = $this->validate_date($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ThrowsSpamAway
      elseif ($key === 'throws_spam_away') {
        $error_message = $this->validate_throws_spam_away($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //一致する文字
      elseif ($key === 'matching_char') {
        $error_message = $this->validate_matching_char($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      } else {
        continue;
      }
    }
    return $errors;
  }

  /**
   * 最小文字数を検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string エラーメッセージ
   */
  private function validate_min(string $data, int|string $value): string
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (mb_strlen($data) <= intval($value)) {
      $error = "{$value}文字以上入力してください";
    }
    return $error;
  }

  /**
   * 最大文字数を検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_max(string $data, int|string $value): string
  {
    $error = '';
    if (intval($value) === 0) {
      return $error;
    }

    if (mb_strlen($data) >= intval($value)) {
      $error = "{$value}文字以内で入力してください";
    }
    return $error;
  }

  /**
   * 必須項目を検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_required(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    if (empty($data)) {
      $error = "必須項目です";
    }

    return $error;
  }

  /**
   * 電話番号を検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_tel(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_tel = !empty($data) ? filter_var($data, FILTER_VALIDATE_REGEXP, [
      'options' => [
        'regexp' => '/\A(((0(\d{1}[-(]?\d{4}|\d{2}[-(]?\d{3}|\d{3}[-(]?\d{2}|\d{4}[-(]?\d{1}|[5789]0[-(]?\d{4})[-)]?)|\d{1,4}\-?)\d{4}|0120[-(]?\d{3}[-)]?\d{3})\z/'
      ]
    ]) : false;

    if (!$is_tel) {
      $error = "電話番号の形式で入力してください";
    }

    return $error;
  }

  /**
   * メールアドレスを検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_email(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_url(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_numeric(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_numeric = !empty($data) ? preg_match('/^[0-9]+$/', $data) === 1 : false;
    if (!$is_numeric) {
      $error = "半角数字で入力してください";
    }

    return $error;
  }

  /**
   * 半角英字を検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_alpha(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_alpha_numeric(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_katakana(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_katakana = !empty($data) ? preg_match('/^[ァ-ヶー]+$/u', $data) === 1 : false;
    if (!$is_katakana) {
      $error = "全角カタカナで入力してください";
    }

    return $error;
  }

  /**
   * ひらがなを検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_hiragana(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $is_hiragana = !empty($data) ? preg_match('/^[ぁ-んー]+$/u', $data) === 1 : false;
    if (!$is_hiragana) {
      $error = "ひらがなで入力してください";
    }

    return $error;
  }


  /**
   * カタカナ or ひらがなを検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_kana(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_date(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * Throws SPAM Awayを検証する
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_throws_spam_away(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
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
   * @param  string $data  検証するデータ
   * @param  integer|string $value 検証条件
   * @return string        エラーメッセージ
   */
  private function validate_matching_char(string $data, int|string $value): string
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    //一致させる文字列を配列で取得
    $words = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!empty($words) && !in_array($data, $words, true)) {
      $error = '不正な値が送信されました。';
    }

    return $error;
  }


  /**
   * Throws SPAM Awayプラグインの検証処理を追加
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
}
