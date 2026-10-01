<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** ページ連携と埋め込みの画面遷移を処理。メール・CAPTCHA・添付検証は既存処理を共用する。 */
trait OMF_Trait_Managed_Flow
{
  private array $managed_routes = [];

  private function managed_redirect(\WP_Post $form): void
  {
    $routes = OMF_Form_Routes::resolve($form);
    $this->managed_routes = $routes['pages'];
    $route = array_search((int) get_queried_object_id(), $this->managed_routes, true);
    if ($routes['errors']) {
      $this->clear_current_form_sessions();
      return;
    }
    if ($route === false) {
      $this->clear_current_form_sessions();
      wp_safe_redirect(get_permalink($this->managed_routes['entry']), 303);
      exit;
    }
    $state_key = $this->session_name_prefix . '_managed_step';
    $once = $this->session_name_prefix . '_managed_entry_once';
    $skip = get_post_meta($form->ID, 'cf_omf_skip_confirm', true) === '1';
    $authorized = $this->has_valid_session() && !empty($_SESSION[$this->session_name_post_data]) && !empty($_SESSION[OMF_Embed_Context::token_key()]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      if ($route === 'entry') {
        if (!empty($_SESSION[$once])) { unset($_SESSION[$once]); }
        else { $this->clear_current_form_sessions(); }
        $_SESSION[$this->session_name_auth] = false;
        return;
      }
      if ($route === 'confirm' && !$skip && $authorized && ($_SESSION[$state_key] ?? '') === 'confirm') { return; }
      if ($route === 'complete' && $this->has_valid_session() && $this->has_sent_session() && ($_SESSION[$state_key] ?? '') === 'complete' && !empty($_SESSION[$this->session_name_prefix . '_managed_complete_once'])) {
        // 描画・公開APIが使う値を退避し、保存済み状態を消してから応答を生成する。
        OMF_Managed_Form::complete_for_current_request($form, $this->get_post_data_session());
        $this->clear_current_form_sessions();
        session_write_close();
        return;
      }
      $this->clear_current_form_sessions();
      $this->managed_reload('entry');
    }
    $actions = array_keys(array_filter(['back' => $this->is_back_button_clicked(), 'confirm' => $this->is_confirm_button_clicked(), 'send' => $this->is_mail_send_request()]));
    $action = count($actions) === 1 ? $actions[0] : '';
    $consumed = $_SESSION[$this->session_name_prefix . '_managed_consumed_token'] ?? '';
    $posted_token = $_POST['omf_token'] ?? '';
    if ($action === 'send' && in_array($route, ['entry', 'confirm'], true) && $this->has_sent_session() && $consumed !== '' && is_string($posted_token) && hash_equals($consumed, hash('sha256', $posted_token)) && $this->is_valid_nonce()) {
      $this->managed_reload('complete');
    }
    $valid_action = ($route === 'entry' && $action === ($skip ? 'send' : 'confirm')) || ($route === 'confirm' && !$skip && $authorized && ($_SESSION[$state_key] ?? '') === 'confirm' && in_array($action, ['back', 'send'], true));
    if (!$valid_action || !$this->is_valid_nonce() || !$this->is_valid_token()) {
      $this->clear_current_form_sessions();
      $this->managed_reload('entry');
    }
    if ($action === 'back') {
      $_SESSION[$this->session_name_auth] = false;
      $this->managed_reload('entry', true);
    }
    if ($route === 'entry') { $this->managed_input($form); }
    else { $_FILES = []; } // 確認後は本文・添付をPOSTから差し替えない。
    $data = $route === 'confirm' ? $this->restore_uploaded_files($this->get_post_data_session()) : $this->get_post_values();
    $_SESSION[$this->session_name_prefix . '_managed_edited'] = true;
    $errors = $this->validate_submission($data);
    $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($data);
    if ($errors) {
      $_SESSION[$this->session_name_error] = $errors;
      $_SESSION[$this->session_name_auth] = false;
      $this->managed_reload('entry', true);
    }
    $_SESSION[$this->session_name_auth] = true;
    if ($action === 'confirm') { $this->managed_reload('confirm'); }
    $data['mail_id'] = $this->get_mail_id($form->ID);
    $post_id = get_queried_object_id();
    do_action('omf_before_send_mail', $data, $form, $post_id);
    if ($this->send_mails($data, $form->ID, $post_id)) {
      $_SESSION[$this->session_name_prefix . '_managed_consumed_token'] = hash('sha256', $_SESSION[OMF_Embed_Context::token_key()]);
      $this->after_send_mails($form, $data, $post_id);
      $_SESSION[$this->session_name_post_data] = $this->filter_post_keys($data);
      $_SESSION[$this->session_name_prefix . '_managed_complete_once'] = true;
      $this->managed_reload('complete');
    }
    $_SESSION[$this->session_name_error] = ['send' => ['送信できませんでした。時間をおいてもう一度お試しください。']];
    $_SESSION[$this->session_name_auth] = false;
    $this->managed_reload('entry', true);
  }

  /** WordPressの公開クエリ変数と衝突しないPOST形式を共通処理へ渡す。 */
  private function managed_input(\WP_Post $form): void
  {
    $input = isset($_POST['omf_fields']) && is_array($_POST['omf_fields']) ? $_POST['omf_fields'] : [];
    unset($_POST['omf_fields']);
    $schema = OMF_Field_Schema::read($form->ID);
    if (is_wp_error($schema)) { return; }
    $allowed = array_column($schema['fields'], 'key');
    foreach ($allowed as $key) { unset($_POST[$key]); }
    $_POST = array_merge($_POST, array_intersect_key($input, array_flip($allowed)));
    $uploads = $_FILES['omf_fields'] ?? [];
    $_FILES = [];
    foreach ($schema['fields'] as $field) {
      $key = $field['key'];
      if ($field['type'] !== 'file' || !isset($uploads['error'][$key])) { continue; }
      foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) { $_FILES[$key][$part] = $uploads[$part][$key] ?? null; }
    }
  }

  private function managed_reload(string $step, bool $retain = false): void
  {
    $_SESSION[$this->session_name_prefix . '_managed_step'] = $step;
    if ($retain) { $_SESSION[$this->session_name_prefix . '_managed_entry_once'] = true; }
    session_write_close();
    wp_safe_redirect(get_permalink($this->managed_routes[$step]), 303);
    exit;
  }
}
