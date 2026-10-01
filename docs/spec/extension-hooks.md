# カスタマイズフック（1.2.0）

テーマのfunctions.phpまたは独自プラグインに記述する。登録は管理画面だけに限定せず、REST・cron・WP-CLIでも読み込まれる場所に置く。フォームIDで対象を限定する。

## 新規フック

| 名前 | 種別・引数 | 実行場所・回数 |
| --- | --- | --- |
| omf_field_schema | filter: 定義配列, form_id | 定義読込ごと。返却後に正規化し、不正なら受付停止 |
| omf_field_choices | filter: 選択肢配列, field配列, form_id | 各項目の読込ごと。描画と検証の両方 |
| omf_field_default | filter: string/配列, field配列, form_id | 定義読込ごと。初期値を表示に使うのは初回入力のみ |
| omf_validation_errors | filter: 空配列, 入力配列, form_id, page_id | 検証ごと。項目キー→メッセージ配列を返す。組み込みエラーは消せない |
| omf_field_html | filter: null, field, value, errors, confirm(bool), form_id | 自動描画の各項目。stringを返すと置換。HTMLのエスケープと入力nameは実装者の責任 |
| omf_before_field | action: field, value, confirm, form_id | 標準の項目描画前。独自HTMLへ差し替えた場合は呼ばれない |
| omf_mail_args | filter: 引数配列, reply/admin, form_id | wp_mail直前、試行ごと。to/subject/message/headers/attachments。保存済み以外の添付追加は不可 |
| omf_mail_result | action: bool, reply/admin, form_id | wp_mail試行終了時。DBへの結果保存・受信箱到着を意味しない |
| omf_submission_accepted | action: receipt_id, form_id, mode | 非同期・並列の永続受付後。通常の再POSTでは重複を抑制するが、異常終了を跨ぐ厳密な1回保証はない |
| omf_delivery_completed | action: receipt_id, form_id, reply状態, admin状態 | 非同期・並列の両通完了後、DB保存・既存外部連携後。中断時は未実行の可能性がある |
| omf_operation_timing | action: operation, milliseconds(float), form_id | mail_reply/mail_admin/database/slack/sheets。本文・宛先・秘密情報を含めない |
| omf_extension_error | action: hook_name, receipt_id | 受付後の拡張処理の例外通知 |

項目定義のフィルターは繰り返し実行される。外部APIを直接毎回呼ばずキャッシュし、確認→送信の間で選択値が変わらないようにする。非同期・並列のフックではセッションや画面出力を使用しない。受付IDを連携先の重複防止キーとして使う。


## 外部選択肢の例

```php
add_filter('omf_field_choices', function ($choices, $field, $form_id) {
    if ($form_id !== 123 || $field['key'] !== 'department') { return $choices; }
    return [['value' => 'sales', 'label' => '営業'], ['value' => 'support', 'label' => 'サポート']];
}, 10, 3);
```

## 独自検証の例

```php
add_filter('omf_validation_errors', function ($errors, $data, $form_id) {
    if ($form_id === 123 && ($data['message'] ?? '') === 'テスト禁止') {
        $errors['message'][] = 'お問い合わせ内容を具体的に入力してください。';
    }
    return $errors;
}, 10, 3);
```

## 通知先変更の例

```php
add_filter('omf_mail_args', function ($args, $kind, $form_id) {
    if ($form_id === 123 && $kind === 'admin') { $args['to'] = 'support@example.com'; }
    return $args;
}, 10, 3);
```

## 完了後の連携例

```php
add_action('omf_delivery_completed', function ($receipt_id, $form_id, $reply, $admin) {
    // 独自システム側で $receipt_id の処理済み確認を行ってから連携する。
}, 10, 4);
```

## 既存フックの扱い

同期直列の既存フックの引数と順序は維持する。同期並列では各メール内のbefore→wp_mail→afterを維持するが、返信と通知の相互順序は保証しない。omf_before_send_mailは受付リクエスト、非同期のomf_after_send_mailはワーカーで両通の成功後に実行する。非同期で入力受付と配信完了を区別する場合は新規フックを使う。

## 管理項目をPHPで配置する場合

通常は `OMF::render_form(['slug' => 'contact']);` だけで全項目を出力する。特殊な構造が必要な場合だけ、テーマ内の項目テンプレートを指定できる。管理画面の項目順序と定義をそのまま受け取るため、順番をPHPに固定しない。

```php
OMF::render_form([
  'slug' => 'contact',
  'fields_template' => get_theme_file_path('/include/contact-fields.php'),
  'actions_template' => get_theme_file_path('/include/contact-actions.php'),
  'complete_template' => get_theme_file_path('/include/contact-complete.php'),
]);
```

項目テンプレートには `$omf_fields`（順序付き定義）、`$omf_values`、`$omf_errors`、`$omf_step`、`$omf_form_id` が渡る。

```php
foreach ($omf_fields as $field) {
  // 項目キーに応じた囲みや説明を追加できる。
  OMF::render_field($field, $omf_values[$field['key']],
    (array) ($omf_errors[$field['key']] ?? []),
    $omf_step === 'confirm', $omf_form_id);
}
```

`form`・nonce・CAPTCHA・操作ボタンはプラグインが生成する。テンプレートに重複して書かない。読込先は有効な親/子テーマ内のPHPファイルに限定する。完了テンプレートは文面だけを担当し、`$omf_step` と `$omf_form_id` を使える。

ページ見出しや電話案内を切り替える場合は `OMF::form_step(['slug'=>'contact'])` の entry / confirm / complete を参照する。PHPでの設置・管理方式用のAPI。FSEではフォームの中の見出し・操作が自動で切り替わる。

### カンプに合わせて入力要素も記述する場合

`OMF_Field_Renderer::attributes($field, $value, $errors, $omf_form_id)` は、name・ID・検証・必須・郵便番号連携・エラーの属性を返す。テーマで input / select / textarea を書く場合も、この属性を使い、管理設定とPHP・JS検証を揃える。選択肢は `$field['choices']` から生成する。入力値とラベルはテーマ側でエスケープする。

入力画面だけ変更する場合は `OMF::form_step()` が `entry` のときだけ `fields_template` と `actions_template` を渡す。確認画面は通常の `OMF::render_form(['slug'=>'contact'])` で同意を含めて順番どおり表示する。

`actions_template` は操作ボタンだけを担当する。`$omf_confirm` がtrueなら name/value/action は `confirm`、falseなら `send` を使い、`data-omf-role="action"` と `data-omf-action` を付ける。nonce・CAPTCHA・form はプラグインに任せる。確認画面にも指定する場合は戻るボタンを含めて実装する。

完了メッセージは「フォーム」タブの「完了画面のメッセージ」で設定する。標準描画・ショートコード・ブロックで共通に表示し、HTML・メールタグは展開しない。完了テンプレートを指定した場合は `$omf_complete_message` に文面が渡るため、`nl2br(esc_html($omf_complete_message))` などで表示する。
