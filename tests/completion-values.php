<?php
// DB・サーバー・メール配送を起動しない、完了データ取得の回帰テスト。
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Page;
use Sharesl\Original\MailForm\OMF_Managed_Form;

function esc_url_raw($url) { return $url; }
function wp_safe_redirect($url, $status = 302) { throw new RuntimeException('redirect:' . $url); }
$GLOBALS['meta'][10]['cf_omf_condition_post'] = ['page'];
foreach (['entry'=>20, 'confirm'=>21, 'complete'=>22] as $step=>$id) {
  $GLOBALS['meta'][$id]['cf_omf_select'] = 'test';
  $GLOBALS['meta'][10]['cf_omf_screen_' . $step] = $step;
  $GLOBALS['pages'][$step] = $id;
}
$page = new OMF_Page();
call_private($page, 'update_session_names', 'test');
$data = ['lp'=>'missing-persons', 'message'=>'本文', 'choices'=>['A', 'B']];
$_SESSION = ['omf_test_data'=>$data, 'omf_test_auth'=>true, 'omf_test_sent'=>true, 'unrelated'=>'保持'];
foreach ([20, 21] as $id) {
  $GLOBALS['page_id'] = $id;
  check($page->get_completion_values() === [], '入力・確認では完了データを公開しない：' . $id);
}
$GLOBALS['page_id'] = 22;
call_private($page, 'complete_page_redirect', ['entry'=>'/entry/']);
check($page->get_completion_values() === $data, '旧方式の完了でhidden・本文・配列値を取得できる');
check($page->get_completion_values() === $data, '同じ完了応答では繰り返し取得できる');
check($page->get_post_values() === [], '旧方式の既存get_post_valuesは空のまま');
check($_SESSION === ['unrelated'=>'保持'], 'フォーム状態を削除し、無関係なセッションは維持する');

$next = new OMF_Page();
call_private($next, 'update_session_names', 'test');
check($next->get_completion_values() === [], '次のリクエストへ完了データを持ち越さない');
try {
  call_private($next, 'complete_page_redirect', ['entry'=>'/entry/']);
  check(false, '直接アクセスは入力へ戻す');
} catch (RuntimeException $error) {
  check($error->getMessage() === 'redirect:https://example.test/entry/', '再読み込み・直接アクセスの遷移を維持する');
}
$_SESSION = ['omf_test_data'=>$data, 'omf_test_auth'=>true];
call_private($next, 'complete_page_redirect', ['entry'=>'/entry/']);
check($next->get_completion_values() === [], '認証状態だけでは未送信データを公開しない');

// 確認省略時は通常の入力セッションがなくても、成功時専用のデータを取得する。
$skip = new OMF_Page();
call_private($skip, 'update_session_names', 'test');
$_SESSION = ['omf_test_completion_data'=>$data, 'omf_test_auth'=>true, 'omf_test_sent'=>true];
call_private($skip, 'complete_page_redirect', ['entry'=>'/entry/']);
check($skip->get_completion_values() === $data, '確認画面省略でも送信成功時のデータを取得できる');
check($_SESSION === [], '送信成功時専用の一時データも完了で削除する');
check($skip->get_post_values() === [], '確認省略でも従来APIに値を追加しない');

$GLOBALS['pages']['other'] = 11;
$GLOBALS['meta'][22]['cf_omf_select'] = 'other';
check($page->get_completion_values() === [], '別フォームへ退避値を公開しない');
$GLOBALS['meta'][22]['cf_omf_select'] = '';
check($page->get_completion_values() === [], 'フォーム非連携のページでは空配列');
$GLOBALS['meta'][22]['cf_omf_select'] = 'test';
$GLOBALS['meta'][10]['cf_omf_form_mode'] = 'builder';
$GLOBALS['meta'][10]['cf_omf_render_enabled'] = '1';
OMF_Managed_Form::complete_for_current_request(new WP_Post(), $data);
check($next->get_completion_values() === $data, '管理画面方式の既存の退避値も専用APIから取得できる');
check($next->get_post_values() === $data, '管理画面方式の既存get_post_valuesも変更しない');
check($_SESSION === [], '取得時にフォームのセッションを再作成しない');
echo "完了データ取得テスト完了\n";
