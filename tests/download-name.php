<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Download_Name as Name;
$title = '抵当権抹消登記サポートフォーム_export_2026-10-01_to_2026-10-16.csv';
$header = Name::content_disposition($title, 'form-data_2026-10-01_to_2026-10-16.csv');
check(str_contains($header, "filename*=UTF-8''" . rawurlencode($title)), '日本語のフォーム名をmd5にせずUTF-8で渡す');
check(str_contains($header, 'filename="form-data_2026-10-01_to_2026-10-16.csv"'), '予備の名前は英数字だけで渡す');
check(Name::clean("a/b\\c:d*e?f\"g<h>i|j\r\n.csv") === 'abcdefghij.csv', 'ファイル名に使えない文字と改行を除く');
check(Name::clean(' 　名前  付き .csv ') === '名前 付き .csv', '連続する空白をまとめ前後を整える');
check(Name::clean('///', 'form_data.csv') === 'form_data.csv', '空になったら既定の名前を使う');
check(!str_contains(Name::content_disposition("x\"; filename=evil.exe", 'x"y.csv'), 'evil.exe"'), 'ヘッダーの区切りを注入させない');
echo "ダウンロード名テスト完了\n";
