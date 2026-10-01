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

旧コード方式・同期直列の `omf_after_send_mail` は、原型どおり配送失敗時にも入力値・フォーム・ページIDの3引数で呼ぶ。失敗時にはトークンを消費せず、成功時の完了処理・受付番号更新・外部連携とは区別する。

## 旧方式の載せ替え契約

手動のファイル交換でも旧方式を維持する。`cf_omf_form_mode` 未設定は `code`、送信方式未設定は同期直列。更新イベント・再有効化を必要とせず、フォーム項目・メール設定・ページ連携を自動で新方式へ変更しない。新方式の項目保存とテーマ調整は明示操作で行う。

コード方式の添付データは `upload_id` に加えて旧 `attachment_id`・`tmp_name`・画像の `image[src,width,height]` を返す。実体は非公開で、URLの取得には本人セッションまたはメディア編集権限が必要。メール向け添付タグは原型の `id`・`name`・`url` と画像情報を維持する。POSTからこれらを送っても信用せず、サーバーの保管情報から復元する。更新前の確認セッションの添付は復元時に同じメディアIDで非公開化し、引き継げない場合は再添付エラーとする。

既存 `/omf-api/v0/validate`・`send` は、成功・入力エラー・配送失敗・認証NGとも原型のHTTP 200を維持する。認証NGはWP_ErrorのJSON表現（`errors`・`error_data.failed.status:404`）。HTTP成功だけで処理成功とせず、`valid`・`is_sended` とエラーを確認する。nonce・送信トークン・CAPTCHA・ページ連携の検証は継続する。

## 各画面をテーマのPHPで組む場合

入力・確認・完了の専用ページを画面設定に登録し、各ページの「メールフォーム連携」で同じフォームを選ぶ。確認省略の場合は入力・完了の2ページ。各URLには別々のWordPressテンプレートを用意でき、共通テンプレートへの集約は不要。

`OMF::form_context(['slug' => 'contact'])` はHTMLを出力せず、管理設定と現在の画面のデータを返す。設定不備では `WP_Error` を返す。配列を受け取ってから、見出し・説明・外枠・フォームタグをテーマで記述する。項目とボタンのHTMLはOMFの関数で生成し、配置とCSS・ボタン文言はテーマで指定できる。

| キー | 内容 |
| --- | --- |
| form_id / slug / step | 対象フォームと entry / confirm / complete |
| fields | 管理画面の順序どおりの項目定義 |
| values | 初期値または検証済み入力。完了ではリクエスト内に退避したデータ |
| errors | 項目キーごとのエラー。同じ応答で再取得しても同じ値 |
| confirm | 入力から確認へ進む場合true。確認省略時はfalse |
| action_url | 現在の専用ページへのPOST先 |
| complete_message | 完了文のプレーンテキスト。テーマ側でエスケープする |
| async | 永続受付を完了とする非同期方式ならtrue。SMTP配送完了とは限らない |

各テンプレートの先頭で取得する。

```php
use Sharesl\Original\MailForm\OMF;
$contact = OMF::form_context(['slug' => 'contact']);
if (is_wp_error($contact)) {
  echo '<p>' . esc_html($contact->get_error_message()) . '</p>';
  return;
}
```

### 項目はforeachで囲むだけ

`OMF::get_fields(['slug' => 'contact'])` は、現在の画面用の生成済みHTMLを項目キー付き配列で返す。入力・確認どちらでも同じ呼び出しを使い、入力画面では入力欄、確認画面ではラベルと確認値になる。管理設定の順序を保ち、項目追加・削除・変更・並べ替えも反映する。設定不備では `WP_Error`、完了では空配列を返す。

`form_context()` で設定が有効なことを確認した後、各テンプレートで取得する。

```php
$fields = OMF::get_fields(['slug' => 'contact']);
```

項目部分はこれだけでよい。種類別の分岐、値・必須・エラー・選択肢の組み立てをテーマに書く必要はない。

```php
<?php foreach ($fields as $field) : ?>
  <div class="contact__row">
    <?php echo $field; ?>
  </div>
<?php endforeach; ?>
```

`$field` は生成済みHTMLなのでそのままechoする。`esc_html($field)` はタグを文字として表示してしまうため使わない。OMFが管理画面由来のラベル・値・説明等をエスケープし、説明文の許可されたリンクだけを生成する。`omf_field_html` フィルターで独自HTMLを返す場合は開発者側のエスケープ責任を維持する。

| 共通クラス | 内容 |
| --- | --- |
| omf-managed-field | 項目全体。`data-omf-field` は項目キー |
| omf-managed-field--text / --email / --select 等 | 入力種類。全10種類に対応 |
| omf-managed-control | 入力欄・確認値・説明・エラーをまとめる領域 |
| omf-managed-required | 必須表示。条件付き必須も管理設定に連動 |
| omf-managed-choices / omf-managed-choice | 選択肢の一覧と各選択肢 |
| omf-managed-help / omf-managed-error / omf-managed-policy | 説明・エラー・規約本文 |
| omf-managed-value | 確認値。選択ラベル・同意・添付名を解決済み |

テーマの `.contact__row .omf-managed-field` などからCSSを指定する。専用の `.omf-managed` 外枠は不要。管理設定どおりのHTML順序を保ち、同意だけボタン前へ置きたいなどの配置はテーマのCSSで調整できる。

### ボタンも共通HTMLを関数で出力する

`OMF::render_buttons($labels = [], $selector = 0)` は、現在の画面に必要なボタンだけを出力する。第1引数は文言の指定、第2引数は任意のフォーム指定。省略すると現在のページのフォームを使う。外側のdivやレイアウトは出力しない。

入力ページの例。確認を省略する設定なら自動で送信ボタンに切り替わる。

```php
<div class="contact__actions">
  <?php OMF::render_buttons([
    'confirm' => '入力内容を確認する',
    'send' => '送信する',
  ]); ?>
</div>
```

確認ページも1回の呼び出しで修正・送信を出力する。

```php
<?php OMF::render_buttons([
  'back' => '入力内容を修正',
  'send' => 'この内容で送信する',
]); ?>
```

完了ページではトップへの通常リンクだけを出力する。完了メッセージの表示と、この関数を呼ぶ位置はテーマで決める。

```php
<section data-omf-step="complete">
  <h1>お問い合わせ完了</h1>
  <p><?php echo nl2br(esc_html($contact['complete_message'])); ?></p>
  <div class="contact__actions">
    <?php OMF::render_buttons(['home' => 'トップページへ戻る']); ?>
  </div>
</section>
```

| 画面 | 生成する操作 | 文言を省略した場合 |
| --- | --- | --- |
| 入力・確認あり | confirm | 入力内容を確認 |
| 入力・確認省略 | send | 送信する |
| 確認 | back / send | 入力内容を修正 / 送信する |
| 完了 | home（トップへのa要素） | トップページへ戻る |

文言はプレーンテキストとしてエスケープする。共通クラスは `.omf-managed-button`、操作別クラスは `--confirm`・`--send`・`--back`・`--home`。確認・送信には `.omf-managed-primary` も付ける。name/value・操作属性・修正ボタンの `formnovalidate` はOMFが生成する。テーマは `.contact__actions .omf-managed-button` などからCSSで調整できる。完了リンクの生成でセッション・トークンを再作成しない。

入力のフォームタグはテーマに置く。`method="post"`、添付を扱う場合の `enctype="multipart/form-data"`、`action` に `$contact['action_url']`、`data-omf-form` に `$contact['slug']`、`data-omf-step="entry"` を指定する。フォーム内で `OMF::nonce_field()` と項目ループ、`OMF::recaptcha_field()`・`OMF::turnstile_field()`、ボタン出力を呼ぶ。

確認も同じ項目ループを使い、フォームの `data-omf-step` を `confirm` にする。nonceとボタンを出力し、CAPTCHAや入力値のhiddenコピーは作らない。送信処理はセッションの検証済みデータを使う。完了はフォームタグもトークンも不要。

### 操作のtransitionとローディング

`form[data-omf-form]` に共通JSを適用する。検証を通過したsubmitで、押したボタンをスピナーと「確認中…」「送信中…」「移動中…」へ切り替え、二重送信を防止する。フォームを `inert` にして操作を止め、`setTimeout(..., 0)` で全入力欄・選択欄・ボタンをdisabledにする。ブラウザの通常のPOST値確定を先に行うため、FormDataの退避・差し替えはしない。

応答待ちが60秒を超えた場合と `pageshow` で戻った場合は、元のdisabled・ボタンHTML・操作状態へ復元する。入力条件の検証も再実行する。タイムアウトは通信の取消や未送信の保証ではなく、自動再送もしない。結果不明の案内を表示し、再送前の確認を促す。履歴キャッシュからの復帰では既存の表示許可の再検証も行う。

共通CSSはボタンの有効/無効、radio・同意チェック、入力フォーカスを200msで変化させる。`prefers-reduced-motion` ではtransitionとスピナー回転を止める。テーマは `--omf-interaction-color`・`--omf-motion` と `.omf-managed-button[data-omf-loading="true"]`・`.omf-submit-status` で調整できる。ローカルの遅延試験などではフォームの `data-omf-submit-timeout` に正のミリ秒値を指定でき、省略時は60000になる。

入力条件が不足する間だけ、`.omf-validation-status` に残り件数をボタンの下へ表示する。条件を満たした場合は文言を空にして非表示にする。同意チェックでは文言・リンクの色を変更せず、チェックボックスの選択状態を切り替える。同意欄のエラー行の位置と余白はテーマCSSで調整できる。

`data-omf-form` と `data-omf-step` は履歴キャッシュ復元時の再検証に使用する。独自テンプレートでも記述する。nonce・トークン・CAPTCHA・セッション・検証・送信・303遷移はプラグインが担当する。テーマは `wp_head()` と `wp_footer()` を呼ぶ。

### 入力要素もテーマで記述する場合

`OMF_Field_Renderer::attributes($field, $value, $errors, $form_id)` は、name・ID・検証・必須・郵便番号連携・エラーの属性を返す。テーマで input / select / textarea を書く場合も、この属性を使って管理設定とPHP・JS検証を揃える。選択肢は `$field['choices']` から生成し、値・ラベルはテーマ側でエスケープする。項目の順序は `fields` のforeachで反映する。

### 自動描画と既存の部分テンプレート

`OMF::render_form(['slug' => 'contact'])`、ショートコード、ブロックも引き続き利用できる。入力・確認はフォームと操作を自動描画する。完了は設定されたメッセージだけを出力し、見出し・外枠・トップへのリンクは設置先のページやテーマに置く。

既存の `fields_template`・`actions_template`・`complete_template` は後方互換のため維持する。読込先は有効な親/子テーマ内のPHPファイルに限定する。項目用には `$omf_fields`、`$omf_values`、`$omf_errors`、`$omf_step`、`$omf_form_id`、操作用には `$omf_confirm`、完了用には `$omf_complete_message` と `$omf_values` が渡る。完了テンプレートの前後にはプラグインの見出し・外枠・リンクを付けない。

完了メッセージは「フォーム」タブの「完了画面のメッセージ」で設定する。HTML・メールタグは展開しない。完了応答では描画前に対象フォームのセッションが保存先から消去される。`form_context()`・`get_post_values()`・`form_step()` はその応答内だけ退避した情報を参照する。完了から `nonce_field()`・`get_omf_token()` を呼んでもトークンは再作成しない。非同期配送は受付IDと永続データを使う。


ページ側の「メールフォーム連携」は、PHP・本文ブロック・本文ショートコードすべてに適用する。設置したフォームと連携先が一致しないページは表示・送信できない。未設定・「連携しない」はOFFとして扱う。
