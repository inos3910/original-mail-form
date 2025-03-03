# WordPress プラグイン Original Mail Form

## バージョン

v1.1 （2024-03-28）

## 修正・変更

- 確認画面なしでもフォームの送信が可能に。
- 自動返信メールの無効化が可能に。
- 設定 から　 REST API の ON／OFF 切り替えが可能に。
- セッションのクリア方法を変更（複数フォーム設置時にセッションが消える不具合の解消）
- 問い合わせ内容の DB 保存機能をテーブルを増やさずに実現する方法で追加
  - 「メールフォーム」投稿タイプ配下に「問い合わせデータ」メニューを追加
  - 問い合わせデータは各メールフォームの ID を suffix とした投稿タイプにする。`omf_db_{ID}`
  - 各メールフォームごとにメール送信時に問い合わせデータ投稿タイプのカスタムフィールドに保存。
  - 問い合わせデータのメールフォーム一覧を作成。
  - 問い合わせデータの各メールフォームごとの一覧を作成。
  - 問い合わせデータの詳細を作成 `add_meta_box` で詳細を表示
- Slack 通知機能の追加
- Google スプレッドシートに書き込む機能の追加（OAuth + Google Sheets API）

## 概要

- MW WP Form のクローズに伴い、移行用に作成した簡易なメールフォームプラグイン
- インストールすると「メールフォーム」というメニューが管理画面に出てくる
- 入力画面・確認画面・完了画面の 3 つの画面を、投稿または固定ページで 3 ページ用意して使う。もしくは入力画面と完了画面の 2 ページのみでも OK。
- バリデーション機能あり
- reCAPTCHA 設定あり
- ThrowsSpamAway プラグインとの連携機能あり
- 提案可能なクライアントは、フォームプラグインを必要としない（MW WP Form などのプラグインを導入しても自ら設定変更はせず、フォーム改修案件として逐一業者に依頼する）場合に限る
- フォームをクライアント側で変更する必要がある場合は、Snow Monkey Forms・Contact Form 7・Contact Form by WPForms、または Google Forms を検討する

## MW WP Form と類似する機能

- 自動返信メールあり
- メールタグ`{mail_tag}`を使ったメール本文の設定が管理画面から可能
- バリデーション設定が可能
- メールフォームを複数作成可能
- フィルターフックで自動返信・管理者宛メールどちらも本文を変更可能
- メール内容を DB に保存可能

## MW WP Form との違い

- ショートコードはない
- フォームはエディターで作らない（php ファイルをハードコーディングして作る）
- ファイルは送信できない
- ~~確認画面が必須（現状、確認画面なしでは動作しない）~~ → 確認画面なしでも送信可能に変更
- hook はほとんど無い。既存のプロジェクトで必要なものだけ追加する予定。
- 個別のエラー画面は設定不可。エラーの場合は入力画面に戻ってそこでエラーを取得して表示。
- エラー表示は自分で PHP を使って作成する必要がある
- ショートコードがないため、プラグイン管理画面上の「表示条件」で表示する固定ページもしくは投稿タイプを選択した上で、該当の投稿・固定ページ上で「メールフォーム連携」を有効化設定する必要がある
- WP REST API の専用エンドポイントでバリデーション・メール送信ができる
- Slack の Incoming Webhook を利用してして任意のチャンネルにメール内容を通知できる
- Google スプレッドシートに送信内容を記録できる

## 主な使い方

### 1, メールフォームの入力画面・確認画面・送信画面を用意する

固定ページ、もしくは投稿ページで 3 ページ用意する

例）

- 入力 /contact/
- 確認 /contact/confirm/
- 完了 /contact/complete/

### 2, 管理画面で各種設定

- 「メールフォーム」投稿タイプから新規追加
- タイトル、スラッグ、画面設定、自動返信メール、管理者宛メール、バリデーション設定、表示条件、reCAPTCHA 設定を全て設定する
- 画面設定で入力画面、確認画面、完了画面を設定（ サイト名は省略可能。`https://example.com/contact/` の場合、`/contact/`で OK。
- メール本文にはメールタグが使える。入力画面で POST 送信した値はすべてメールタグとして使える。

```
<form action="" method="post">

  <label for="name">氏名</label>
  <input type="text" name="name" id="name" value="">

  <label for="email">メールアドレス</label>
  <input type="email" name="email" id="email" value="">

  <label for="tel">電話番号</label>
  <input type="tel" name="tel" id="tel" value="">

  <label for="message">お問い合わせ内容</label>
  <textarea name="message" id="message" cols="30" rows="10"></textarea>

  <button type="submit">確認</button>
</form>

この場合、メール本文で使えるタグは下記のようになる。

{name} → 送信された氏名
{email} → 送信されたメールアドレス
{tel} → 送信された電話番号
{message} → 送信されたお問い合わせ内容

```

- バリデーション設定は「項目を追加」で追加し、バリデーション項目に POST されたキーを設定し、必要なものを入力もしくはチェックを入れる。
- 表示条件は、管理画面でフォーム連携メタボックスを表示する表示するページを指定して保存し、該当ページの編集画面に進むと、「メールフォーム連携」というメタボックスがサイドエリアに追加され、作成したどのフォームと連携するかラジオボタンで選択できるようになっているので、、任意の問い合わせフォームを選んで保存することでフォームが連携可能となる。連携を無効化する場合は連携しないを選択して保存。
- reCAPTCHA 設定はサブメニューの「reCAPTCHA 設定」から詳細設定が必要

### 3, コーディングする

MW WP Form と違い、エディターで作ることを想定していない。そのため HTML コーディングが必須。<br>
入力画面で POST するとバリデーションが実行される。<br>
エラーがある場合はエラー情報を持って入力画面に返る。<br>
エラーがない場合は確認画面が表示される。

**▼ 入力画面**

```
<?php
use Sharesl\Original\MailForm\OMF;

$values  = class_exists('Sharesl\Original\MailForm\OMF') ? OMF::get_post_values() : null;
$name    = !empty($values['name']) ? $values['name'] : '';
$email   = !empty($values['email']) ? $values['email'] : '';
$tel     = !empty($values['tel']) ? $values['tel'] : '';
$message = !empty($values['message']) ? $values['message'] : '';

//エラー
$errors  = class_exists('Sharesl\Original\MailForm\OMF') ? OMF::get_errors() : null;
if(!empty($errors)){
  ?>
  <div class="errors">
    <h2>入力エラーがあります</h2>
    <ul class="errors__list">
      <?php
      foreach ((array)$errors as $key => $error) {
        foreach((array)$error as $e){
          ?>
          <li class="error"><a href="<?php echo esc_attr("#field_{$key}")?>">・<?php echo esc_html($e)?></a></li>
          <?php
        }
      }
      ?>
    </ul>
  </div>
  <!-- /.errors -->
  <?php
}
?>

<form action="" method="post">

  <fieldset id="field_name">
    <label for="name">氏名</label>
    <input type="text" name="name" id="name" value="<?php echo esc_attr($name)?>">
  </fieldset>

  <fieldset id="field_email">
    <label for="email">メールアドレス</label>
    <input type="email" name="email" id="email" value="<?php echo esc_attr($email)?>">
  </fieldset>

  <fieldset id="field_tel">
    <label for="tel">電話番号</label>
    <input type="tel" name="tel" id="tel" value="<?php echo esc_attr($tel)?>">
  </fieldset>

  <fieldset id="field_message">
    <label for="message">お問い合わせ内容</label>
    <textarea name="message" id="message" cols="30" rows="10"><?php echo esc_html($message)?></textarea>
  </fieldset>

  <?php
  if(class_exists('Sharesl\Original\MailForm\OMF')){
    // nonceフィールドの出力
    OMF::nonce_field();
    // reCAPTCHAフィールドの出力
    OMF::recaptcha_field();
  }
  ?>

  <button type="submit" name="confirm" value="confirm">確認</button>

  <?php /*
  nameとvalueをsendにすると確認画面をスキップして送信可能
  <button type="submit" name="send" value="send">送信</button>
  */?>
</form>
```

確認ボタンは`name="confirm" value="confirm"`が必須。

**▼ 確認画面**

入力した内容の確認を表示する。<br>
入力画面からではなく直接このページに遷移したり、入力内容にバリデーションエラーがあった場合は強制的に入力画面にリダイレクトされる。<br>
確認画面で送信ボタンを押すと、メールが送信される。<br>
送信時も再度バリデーションを実行するため、エラーがあれば入力画面に戻る。

```
<?php
use Sharesl\Original\MailForm\OMF;

$values  = class_exists('Sharesl\Original\MailForm\OMF') ? OMF::get_post_values() : null;
$name    = !empty($values['name']) ? $values['name'] : '';
$email   = !empty($values['email']) ? $values['email'] : '';
$tel     = !empty($values['tel']) ? $values['tel'] : '';
$message = !empty($values['message']) ? $values['message'] : '';
?>
<form action="" method="post">
  <button type="submit" name="submit_back" value="back">← 戻る</button>

  <p>氏名</p>
  <p><?php echo esc_html($name)?></p>

  <p>メールアドレス</p>
  <p><?php echo esc_html($email)?></p>

  <p>電話番号</p>
  <p><?php echo esc_html($tel)?></p>

  <p>お問い合わせ内容</p>
  <p><?php echo esc_html($message)?></p>

  <?php
  if(class_exists('Sharesl\Original\MailForm\OMF')){
    // nonceフィールドの出力
    OMF::nonce_field();
  }
  ?>
  <button type="submit" name="send" value="send">送信</button>
</form>
```

戻るボタンは`name="submit_back" value="back"`が必須。<br>
送信ボタンは`name="send" value="send"`が必須。

**▼ 送信完了画面**

送信完了画面は、送信処理が正常終了した場合に遷移する。<br>
その他の送信処理以外でのアクセスの場合は、強制的に入力画面にリダイレクトされる。<br>
特に埋め込むタグはないので、自由にデザイン変更可能。

```
<h1>フォーム送信完了</h1>
<p>送信完了しました。</p>
```

## 備考

- メールの送信には `wp_mail()` を使用
- SMTP 設定は`WP Mail SMTP`などのプラグイン利用を想定 → wp に内蔵されている phpmailer で設定できるように変更する？
- メールの送信者名は今のところカスタマイズできないので自動返信・管理者宛でそれぞれフィールドを追加予定。

## REST API

カスタムエンドポイントを 2 つ用意。

- POST `/omf-api/v0/validate` バリデーション
- POST `/omf-api/v0/send` 送信

### 基本設定

管理画面「設定」から REST API を有効化しておく。<br>
その上でコードを追加していく。<br>
API に必須項目があるので、まず WP 側でそれを出力しておく。

```
function add_omf_scripts()
{
  $handle = 'omf';
  wp_register_script(
    //ハンドルネーム
    $app,
    //パス
    get_theme_file_uri('js/script.js'),
    //依存スクリプト
    [],
    //version
    false,
    //wp_footerに出力
    true
  );

  $arr = [
    'root'       => esc_url_raw(rest_url()), //rest apiのルートURL
    'omf_nonce'  => wp_create_nonce('wp_rest'), //認証用のnonce
    'post_id'    => get_the_ID() //記事ID
  ];

  //グローバル変数からインスタンスを取得
  global $global_omf;
  //入力画面のみ
  if (!empty($global_omf) && $global_omf->is_page('entry')) {
    //ワンタイムトークンを発行
    $arr['omf_token'] = $global_omf->get_omf_token();
  }

  wp_localize_script($handle, 'OMF_VALUES', $arr);

  wp_enqueue_script($handle);
}
add_action('wp_enqueue_scripts', 'add_omf_scripts');
```

### バリデーション

▼script.js（主要部分のみ抜粋）

```
async validate() {
  const requestUrl = `${OMF_VALUES.root}omf-api/v0/validate`;

  const requestBody = {
    user_name: 'しぇあする太郎',
    user_email: 'sharesl@example.com',
    message: 'お問い合わせ内容'
  };

  const res = await fetch(requestUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': OMF_VALUES.omf_nonce, //nonceをヘッダーに追加
      'X-OMF-Post-ID': OMF_VALUES.post_id, //記事IDをヘッダーに追加
    },
    body: JSON.stringify(requestBody),
    credentials: 'include', //セッション（Cookie）を共有する
  });
  const json = await res.json();
  console.log(json);
}
```

#### バリデーション成功時のレスポンス

```
{
  valid: true
  data: {
    user_name: 'しぇあする太郎',
    user_email: 'sharesl@example.com',
    message: 'お問い合わせ内容',
    post_id: 'xxx',
  }
}
```

#### バリデーション失敗のレスポンス

```
{
  valid: false
  errors: {
    user_email: ['必須項目です','正しいメールアドレスを入力してください']
  },
  data: {
    user_name: 'しぇあする太郎',
    user_email: '',
    message: 'お問い合わせ内容',
    post_id: 'xxx',
  }
}
```

### 送信

▼script.js（主要部分のみ抜粋）

```
async sendMail() {
  const requestUrl = `${OMF_VALUES.root}omf-api/v0/send`;

  const requestBody = {
    user_name: 'しぇあする太郎',
    user_email: 'sharesl@example.com',
    message: 'お問い合わせ内容',
  };

  const res = await fetch(requestUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': OMF_VALUES.omf_nonce, //nonceをヘッダーに追加
      'X-OMF-Token': OMF_VALUES.omf_token, //ワンタイムトークンをヘッダーに追加
      'X-OMF-Post-ID': OMF_VALUES.post_id, //記事IDをヘッダーに追加
    },
    body: JSON.stringify(requestBody),
  });
  const json = await res.json();
  console.log(json);

  //完了画面に遷移
  if ('redirect_url' in json && json.redirect_url) {
    window.location.href = json.redirect_url;
  }
}
```

ワンタイムトークンは、ボタン連打などの多重送信を回避でき、CSRF 対策にもなる。

### バリデーション失敗

送信時もバリデーションチェックが走るので、失敗するとエラーが返る

```
{
  valid: false
  errors: {
    user_email: ['必須項目です','正しいメールアドレスを入力してください']
  },
  data: {
    user_name: 'しぇあする太郎',
    user_email: '',
    message: 'お問い合わせ内容',
    post_id: 'xxx',
  }
}
```

### 送信成功時のレスポンス

```
{
  is_sended : true,
  data : {
    user_name: 'しぇあする太郎',
    user_email: 'sharesl@example.com',
    message: 'お問い合わせ内容',
    post_id: 'xxx',
  },
  redirect_url : 'https://example.com/contact/complete/'
}
```

完了画面にリダイレクトするための URL を含めて返ってくる。

### 送信失敗時のレスポンス

```
{
  is_sended : false,
  data : {
    user_name: 'しぇあする太郎',
    user_email: 'sharesl@example.com',
    message: 'お問い合わせ内容',
    post_id: 'xxx',
  },
  errors : {
    reply_mail : ['自動返信メールの送信処理に失敗しました'],
    admin_mail : ['通知メールの送信処理に失敗しました']
  }
}
```

送信処理に失敗した場合にはエラーを含めて返ってくる。

## フィルターフック

### 通知メール変更

```
/**
 * 通知メール変更
 *
 * @param String $message_body メール本文
 * @param Array $tags メールタグ情報
 * @return String メール本文
 */
function my_custom_admin_mail($message_body, $tags) {
  /*
   *メール本文をゴニョゴニョ
  **/

  return $message_body;
}
add_filter('omf_admin_mail', 'my_custom_admin_mail', 10, 2);
```

### 自動返信メール変更

```
/**
 * 自動返信メール変更
 *
 * @param String $message_body メール本文
 * @param Array $tags メールタグ情報
 * @return String メール本文
 */
function my_custom_reply_mail($message_body, $tags) {
  /*
   *メール本文をゴニョゴニョ
  **/

  return $message_body;
}
add_filter('omf_reply_mail', 'my_custom_reply_mail', 10, 2);
```

### メールタグの内容変更

```
/**
 * メールタグの内容変更
 *
 * @param String $replacement_text 現在のメールタグの置換内容
 * @param String $tag メールタグのキー
 * @return String カスタマイズ後のメールタグの置換内容
 */
add_filter('omf_mail_tag', function ($replacement_text, $tag) {
  if ($tag === 'type') {
    $replacement_text = 'カスタマイズ';
  }

  return $replacement_text;
}, 10, 2);
```

この場合は{type}というメールタグが「カスタマイズ」に置換される。

### 送信データの項目名変更

```
/**
 * 送信データの項目名変更
 *
 * @param String $field_key フィールド名
 * @return String 変更後のフィールド名
 */
add_filter('omf_data_custom_field_key_{$slug}', function ($field_key) {
  if ($field_key === 'custom') {
    return 'カスタマイズ';
  }

  if ($field_key === 'example') {
    return '例';
  }

  return $field_key;
});
```

## アクションフック

### メール送信前

```
/**
 * メール送信前
 *
 * @param String $post_data フォーム送信情報
 * @param Array $linked_mail_form メールフォームの情報
 * @param Array $post_id フォーム送信時の記事ID
 */
add_action('omf_before_send_mail', function ($post_data, $linked_mail_form, $post_id) {
  /*
   * 送信前にゴニョゴニョ
  **/
}, 10, 3);
```

### メール送信後

```
/**
 * メール送信後
 *
 * @param Array $post_data フォーム送信情報
 * @param Object $linked_mail_form メールフォームの情報
 * @param String $post_id フォーム送信時の記事ID
 */
add_action('omf_after_send_mail', function ($post_data, $linked_mail_form, $post_id) {
  /*
   * 送信後にゴニョゴニョ
  **/
}, 10, 3);
```

#### 自動返信送信前

```
/**
 * メール送信後
 *
 * @param Arrray $tags メールタグ情報
 * @param String $mail_to 送信先メールアドレス
 * @param String $form_title 件名
 * @param String $mail_template メールテンプレート
 * @param String $mail_from 送信元メールアドレス
 * @param String $from_name 送信元の名前
 */
add_action('omf_before_send_reply_mail', function ($tags, $mail_to, $form_title, $mail_template, $mail_from, $from_name) {
  /*
   * 送信後にゴニョゴニョ
  **/
}, 10, 6);
```

#### 自動返信送信後

```
/**
 * メール送信後
 *
 * @param Arrray $tags メールタグ情報
 * @param String $reply_mailaddress 送信先メールアドレス
 * @param String $reply_subject 件名
 * @param String $reply_message メッセージ内容
 * @param String $reply_headers ヘッダー情報
 */
add_action('omf_after_send_reply_mail', function ($tags, $reply_mailaddress, $reply_subject, $reply_message, $reply_headers) {
  /*
   * 送信後にゴニョゴニョ
  **/
}, 10, 6);
```

#### 管理者宛 送信前／送信後

- `omf_before_send_admin_mail`
- `omf_after_send_admin_mail`

使い方は自動返信と同じ。

## MW WP Forms からの移行方法

- MW WP Form を使った元のファイルはいじらずに、新しくカスタムテンプレートファイルを作る
- テスト環境で動作確認
- 移行作業
  1. reCAPTCHA for MW WP Form など、MW WP Form を拡張しているプラグインがある場合は移行作業前にあらかじめ無効化しておく。
  2. Original Mail Form プラグインをインストール
  3. 管理画面メニュー「メールフォーム」からフォームを作成し、メール文面などを設定
  4. 入力画面、完了画面、確認画面の編集画面で作成したカスタムテンプレートに切り替え、同時に「メールフォーム連携」から作成したフォームを選択して更新
  5. MW WP Form の該当のフォームを下書きに変更。もしくは削除。
- 移行作業をフォームの数だけ繰り返す
- すべての移行が終われば MW WP Forms をアンインストール（2023-10-20 現在、WP からアンインストールするとエラーでできないため手動で削除が必要）

この手順で移行すれば、ダウンタイムほぼなして切り替え可能。<br>
→ 固定ページの切り替え時間と MW WP Form のステータス変更の時間はかかるので 1 分ぐらいはかかるかも？
