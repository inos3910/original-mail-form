<?php
class OMF_Validation
{
  /**
   * データを検証
   * @param  Array $post_data 検証するデータ
   * @param  String $validation 検証条件
   * @return array エラー文
   */
  public static function validate($post_data, $validation)
  {
    if (empty($validation)) {
      return;
    }

    $post_key = $validation['target'];
    $data     = !empty($post_data[$post_key]) ? $post_data[$post_key] : '';

    $errors = [];

    foreach ((array)$validation as $key => $value) {
      //最小文字数
      if ($key === 'min') {
        $error_message = self::validate_min($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //最大文字数
      elseif ($key === 'max') {
        $error_message = self::validate_max($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //必須
      elseif ($key === 'required') {
        $error_message = self::validate_required($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //電話番号
      elseif ($key === 'tel') {
        $error_message = self::validate_tel($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //メールアドレス
      elseif ($key === 'email') {
        $error_message = self::validate_email($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //URL
      elseif ($key === 'url') {
        $error_message = self::validate_url($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角数字
      elseif ($key === 'numeric') {
        $error_message = self::validate_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英字
      elseif ($key === 'alpha') {
        $error_message = self::validate_alpha($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //半角英数字
      elseif ($key === 'alphanumeric') {
        $error_message = self::validate_alpha_numeric($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ
      elseif ($key === 'katakana') {
        $error_message = self::validate_katakana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ひらがな
      elseif ($key === 'hiragana') {
        $error_message = self::validate_hiragana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //カタカナ or ひらがな
      elseif ($key === 'kana') {
        $error_message = self::validate_kana($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //日付
      elseif ($key === 'date') {
        $error_message = self::validate_date($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //ThrowsSpamAway
      elseif ($key === 'throws_spam_away') {
        $error_message = self::validate_throws_spam_away($data, $value);
        if (!empty($error_message)) {
          $errors[] = $error_message;
        }
      }
      //一致する文字
      elseif ($key === 'matching_char') {
        $error_message = self::validate_matching_char($data, $value);
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_min($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_max($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_required($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_tel($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_email($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_url($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_numeric($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_alpha($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_alpha_numeric($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_katakana($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_hiragana($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_kana($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_date($data, $value)
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
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_throws_spam_away($data, $value)
  {
    $error = '';

    //検証フラグがOFFの時はスキップ
    if (intval($value) !== 1) {
      return $error;
    }

    $check_spam = self::throws_spam_away($data);

    //スパム判定された場合
    if ($check_spam['valid'] === false) {
      $error = $check_spam['message'];
    }

    return $error;
  }


  /**
   * 一致する文字列を検証する
   * @param  String $data  検証するデータ
   * @param  String $value 検証条件
   * @return String        エラーメッセージ
   */
  public static function validate_matching_char($data, $value)
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
  public static function throws_spam_away($value)
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
