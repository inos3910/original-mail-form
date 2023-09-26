# WordPressプラグイン Original Mail Form 

## 概要
- MW WP Formのクローズに伴い、移行用に作成した簡易なメールフォームプラグイン
- 入力画面・確認画面・完了画面の3つの画面を、投稿または固定ページで3ページ用意して使う
- バリデーション機能あり
- reCAPTCHA設定あり
- ThrowsSpamAwayプラグインとの連携機能あり

## MW WP Formと同じ
- 自動返信メールあり
- メールタグ`{mail_tag}`を使ったメール本文の設定が管理画面から可能
- バリデーション設定が可能
- メールフォームを複数作成可能

## MW WP Formとの違い
- ショートコードは使わない
- フォームはエディターで作らない（HTMLでテンプレートでハードコーディングして作る）
- メール内容はDBに保存しない
- ファイルは送信できない
- 確認画面が必須
- hookはほとんど無い
- 個別のエラー画面は設定不可。エラーの場合は入力画面に戻る。
- エラー表示は自分でPHPを使って作成する必要がある
- ショートコードがないため、プラグイン管理画面上で「表示条件」で表示する固定ページもしくは投稿タイプを選択した上で、該当の投稿・固定ページ上で「メールフォーム連携」を有効化設定する必要がある

## 主な使い方

### 1, メールフォームの入力画面・確認画面・送信画面を用意する

固定ページ、もしくは投稿ページで3ページ用意する

例）

- 入力 /contact/
- 確認 /contact/confirm/
- 完了 /contact/complete/

### 2, 管理画面で各種設定
- 「メールフォーム」投稿タイプから新規追加
- タイトル、スラッグ、画面設定、自動返信メール、管理者宛メール、バリデーション設定、表示条件、reCAPTCHA設定を全て設定する
- 画面設定で入力画面、確認画面、完了画面を設定（ サイト名は省略可能。`https://example.com/contact/` の場合、`/contact/`でOK。
- メール本文にはメールタグが使える。入力画面でPOST送信した値はすべてメールタグとして使える。

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

- バリデーション設定は「項目を追加」で追加し、バリデーション項目にPOSTされたキーを設定し、必要なものを入力もしくはチェックを入れる。
- 表示条件は、管理画面でフォーム連携メタボックスを表示する表示するページを指定。。指定して保存し、該当ページの編集画面に進むと、「メールフォーム連携」というメタボックスがサイドエリアに追加され、作成したどのフォームと連携するかラジオボタンで選択できるようになっているので、、任意の問い合わせフォームを選んで保存することでフォームが連携可能となる。連携を無効化する場合は連携しないを選択して保存。
- reCAPTCHA設定はサブメニューの「reCAPTCHA設定」から詳細設定が必要


### 3, コーディングする

MW WP Formと違い、エディターで作ることを想定していない。そのためHTMLコーディングが必須。<br>
入力画面でPOSTするとバリデーションが実行される。<br>
エラーがある場合はエラー情報を持って入力画面に返る。<br>
エラーがない場合は確認画面が表示される。<br>
確認画面で送信ボタンを押すと、メールが送信される。<br>
（内部的には送信時もバリデーションを実行する）<br>

**▼入力画面**

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

  <?php
  if(class_exists('OMF')){
    // nonceフィールドの出力
    OMF::nonce_field();
    // reCAPTCHAフィールドの出力
    OMF::recaptcha_field();
  }
  ?>
  <button type="submit">確認</button>
</form>
```

**▼確認画面**

```
<?php
$values  = class_exists('OMF') ? OMF::get_post_values() : null;
$name    = !empty($values['name']) ? $values['name'] : '';
$email   = !empty($values['email']) ? $values['email'] : '';
$tel     = !empty($values['tel']) ? $values['tel'] : '';
$message = !empty($values['message']) ? $values['message'] : '';
?>
<form action="" method="post">

  <p>氏名</p>
  <p><?php echo esc_html($name)?></p>

  <p>メールアドレス</p>
  <p><?php echo esc_html($email)?></p>

  <p>電話番号</p>
  <p><?php echo esc_html($tel)?></p>

  <p>お問い合わせ内容</p>
  <p><?php echo esc_html($message)?></p>

  <?php
  if(class_exists('OMF')){
    // nonceフィールドの出力
    OMF::nonce_field();
  }
  ?>
  <button type="submit">送信</button>
</form>
```

**▼送信完了画面**

送信完了画面は、送信処理が正常終了した場合にリダイレクトされる。<br>
その他の送信処理以外でのアクセスの場合は、強制的に入力画面にリダイレクトされる。<br>
特に埋め込むタグはないので、自由に完了メッセージを編集可能。
```
<h1>フォーム送信完了</h1>
<p>送信完了しました。</p>
```