<?php
require __DIR__ . '/port-helpers.php';
use Sharesl\Original\MailForm\OMF_Port_Assets;

// 書き出し画面のテンプレートと、アセットの読み込み条件
function wp_enqueue_style($handle, $src, ...$args){$GLOBALS['enqueued'][]=['style',$handle,$src];}
function wp_enqueue_script($handle, $src, ...$args){$GLOBALS['enqueued'][]=['script',$handle,$src];}
function plugins_url($path, $file){return 'https://example.test/wp-content/plugins/omf/'.$path;}

build_source();
add_test_post(11, 'original_mail_forms', 'second-form', '', 'draft');
$_GET = [];
$view = render_template('omf_port.php');
check(substr_count($view, 'name="scope"') === 3 && str_contains($view, 'value="all" checked') && str_contains($view, 'value="forms"') && str_contains($view, 'value="settings"'), '書き出す範囲を3つのラジオ（丸ごとすべて・フォームを選ぶ・共通設定だけ）で出す');
check(str_contains($view, '丸ごとすべて') && str_contains($view, 'フォームを選ぶ') && str_contains($view, '共通設定だけ') && str_contains($view, 'REST API の利用、送信データの保存期間、reCAPTCHA の判定スコア・項目名'), '各範囲の説明と、共通設定の中身を説明する');
check(str_contains($view, '全フォーム（2件）と共通設定を書き出します。') && str_contains($view, 'role="status"') && str_contains($view, 'aria-live="polite"'), 'ボタン上に要約を aria-live つきで出す');
check(substr_count($view, 'name="parts[]"') === 5 && substr_count($view, ' checked>') >= 5 && str_contains($view, 'フォーム情報') && str_contains($view, 'メール文面') && str_contains($view, 'その他の設定'), '項目は5つ、初期はすべてオン');
check(substr_count($view, 'name="form_ids[]"') === 2 && str_contains($view, 'contact-form') && str_contains($view, '（タイトルなし）') && str_contains($view, 'name="include_settings" value="1"> プラグイン共通の設定も含める'), 'フォーム一覧（タイトル＋スラッグ）と「共通設定も含める」（初期オフ）を出す');
check(!str_contains($view, 'include_secrets') && !str_contains($view, '秘密情報も含める') && str_contains($view, '秘密情報（reCAPTCHA') && str_contains($view, 'メールIDの連番'), '秘密情報の選択肢は無く、含まれないことを説明する');
check(str_contains($view, '<legend>1. 書き出す範囲</legend>') && str_contains($view, 'data-omf-port-bulk hidden') && !str_contains($view, 'data-omf-port-panel hidden'), 'fieldset/legend を使い、パネルの非表示は JS で付ける（HTML では全入力が見える）');
check(str_contains($view, '1. ファイルを選ぶ') && str_contains($view, '確認画面で') && str_contains($view, '取り込みを実行') && str_contains($view, '同じスラッグのフォームがある場合') && str_contains($view, 'パスで'), 'インポートの3手順と、上書き・パス照合の説明を出す');

$GLOBALS['enqueued'] = [];
$_GET = ['page' => 'omf_settings', 'tab' => 'general'];
OMF_Port_Assets::enqueue();
check($GLOBALS['enqueued'] === [], '他のタブではアセットを読み込まない');
$_GET = ['page' => 'omf_settings', 'tab' => 'port'];
OMF_Port_Assets::enqueue();
check(count($GLOBALS['enqueued']) === 2 && str_ends_with($GLOBALS['enqueued'][0][2], 'assets/port-admin.css') && str_ends_with($GLOBALS['enqueued'][1][2], 'assets/port-admin.js'), '設定の port タブだけで CSS と JS を読み込む');
check(is_file(dirname(__DIR__) . '/assets/port-admin.css') && is_file(dirname(__DIR__) . '/assets/port-admin.js'), 'アセットのファイルが存在する');
echo "書き出し画面のテストが完了\n";
