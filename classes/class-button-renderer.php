<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 画面に必要な操作だけを共通HTMLで生成する。外枠と装飾はテーマで決める。 */
class OMF_Button_Renderer
{
  public static function html(array $context, array $labels = []): string
  {
    $defaults = ['confirm' => '入力内容を確認', 'send' => '送信する', 'back' => '入力内容を修正', 'home' => 'トップページへ戻る'];
    $actions = match ($context['step']) {
      'entry' => [$context['confirm'] ? 'confirm' : 'send'],
      'confirm' => ['back', 'send'],
      'complete' => ['home'],
      default => [],
    };
    $html = '';
    foreach ($actions as $action) {
      $label = isset($labels[$action]) && is_string($labels[$action]) ? $labels[$action] : $defaults[$action];
      $class = 'omf-managed-button omf-managed-button--' . $action;
      if ($action === 'home') {
        $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(home_url('/')) . '" data-omf-role="action" data-omf-action="home">' . esc_html($label) . '</a>';
        continue;
      }
      if ($action !== 'back') { $class .= ' omf-managed-primary'; }
      $html .= '<button type="submit" class="' . esc_attr($class) . '" name="' . ($action === 'back' ? 'submit_back' : $action) . '" value="' . esc_attr($action) . '"' . ($action === 'back' ? ' formnovalidate' : '') . ' data-omf-role="action" data-omf-action="' . esc_attr($action) . '">' . esc_html($label) . '</button>';
    }
    return $html;
  }
}
