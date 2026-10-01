<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;

trait OMF_Trait_Send
{
  use OMF_Trait_Google_Sheets, OMF_Trait_Slack, OMF_Trait_Save_Db, OMF_Trait_Submission;

  /**
   * 同一リクエスト内でのアップロード処理の二重保存防止フラグ
   * @var boolean
   */
  private bool $uploaded_files_processed = false;


  /**
   * 自動返信メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @param array $attachments 添付ファイル
   * @return boolean|string
   */
  private function send_reply_mail(array $post_data, int $post_id, array $attachments = []): bool|string
  {

    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    //自動返信メール情報を取得
    $info           = $this->get_reply_mail_info($form->ID, $post_data);
    $form_title     = $info['form_title'];
    $mail_to        = $info['mail_to'];
    $mail_template  = $info['mail_template'];
    $mail_from      = $info['mail_from'];
    $from_name      = $info['from_name'];
    $tag_to_text    = $info['tag_to_text'];

    //送信前のフック
    do_action('omf_before_send_reply_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name, $attachments);

    $mail                = $this->create_reply_mail($info, []);
    $reply_mailaddress   = $mail['mailaddress'];

    //宛先がない場合は終了
    if (empty($reply_mailaddress)) {
      return false;
    }

    $reply_subject       = $mail['subject'];
    $reply_message       = $mail['message'];
    $reply_headers       = $mail['headers'];
    $attachments         = $mail['attachments'];

    //メール送信処理
    $is_sended_reply = OMF_Mail_Transport::send('reply', $form->ID,
      //宛先
      $reply_mailaddress,
      //件名
      $reply_subject,
      //内容
      $reply_message,
      //メールヘッダー
      $reply_headers,
      //添付ファイル
      $attachments
    );

    if ($is_sended_reply) {
      //送信後のフック
      do_action('omf_after_send_reply_mail', $tag_to_text, $reply_mailaddress, $reply_subject, $reply_message, $reply_headers, $attachments);
    }

    return $is_sended_reply;
  }

  /**
   * 通知メールの送信処理
   * @param array $post_data メールフォーム送信データ
   * @param int $post_id フォームを設置したページのID
   * @param array $attachments 添付ファイル
   * @return boolean
   */
  private function send_admin_mail(array $post_data, int $post_id, array $attachments = []): bool
  {
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return false;
    }

    //メール情報を取得
    $info          = $this->get_admin_mail_info($form->ID, $post_data);
    $form_title    = $info['form_title'];
    $mail_to       = $info['mail_to'];
    $mail_template = $info['mail_template'];
    $mail_from     = $info['mail_from'];
    $from_name     = $info['from_name'];
    $tag_to_text   = $info['tag_to_text'];

    //送信前のフック
    do_action('omf_before_send_admin_mail', $tag_to_text, $mail_to, $form_title, $mail_template, $mail_from, $from_name);

    $mail                = $this->create_admin_mail($info, $attachments);
    $admin_mailaddress   = $mail['mailaddress'];
    $admin_subject       = $mail['subject'];
    $admin_message       = $mail['message'];
    $admin_headers       = $mail['headers'];
    $attachments         = $mail['attachments'];

    //メール送信処理
    $is_sended_admin   = OMF_Mail_Transport::send('admin', $form->ID,
      //宛先
      $admin_mailaddress,
      //件名
      $admin_subject,
      //内容
      $admin_message,
      //メールヘッダー
      $admin_headers,
      //添付ファイル
      $attachments
    );

    //メール送信成功時
    if ($is_sended_admin) {
      //送信後のフック
      do_action('omf_after_send_admin_mail', $tag_to_text, $admin_mailaddress, $admin_subject, $admin_message, $admin_headers, $attachments);
    }

    //送信後に実行するオプション
    $this->after_send_admin($form, $info, $mail, $is_sended_admin);

    return $is_sended_admin;
  }

  /**
   * 自動返信メール作成用の基本情報をDBから取得
   *
   * @param integer $form_id
   * @param array $post_data
   * @return array
   */
  private function get_reply_mail_info(int $form_id, array $post_data): array
  {
    $form_title    = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_title', true));
    $mail_to       = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_to', true));
    $mail_template = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_mail', true), true);
    $mail_from     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_from', true));
    $mail_from     = is_email($mail_from) ? $mail_from : '';
    $from_name     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(["\r", "\n"], '', $from_name) : '';
    $mail_reply_to = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_reply_address', true));

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html(OMF_Utils::get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/'))
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    return [
      'form_title'    => $form_title,
      'mail_to'       => $mail_to,
      'mail_template' => $mail_template,
      'mail_from'     => $mail_from,
      'from_name'     => $from_name,
      'mail_reply_to' => $mail_reply_to,
      'display_tags'  => $this->mail_display_values($tag_to_text, $form_id),
      'tag_to_text'   => $tag_to_text
    ];
  }

  /**
   * 自動返信メールを作成
   *
   * @param array $info
   * @param array $attachments
   * @return array
   */
  private function create_reply_mail(array $info, array $attachments): array
  {

    if (empty($info['tag_to_text'])) {
      return [];
    }

    $tag_to_text = $info['tag_to_text'];

    //宛先（タグを含む場合は、置換後の値が単一の正しいメールアドレスの時だけ有効とする）
    $mailaddress = $this->resolve_mail_to_address($info['mail_to'], $tag_to_text);
    //件名
    $subject = str_replace(["\r", "\n"], '', $this->replace_form_mail_tags($info['form_title'], $tag_to_text));
    //メール本文のifタグを置換
    $mail_template = $this->replace_form_mail_if_tags($info['mail_template'], $tag_to_text);
    //メールタグを置換
    $message = $this->replace_form_mail_tags($mail_template, $info['display_tags'] ?? $tag_to_text);

    //フィルターを通す
    $message = apply_filters('omf_reply_mail', $message, $tag_to_text);

    //メールヘッダー
    $headers = [];
    if (!empty($info['from_name'] && !empty($info['mail_from']))) {
      $headers[]   = "From: {$info['from_name']} <{$info['mail_from']}>";

      $reply_to = is_email($info['mail_reply_to']) ? $info['mail_reply_to'] : $info['mail_from'];
      $headers[]   = "Reply-To: {$info['from_name']} <{$reply_to}>";

      $headers     = implode(PHP_EOL, $headers);
    }

    return [
      'mailaddress' => $mailaddress,
      'subject'     => $subject,
      'message'     => $message,
      'headers'     => $headers,
      'attachments' => [],
    ];
  }


  /**
   * 通知メール作成用の基本情報をDBから取得
   *
   * @param integer $form_id
   * @param array $post_data
   * @return array
   */
  private function get_admin_mail_info(int $form_id, array $post_data): array
  {
    $form_title    = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_title', true));
    $mail_to       = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_to', true));
    $mail_template = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_mail', true), true);
    $mail_from     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_from', true));
    $mail_from     = is_email($mail_from) ? $mail_from : '';
    $from_name     = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_admin_from_name', true));
    $from_name     = !empty($from_name) ? $from_name : get_bloginfo('name');
    $from_name     = !empty($from_name) ? str_replace(["\r", "\n"], '', $from_name) : '';

    //メールタグ
    $default_tags = [
      'send_datetime' => esc_html(OMF_Utils::get_current_datetime()),
      'site_name'     => esc_html(get_bloginfo('name')),
      'site_url'      => esc_url(home_url('/')),
      'user_agent'    => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
      'user_ip'       => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
      'host'          => ''
    ];
    $tag_to_text = array_merge($post_data, $default_tags);

    return [
      'form_title'    => $form_title,
      'mail_to'       => $mail_to,
      'mail_template' => $mail_template,
      'mail_from'     => $mail_from,
      'from_name'     => $from_name,
      'mail_reply_to' => $this->resolve_mail_to_address((string) get_post_meta($form_id, 'cf_omf_admin_reply_to', true), $tag_to_text),
      'display_tags'  => $this->mail_display_values($tag_to_text, $form_id),
      'tag_to_text'   => $tag_to_text
    ];
  }

  /**
   * 通知メールを作成
   *
   * @param array $info
   * @param array $attachments
   * @return array
   */
  private function create_admin_mail(array $info, array $attachments): array
  {
    if (empty($info['tag_to_text'])) {
      return [];
    }

    $tag_to_text = $info['tag_to_text'];

    //宛先（タグを含む場合は、置換後の値が単一の正しいメールアドレスの時だけ有効とする）
    $mailaddress = $this->resolve_mail_to_address($info['mail_to'], $tag_to_text);
    //件名
    $subject = str_replace(["\r", "\n"], '', $this->replace_form_mail_tags($info['form_title'], $tag_to_text));

    //メール本文のifタグを置換
    $mail_template = $this->replace_form_mail_if_tags($info['mail_template'], $tag_to_text);

    //メールタグを置換
    $message = $this->replace_form_mail_tags($mail_template, $info['display_tags'] ?? $tag_to_text);

    //フィルターを通す
    $message = apply_filters('omf_admin_mail', $message, $tag_to_text);

    //メールヘッダー
    $headers = [];
    if (!empty($info['from_name'] && !empty($info['mail_from']))) {
      $headers[]   = "From: {$info['from_name']} <{$info['mail_from']}>";
    }
    if (!empty($info['mail_reply_to']) && is_email($info['mail_reply_to'])) {
      $headers[] = 'Reply-To: ' . $info['mail_reply_to'];
    }

    return [
      'mailaddress' => $mailaddress,
      'subject'     => $subject,
      'message'     => $message,
      'headers'     => $headers,
      'attachments' => $attachments
    ];
  }

  /**
   * アップロードファイルを検証・保存し、送信データに反映する
   *
   * - フォーム設定でfile型（明示的な種別または拡張子設定を持つ）として定義された項目名だけを受け付ける
   * - 保存はnonceが正しいPOSTの時だけ行う。同一リクエスト内では一度だけ処理する
   * - POST内のファイル情報（attachment_id・name・typeなど）は信用せず、
   *   サーバー側で検証・保存した結果だけをセッションに保持して使う
   *
   * @param array $post_data
   * @param integer|string|null $post_id
   * @return array
   */
  private function process_uploaded_files(array $post_data, int|string|null $post_id = null): array
  {
    $form = $this->get_form($post_id);
    if (empty($form)) {
      return $post_data;
    }

    $file_targets = $this->get_file_field_targets($form->ID);
    if (empty($file_targets)) {
      return $post_data;
    }

    $session_key = OMF_Embed_Context::prefix($form->post_name) . '_uploaded_files';
    $post_data = $this->restore_uploaded_files($post_data, $post_id);

    //アップロードがない・同一リクエストで処理済みの場合はここで終了
    if (empty($_FILES) || $this->uploaded_files_processed) {
      return $post_data;
    }

    //nonceが正しいPOSTの時だけ保存する
    if (!$this->is_valid_nonce()) {
      return $post_data;
    }

    $this->uploaded_files_processed = true;

    foreach ($file_targets as $target) {
      if (empty($_FILES[$target]) || !is_array($_FILES[$target])) {
        continue;
      }

      $file = $_FILES[$target];
      if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        continue;
      }

      $result = $this->validate_and_save_uploaded_file($form->ID, $target, $file);
      if (!empty($result['error'])) {
        $this->extra_errors[$target][] = $result['error'];
        continue;
      }

      if (!empty($_SESSION[$session_key][$target]['upload_id'])) {
        OMF_Uploads::remove($_SESSION[$session_key][$target]['upload_id']);
      }
      $post_data[$target] = $result['data'];
      $_SESSION[$session_key][$target] = $result['data'];
    }

    return $post_data;
  }

  /**
   * アップロードされたファイルを検証し、問題がなければ非公開の一時領域に保存する
   *
   * @param integer $form_id
   * @param string $target
   * @param array $file $_FILESの1要素
   * @return array ['error' => string|null, 'data' => array|null]
   */
  private function validate_and_save_uploaded_file(int $form_id, string $target, array $file): array
  {
    if (
      !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK ||
      !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null) ||
      empty($file['tmp_name']) ||
      !is_uploaded_file($file['tmp_name'])
    ) {
      return ['error' => 'ファイルのアップロードに失敗しました', 'data' => null];
    }

    $name = (string)($file['name'] ?? '');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    //実行可能な拡張子・HTML・SVGは許可リストに関係なく常に拒否
    if (empty($ext) || in_array($ext, OMF_Config::BLOCKED_FILE_EXTENSIONS, true)) {
      return ['error' => 'このファイル形式は使用できません', 'data' => null];
    }

    //許可する拡張子（フォーム設定の指定があればそれを優先、なければ既定値）
    $allowed_extensions = $this->get_allowed_upload_extensions($form_id, $target);
    if (!in_array($ext, $allowed_extensions, true)) {
      $allowed_extensions_text = implode('、', $allowed_extensions);
      return ['error' => "拡張子が {$allowed_extensions_text} のファイルのみアップロードできます", 'data' => null];
    }

    //サイズ上限（フォーム設定の指定があればそれを優先、なければサーバーの上限値）
    $max_size = $this->get_max_upload_size($form_id, $target);
    if ((int)($file['size'] ?? 0) > $max_size) {
      return ['error' => 'ファイルサイズは' . size_format($max_size) . '以内にしてください', 'data' => null];
    }

    //実体と拡張子の整合性を確認（保存前に行う）
    $filetype     = wp_check_filetype_and_ext($file['tmp_name'], $name);
    $checked_ext  = !empty($filetype['ext']) ? strtolower($filetype['ext']) : '';
    $checked_type = !empty($filetype['type']) ? $filetype['type'] : '';

    if (empty($checked_ext) || empty($checked_type) || $checked_ext !== $ext) {
      return ['error' => 'ファイルの内容を確認できませんでした。別のファイルをお試しください', 'data' => null];
    }

    if (in_array($checked_ext, OMF_Config::BLOCKED_FILE_EXTENSIONS, true)) {
      return ['error' => 'このファイル形式は使用できません', 'data' => null];
    }

    try {
      $id = OMF_Uploads::save($file['tmp_name'], $name);
    } catch (\Throwable $e) {
      return ['error' => $e->getMessage(), 'data' => null];
    }
    return ['error' => null, 'data' => [
      'name' => sanitize_file_name($name), 'type' => $checked_type,
      'size' => (int) $file['size'], 'upload_id' => $id,
    ]];
  }

  /**
   * 許可する拡張子の一覧を取得する
   * フォーム設定に拡張子の指定があればそれを優先し、なければ既定値（`omf_allowed_file_types`フィルターで変更可）を使う
   *
   * @param integer $form_id
   * @param string $target
   * @return array
   */
  private function get_allowed_upload_extensions(int $form_id, string $target): array
  {
    $rule = $this->get_field_validation_rule($form_id, $target);
    if (!empty($rule['extension']) && is_array($rule['extension'])) {
      return array_map('strtolower', (array)$rule['extension']);
    }

    $defaults = apply_filters('omf_allowed_file_types', OMF_Config::DEFAULT_ALLOWED_FILE_EXTENSIONS);
    return array_map('strtolower', (array)$defaults);
  }

  /**
   * アップロードを許可するファイルサイズ上限（バイト）を取得する
   * フォーム設定に指定があればそれを優先し、なければサーバーの上限値を使う
   *
   * @param integer $form_id
   * @param string $target
   * @return integer
   */
  private function get_max_upload_size(int $form_id, string $target): int
  {
    $rule = $this->get_field_validation_rule($form_id, $target);
    if (!empty($rule['file_size'])) {
      return min((int) $rule['file_size'], (int) wp_max_upload_size(), 10 * MB_IN_BYTES);
    }

    return min((int) wp_max_upload_size(), 10 * MB_IN_BYTES);
  }

  /**
   * タグの中から添付ファイル一覧を作成・WPにファイルを保存
   *
   * @param array $tags
   * @return array
   */
  private function convert_attachments(array $tags): array
  {
    $paths = []; $ids = [];
    foreach ($tags as $key => $tag) {
      if (!is_array($tag) || empty($tag['upload_id'])) { continue; }
      $owned = false;
      foreach ($_SESSION as $session_key => $files) {
        if (str_ends_with((string) $session_key, '_uploaded_files') && is_array($files) && ($files[$key] ?? null) === $tag) { $owned = true; break; }
      }
      if (!$owned) { continue; }
      $path = OMF_Uploads::path($tag['upload_id']);
      if ($path === '') { continue; }
      $paths[] = $path;
      $ids[] = $tag['upload_id'];
      $tags[$key] = $tag['name'];
    }
    return ['attachment_paths' => $paths, 'attachment_ids' => $ids, 'tags' => $tags];
  }

  /**
   * メールタグのif文を置換
   * @param string $text
   * @param array $tags
   * @return string
   */
  private function replace_form_mail_if_tags(string $text, array $tags): string
  {
    $text = preg_replace_callback('/{if:([a-zA-Z_]+)}([\s\S]*?){\/if:\1}/', function ($matches) use ($tags) {
      $tag = $matches[1];
      $content = $matches[2];

      // タグが存在し、かつ値が空でない場合にのみコンテンツを表示
      if (isset($tags[$tag]) && $tags[$tag] !== '') {
        return $content;
      } else {
        return '';
      }
    }, $text);

    //3個以上の連続した改行コード・改行文字を2個に固定
    $text = preg_replace("/(\r\n){3,}|\r{3,}|\n{3,}/", "\n\n", $text);

    return $text;
  }

  /**
   * メールタグを置換
   * @param  string|null $text
   * @param  array $tag_to_text
   * @return string
   */
  /** 管理画面方式の同意は検証・条件分岐では1を使い、メール本文では表示値を使う。 */
  private function mail_display_values(array $tags, int $form_id): array
  {
    if (OMF_Field_Schema::mode($form_id) !== 'builder') { return $tags; }
    $schema = OMF_Field_Schema::read($form_id);
    if (is_wp_error($schema)) { return $tags; }
    $lines = [];
    foreach ($schema['fields'] as $field) {
      $display = OMF_Field_Renderer::display($field, $tags[$field['key']] ?? '');
      $lines[] = str_replace(["\r", "\n"], '', $field['label']) . '：' . ($display === '' ? '—' : $display);
      if ($field['type'] === 'acceptance' && isset($tags[$field['key']])) { $tags[$field['key']] = $tags[$field['key']] === '1' ? '同意する' : ''; }
    }
    $tags['form_data'] = implode("\n\n", $lines);
    return $tags;
  }

  private function replace_form_mail_tags(string|null $text, array $tag_to_text): string
  {
    return preg_replace_callback('/\{([^{}]+)\}/', static function ($match) use ($tag_to_text) {
      $value = $tag_to_text[$match[1]] ?? '';
      if (is_array($value)) {
        $value = array_is_list($value) ? implode('、', array_filter($value, 'is_string')) : '';
      }
      $value = apply_filters('omf_mail_tag', $value, $match[1]);
      return is_scalar($value) ? (string) $value : '';
    }, $text ?? '');
  }

  /**
   * 管理者が設定したカンマ区切りを先に分け、各宛先を単一アドレスとして検証する。
   * タグ値にカンマ・改行が含まれる場合は宛先全体を拒否する。
   *
   * @param string|null $mail_to_template
   * @param array $tag_to_text
   * @return string
   */
  private function resolve_mail_to_address(string|null $mail_to_template, array $tag_to_text): string
  {
    $addresses = [];
    foreach (explode(',', $mail_to_template ?? '') as $template) {
      $address = trim($this->replace_form_mail_tags(trim($template), $tag_to_text));
      if (!is_email($address) || preg_match('/[\r\n]/', $address)) { return ''; }
      $addresses[] = $address;
    }
    return implode(',', array_unique($addresses));
  }

  /**
   * 通知メール送信後に実行
   *
   * @param WP_Post $form
   * @param array $info
   * @param array $mail
   * @param boolean $is_sended_admin
   * @return void
   */
  private function after_send_admin(WP_Post $form, array $info, array $mail, bool $is_sended_admin)
  {
    //保存用の配列を生成
    $data_to_save = $this->create_save_data($info, $mail, $is_sended_admin);

    // DB保存
    $db_start = microtime(true);
    $this->save_data($form, $data_to_save);
    do_action('omf_operation_timing', 'database', (microtime(true) - $db_start) * 1000, $form->ID);

    // 通知メールが失敗した試行では外部連携を重複実行しない。
    if (!$is_sended_admin) { return; }

    //API送信データをまとめる
    $webhook_data = [];

    //Slack通知
    $webhook_data[] = $this->get_send_slack_params($form, $info, $mail, $is_sended_admin);

    //スプレッドシート書き込み
    $webhook_data[] = $this->get_google_sheets_params($form, $data_to_save);

    //一括でまとめて送信
    foreach ($webhook_data as $index => &$request) {
      if (is_array($request) && !empty($request['url'])) { $request['omf_operation'] = $index === 0 ? 'slack' : 'sheets'; $request['omf_form_id'] = $form->ID; }
    }
    unset($request);
    $responses = OMF_Utils::curl_multi_posts($webhook_data);
    if (!empty($this->delivery_receipt_id) && in_array(false, $responses, true)) { throw new \RuntimeException('外部連携の結果を確認できません。'); }
  }
}
