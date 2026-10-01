<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
$cases = [
  'numeric' => ['123', '１２３', '12.3', "123\n", ''],
  'alpha' => ['ABCabc', 'abc1', '日本語'],
  'alphanumeric' => ['ABC123', 'abc_'],
  'hiragana' => ['やまだ たろう', 'ヤマダ', 'あいう'],
  'katakana' => ['ヤマダ　タロウ', 'やまだ', 'ﾔﾏﾀﾞ'],
  'kana' => ['やまだタロウ', 'やまだ たろう'],
  'postal_code' => ['1234567', '123-4567', '12-34567', '１２３４５６７'],
  'date' => ['2024-02-29', '2025-02-29', '2026-04-31', '2026-09-30', '0000-01-01', '2026/9/30', '2026年9月30日', '2026年9月30日（水）', '2025/2/29'],
  'email' => ['user@example.test', 'bad', 'a@b', 'a..b@example.test', '.a@example.test', 'a.@example.test', "a@b.test\n"],
  'url' => ['https://example.test/path?q=1', 'http://localhost:8080/', 'javascript:alert(1)', 'https://'],
  'tel' => ['090-1234-5678', '０９０１２３４５６７８', '1234567890', '090--1234--5678'],
];
$result = [];
foreach ($cases as $format => $values) {
  $field = ['type' => in_array($format, ['tel', 'email', 'url']) ? $format : 'text', 'validation_format' => $format, 'min_length' => 0, 'max_length' => 0];
  $checks = Schema::checks($field);
  foreach ($values as $value) { $result[] = ['value' => $value, 'checks' => $checks, 'valid' => Schema::validate_checks($value, $checks) === []]; }
}
foreach (['', 'あ', 'あいう', 'あいうえ', '😀😀'] as $value) {
  $checks = Schema::checks(['type' => 'text', 'min_length' => 2, 'max_length' => 3]);
  $result[] = ['value' => $value, 'checks' => $checks, 'valid' => Schema::validate_checks($value, $checks) === []];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
