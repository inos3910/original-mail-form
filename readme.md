# WordPressプラグイン Original Mail Form 

## 概要
- MW WP Formのクローズに伴い、移行用に作成した簡易なメールフォームプラグイン
- 入力画面・確認画面・完了画面の3つの画面を、投稿または固定ページで3ページ用意して使う
- バリデーション機能あり
- reCAPTCHA設定あり
- ThrowsSpamAwayプラグインとの連携機能あり

## MW WP Formとの違い
- ショートコードは使わない
- フォームはエディターで作らない（HTMLでテンプレートでハードコーディングして作る）
- メール内容はDBに保存しない
- ファイルは送信できない
- 確認画面が必須
- hookはほとんど無い
- 個別のエラー画面は設定不可。エラーの場合は入力画面に戻る。
- エラー表示は自分でPHPを使って作成する必要がある

## 主な使い方

### 1, メールフォームの入力画面・確認画面・送信画面を用意する

固定ページ、もしくは投稿ページで3ページ用意する

例）

- 入力 /contact/
- 確認 /contact/confirm/
- 完了 /contact/complete/

### 2, コーディングする

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
  <input type="text" name="name" id="user_name" value="">

  <label for="email">メールアドレス</label>
  <input type="email" name="email" id="email" value="">

  <label for="tel">電話番号</label>
  <input type="tel" name="tel" id="tel" value="">

  <label for="message">お問い合わせ内容</label>
  <textarea name="message" id="message" cols="30" rows="10"></textarea>

  <?php
  if(class_exists('OMF')){
    //nonceフィールドの出力
    OMF::nonce_field();
  }
  ?>
  
  <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
  <button type="submit">確認</button>
</form>
```