<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Button_Renderer as Buttons;
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
$context = ['step' => 'entry', 'confirm' => true];
$html = Buttons::html($context, ['confirm' => '内容を確認する', 'send' => '申し込む']);
check(substr_count($html, '<button') === 1 && str_contains($html, 'name="confirm" value="confirm"') && str_contains($html, '>内容を確認する</button>') && str_contains($html, 'omf-managed-button--confirm'), '入力は確認ボタンだけを共通クラスと指定文言で生成');
$context['confirm'] = false;
$html = Buttons::html($context, ['confirm' => '内容を確認する', 'send' => '申し込む']);
check(str_contains($html, 'name="send" value="send"') && str_contains($html, '>申し込む</button>') && !str_contains($html, '内容を確認する'), '確認省略は送信の操作属性・文言へ切り替える');
$context['step'] = 'confirm';
$html = Buttons::html($context, ['back' => '修正する', 'send' => 'この内容で送信']);
check(substr_count($html, '<button') === 2 && str_contains($html, 'name="submit_back" value="back" formnovalidate') && str_contains($html, 'data-omf-action="back"') && str_contains($html, '>この内容で送信</button>'), '確認は修正・送信を出力し修正だけ検証を省略');
$context['step'] = 'complete'; $before = $_SESSION;
$html = Buttons::html($context, ['home' => 'トップへ']);
check(str_contains($html, 'href="https://example.test/"') && str_contains($html, '>トップへ</a>') && !str_contains($html, '<button') && !str_contains($html, 'omf_token') && $_SESSION === $before, '完了は通常リンクだけを出力しセッション・トークンを生成しない');
$html = Buttons::html($context, ['home' => '<script>確認</script>']);
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;確認&lt;/script&gt;'), '引数のボタン文言をプレーンテキストとしてエスケープ');
check(str_contains(Buttons::html($context), 'トップページへ戻る') && !str_contains($html, '<div'), '既定文言を持ち外側のレイアウトHTMLは生成しない');
echo "共通ボタンテスト完了\n";
