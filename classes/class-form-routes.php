<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 全設置方式で共通の専用ページと設置先の整合を確認する。 */
class OMF_Form_Routes
{
  public static function resolve(\WP_Post $form): array
  {
    $page = $GLOBALS['global_omf']->get_instance('page');
    $paths = $page->get_form_page_paths($form->ID);
    $pages = $page->get_active_form_pages($form->ID);
    $required = get_post_meta($form->ID, 'cf_omf_skip_confirm', true) === '1' ? ['entry', 'complete'] : ['entry', 'confirm', 'complete'];
    $labels = ['entry' => '入力', 'confirm' => '確認', 'complete' => '完了'];
    $ids = []; $errors = [];
    foreach ($labels as $step => $label) {
      if (empty($paths[$step]) && !in_array($step, $required, true)) { continue; }
      $target = $pages[$step] ?? null;
      if (!$target || $target->post_status !== 'publish') {
        $errors[] = $label . 'ページに公開済みのページを設定してください。';
        continue;
      }
      $ids[$step] = (int) $target->ID;
      $linked = $page->get_form($target->ID);
      if (!$linked || (int) $linked->ID !== (int) $form->ID) {
        $errors[] = $label . 'ページに同じフォームを指定してください。';
      }
    }
    if (count($ids) !== count(array_unique($ids))) { $errors[] = '入力・確認・完了には別々のページを設定してください。'; }
    return ['pages' => $ids, 'errors' => $errors];
  }

  public static function step(\WP_Post $form): string
  {
    $pages = $GLOBALS['global_omf']->get_instance('page')->get_active_form_pages($form->ID);
    foreach ($pages as $step => $page) {
      if ($page && (int) $page->ID === (int) get_queried_object_id()) { return $step; }
    }
    return 'entry';
  }
}
