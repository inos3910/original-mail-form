<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use WP_Post;


trait OMF_Trait_Form
{
  /**
   * ページと連携しているメールフォームのスラッグを取得
   * @param  int|string|null $post_id $post_id ページID
   * @return string スラッグ
   */
  public function get_form_slug(int|string|null $post_id = null): string
  {
    $current_page_id = !empty($post_id) ? $post_id : get_the_ID();
    if (empty($current_page_id)) {
      return '';
    }

    $form_slug = OMF_Utils::custom_escape(get_post_meta($current_page_id, 'cf_omf_select', true));
    if (empty($form_slug)) {
      return '';
    }

    return $form_slug;
  }

  /**
   * ページと連携しているメールフォームのページオブジェクトを取得
   *
   * @param integer|string|null $post_id
   * @return WP_Post|array
   */
  public function get_form(int|string|null $post_id = null): WP_Post|array
  {
    $form_slug = $this->get_form_slug($post_id);
    if (empty($form_slug)) {
      return [];
    }

    $form = get_page_by_path($form_slug, OBJECT, OMF_Config::NAME);
    if (empty($form)) {
      return [];
    }

    return $form;
  }

  /**
   * フォームを設置するページのパスを取得
   *
   * @param integer|string|null $form_id
   * @return array
   */
  public function get_form_page_paths(int|string|null $form_id): array
  {
    if (empty($form_id)) {
      return [];
    }

    return [
      'entry'    => OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_screen_entry', true)),
      'confirm'  => OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_screen_confirm', true)),
      'complete' => OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_screen_complete', true))
    ];
  }

  /**
   * フォームを設置するページのIDを取得
   * @param  WP_Post|null $form メールフォームページのオブジェクト
   * @return array
   */
  public function get_form_page_ids(WP_Post|null $form = null): array
  {
    $ids = [];

    $form = !empty($form) ? $form : $this->get_form();
    if (empty($form)) {
      return $ids;
    }

    //画面設定からパスを取得
    $page_paths = $this->get_form_page_paths($form->ID);
    if (empty($page_paths)) {
      return $ids;
    }

    foreach ((array)$page_paths as $key => $value) {
      $ids[$key] = url_to_postid($value);
    }

    return $ids;
  }

  /**
   * 固定ページIDからそのページで有効なフォームの各画面のページ情報を取得
   *
   * @param integer|string|null $page_id
   * @return array
   */
  public function get_form_set_pages(int|string|null $page_id = null): array
  {
    $form = $this->get_form($page_id);
    if (empty($form)) {
      return [];
    }

    $pages = $this->get_active_form_pages($form->ID);
    return $pages;
  }

  /**
   * フォームが有効なページを取得
   *
   * @param integer|string|null $form_id
   * @param array $page_paths
   * @return array
   */
  public function get_active_form_pages(int|string|null $form_id, array $page_paths = []): array
  {
    $pages = [];
    if (empty($form_id)) {
      return $pages;
    }

    $page_paths = !empty($page_paths) ? $page_paths : $this->get_form_page_paths($form_id);
    $conditions =  get_post_meta($form_id, 'cf_omf_condition_post', true);
    if (empty($conditions) || empty($page_paths)) {
      return $pages;
    }

    foreach ((array)$conditions as $cond) {
      $cond = OMF_Utils::custom_escape($cond);
      foreach ((array)$page_paths as $key => $path) {
        if (!empty($pages[$key])) {
          continue;
        }
        $pages[$key] = !empty($path) ? get_page_by_path($path, OBJECT, $cond) : null;
      }
    }
    return $pages;
  }

  /**
   * 現在のページを引数の画面設定パスから判定
   *
   * @param string $page_slug
   * @return bool
   */
  public function is_page(string $page_slug): bool
  {
    $current_page_id = get_the_ID();
    $pages = $this->get_form_set_pages($current_page_id);
    if (empty($pages)) {
      return false;
    }

    return $current_page_id === $pages[$page_slug]->ID;
  }

  /**
   * メールIDを取得
   * @param  int|string|null $form_id
   * @return int
   */
  public function get_mail_id(int|string|null $form_id): int
  {
    $mail_id = OMF_Utils::custom_escape(get_post_meta($form_id, 'cf_omf_mail_id', true));
    if (empty($mail_id)) {
      $mail_id = 1;
    } else {
      ++$mail_id;
    }

    return $mail_id;
  }

  /**
   * メールIDを更新
   *
   * @param integer|string $form_id
   * @param integer $mail_id
   * @return boolean
   */
  public function update_mail_id(int|string $form_id, int $mail_id): bool
  {
    return update_post_meta($form_id, 'cf_omf_mail_id', $mail_id);
  }

  /**
   * IDから送信データの投稿タイプを取得
   *
   *
   * @param integer|string|null $form_id
   * @return string
   */
  public function get_data_post_type_by_id(int|string|null $form_id): string
  {
    if (empty($form_id)) {
      return '';
    }

    if (!preg_match('/^\d+$/', $form_id)) {
      return '';
    }

    $data_post_type = OMF_Config::DBDATA . $form_id;
    return $data_post_type;
  }

  /**
   * 自動返信の無効化の有無
   *
   * @param integer $form_id
   * @return boolean
   */
  public function is_disable_reply_mail(int $form_id): bool
  {
    return get_post_meta($form_id, 'cf_omf_disable_reply_mail', true) === '1';
  }
}
