# WordPress プラグイン Original Mail Form

## バージョン

v1.2.0 開発版（2026-09-28・利用者確認待ち）

## 管理画面方式と配送拡張

項目ごとのコードを不要にするフォーム編集・同一ページ自動描画、項目/メールの拡張フック、同期直列・同期並列・WordPress非同期・サーバーcron非同期に対応。

- [動作確認手順書](docs/guide/acceptance-checklist.md)
- [配送設計と制限事項](docs/spec/delivery-design.md)
- [カスタマイズフック](docs/spec/extension-hooks.md)
- [タスク進捗](docs/tasks/TASK.md)

非同期・並列の配送用データは通常DB保存OFFでも最長7日間保持し、全処理成功時には本文・添付を消去する。並列送信は管理画面の環境確認が必要。SMTP受理後の結果不明は自動再送しない。

## コードを書かずに設置する（FSE対応）

公開済みの「画面でかんたんに作成」フォームを用意し、投稿・固定ページの本文に「お問い合わせフォーム」ブロックを追加してフォーム名で選ぶ。PHP編集・ページ連携設定は不要。

ショートコード `[omf_render_form slug="フォームのスラッグ"]` でも設置できる。フォーム編集画面の「ページに設置する」にコピー欄を用意。旧 `[original_mail_form id="フォームID"]` も利用できる。項目編集は共通の管理画面で行い、ブロックは入力欄の見本と編集リンクを表示する。

1ページに1フォーム。グループと同期パターン内に対応し、複数設置・循環参照・クエリーループ内は拒否する。設置先ページIDとフォームIDで入力・認証・添付・完了状態を分離する。同一フォームを複数ページに置いても状態を共有しない。サイトエディターのテンプレート/パーツへの直接設置は対象外。

ブロック・ショートコードは共通のページPOST経路で送信する。旧REST送信APIへは渡さない。既存のPHP関数とコード方式も維持する。PHPテンプレートでは、設置先ページの「メールフォーム連携」で対象フォームを選び、`OMF::render_form(['slug' => 'フォームのスラッグ']);` を1行記述する。入力・確認・完了は同じ呼び出しで切り替わる。`echo do_shortcode('[omf_render_form slug="フォームのスラッグ"]');` も同じ条件で利用できる。

## 変更履歴

### v1.2.0（2026-09-26）

- Page/RESTの送信・検証を共通化。CAPTCHA通過をフォーム、入力内容、トークンに結び付け、15分保持する。送信成功時に破棄する。
- バリデーションに定義した項目だけを受け付ける。入力種別は単一文字列・複数選択・ファイル。既存の複数選択は自動判定を維持するが、メール等の単一値項目に配列は許可しない。
- 選択肢の値チェック、文字列 `0`、欠落値、メールタグの再置換を修正。電話番号はハイフンを除いた0始まりの10〜11桁で検証する。
- 自動返信の添付を禁止。添付は公開メディアへ登録せず、一時ディレクトリに保存し、送信成功後に削除する。未受付の添付は1時間で削除対象になる。非同期・並列の受付済み添付は配送処理完了または保持期限まで保護する。
- 送信失敗時は入力とトークンを保持。片方だけ成功した場合は同じ入力で未送信分だけ再送する。DB記録は同じ試行の記録を更新する。
- フォームページと対象RESTのみセッション開始。HTTPS時だけsecure cookieを設定し、303リダイレクトでPOST再送を防ぐ。ブラウザの戻る禁止を廃止。
- Google OAuthの権限・state・解除POSTのnonce検証、メタ保存の権限・nonce・投稿種別確認を追加。
- GoogleトークンをランダムIVの認証付き暗号へ移行。旧暗号を読めた際に移行し、サイトsaltが変わった場合は再接続が必要。
- 通知メールに任意のReply-To設定を追加（空欄で無効）。From未設定時はWordPressの既定値を使い、他のメールのFromに影響させない。
- CSVの対象制限と数式対策、DBの保存期間、WordPressの個人データ出力・消去へ対応。
- 独自のmaster ZIP上書き更新を廃止。公開GitHub Releaseの配布ZIPをWordPress標準の更新画面へ表示する。
- ビルド依存更新、非推奨css-mqpacker等の削除、npm lockfileへ統一。

### 更新時の互換性と設定

- PHP 8.1以上、WordPress 6.3以上。新たに受け付ける項目は必須でなくてもバリデーション一覧へ登録する。複数選択は「複数選択」を選び、選択肢を「一致する文字」に指定する。
- 添付を使用するフォームは `enctype="multipart/form-data"` とファイル項目の設定が必要。既存の拡張子指定は維持する。新しいファイル種別で拡張子が未指定の場合は jpg/jpeg/png/gif/webp/pdfを許可する。
- 1ファイルの上限はフォーム設定・サーバー上限・10MBのうち最小。未完了の保存上限はサイト全体100件（`omf_max_pending_uploads` フィルター）。
- 一時保管先はサーバーの一時ディレクトリ内。必要なら `OMF_PRIVATE_UPLOAD_DIR` に公開領域外の絶対パスを指定する。公開領域内しか使えない環境では保存を拒否する。添付プレビューの公開URLは返さない。
- 旧版が既に公開メディアへ保存した添付は自動削除しない。必要な保管を確認したうえで管理者が整理する。
- DB保存期間は「メールフォーム → 設定」で日数を指定。0は無期限。個人データの出力・消去はWordPressの「ツール」から行い、メール検証を設定した項目の完全一致で本人データを探す。外部サービスや受信済みメールは対象外。
- 自動返信失敗・通知成功時も画面は失敗を表示する。同じ入力の再送は返信だけを再試行する。入力を変更した場合は新しい送信として扱う。
- RESTは成功200、入力不正400、認証不正403、配送失敗502。独自JSはHTTPエラー時もJSONの `errors` を読み、入力を保持する。nonceは認証・CAPTCHAの代替ではない。
- `OMF::get_post_values()` は読み取り専用。ファイル保存は送信/確認の検証を通った後にのみ実行する。

### Turnstile

管理画面の「Turnstile設定」にサイトキーとシークレットキーを登録し、フォームのTurnstileを有効にする。入力フォーム内に `OMF::turnstile_field()` を配置する。RESTでは取得した `cf-turnstile-response` を検証リクエストへ含め、Cookieと入力内容を送信時まで維持する。有効化したのにキーやトークンがない場合は送信を拒否する。

### 配布と開発

`npm ci` → `npm run build` → `npm run test:php`。ビルドにはNode 22.18以上を使用する。配布ZIPはトップ階層を `original-mail-form/` とし、PHP・classes・templates・dist・assets・blocks・autoload.phpを含める。tests・node_modules・Git情報は含めない。

GitHubで `v1.2.0` のような正式リリースを作り、ビルド済みの `original-mail-form.zip` を添付すると標準更新で検出する。masterの更新だけでは配布しない。リリース公開は別途行う。

### v1.1.1（2026-09-26）セキュリティ修正

- 添付ファイル機能で、フォーム設定で file 型として定義していない項目や、許可していない拡張子のファイルも uploads に保存できてしまう不具合を修正
  - 保存前に「拡張子の許可リスト」「`wp_check_filetype_and_ext()` による実体と拡張子の一致」「サイズ上限」を検証するように変更（検証NGの場合は保存せずエラーにする）
  - 実行可能な拡張子（`.php` など）・`.html`・`.htm`・`.svg` は許可リストに関係なく常に拒否
  - 保存するファイル名をランダムな文字列にし、元のファイル名は添付のメタとして保持するように変更
  - 保存はフォーム送信の nonce が正しい場合だけ行うように変更（画面の描画だけでは保存しない）
- 送信データに配列を送り込むことで、メールアドレスの検証やメールタグの置換をすり抜けられる不具合を修正
  - ファイル項目以外の POST 値は文字列のみ受け付けるように変更（チェックボックスなど正当に配列を送る項目は、文字列だけで構成された配列のみ許可）
  - 自動返信・通知メールの宛先にメールタグ（`{email}` など）を使っている場合、置換後の値が単一の正しいメールアドレスの時だけ送信するように変更（固定で複数指定した宛先は従来どおり送信）
- 添付ファイルの情報（`attachment_id` など）を POST から偽装することで、他人がアップロードした添付ファイルをメールに付けられてしまう不具合を修正
  - 添付にはサーバー側で検証・保存したファイルの情報だけを使うように変更（POST のファイル情報は信用しない）

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
- 添付ファイルは通知メールだけに送信できる。自動返信にはファイル名のみ記載する。
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
    $handle,
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
  if (res.status === 403) { throw new Error('有効期限が切れました。入力画面を開き直してください。'); }
  if (res.status === 400 || res.status === 502) {
    // json.errors を画面へ表示し、入力を保持して再試行する。
    return json;
  }
  if (!res.ok) { throw new Error('通信に失敗しました。'); }
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
    credentials: 'include',
  });
  const json = await res.json();
  if (res.status === 403) { throw new Error('有効期限が切れました。入力画面を開き直してください。'); }
  if (res.status === 400 || res.status === 502) {
    // json.errors を画面へ表示し、入力を保持して再試行する。
    return json;
  }
  if (!res.ok) { throw new Error('通信に失敗しました。'); }

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

例）空のラベルに特定の条件の時だけ表示させる。`Sharesl\Original\MailForm\OMF::get_post_values()`でフォームの送信情報を取得できる。

```
add_filter('omf_mail_tag', function ($replacement_text, $tag) {
  if ($tag === 'local_address') {
    $values      = Sharesl\Original\MailForm\OMF::get_post_values();
    $postal_code = !empty($values['postal_code']) ? $values['postal_code'] : '';
    $prefecture  = !empty($values['prefecture']) ? $values['prefecture'] : '';
    $address     = !empty($values['address']) ? $values['address'] : '';
    if ($postal_code || $prefecture || $address) {
      $replacement_text =  "" . "\n";
      $replacement_text .= "■現地住所" . "\n";
      $replacement_text .= "{$postal_code} {$prefecture}{$address}" . "\n";
    } else {
      return '';
    }
  }

  return $replacement_text;
}, 10, 2);
```

### 送信データの項目名表示（CSV 出力表示）変更

```
/**
 * 送信データの項目名変更
 *
 * @param String $field_key フィールド名
 * @return String 変更後のフィールド名
 */
add_filter('omf_data_custom_field_key_{$post_type}', function ($field_key) {
  if ($field_key === 'custom') {
    return 'カスタマイズ';
  }

  if ($field_key === 'example') {
    return '例';
  }

  return $field_key;
});
```

### 送信データの値表示（CSV 出力表示）変更

```
/**
 * 送信データの値表示（CSV 出力表示）変更
 *
 * @param String $field_value フィールド値
 * @param String $field_key フィールド名
 * @return String 変更後のフィールド名
 */
add_filter('omf_data_custom_field_value_{$post_type}', function ($field_value, $field_key) {
  if ($field_key === 'postal_code') {
    if(empty($field_value)){
      return $field_value;
    }

   $formatted_zip_code = substr($field_value, 0, 3) . '-' . substr($field_value, 3);
   return $formatted_zip_code;
  }

  return $field_value;
}, 10, 2);
```

### CSV 出力データの選択をパターンとしてボタンに登録する

```
/**
 * CSV 出力データの選択をパターンとしてボタンに登録する
 * @param Array 出力するフィールド名の配列（初期値は空なのでボタンは非表示）
 * @return Array 変更後の出力するフィールド名の配列
 */
add_filter('omf_output_data_patterns_massyou', function ($patterns) {
$patterns = [
['user_name', 'furigana', 'tel', 'email', 'postal_code', 'pref', 'city', 'address', 'message'],
['user_name', 'postal_code', 'pref', 'city', 'address']
];
return $patterns;
});
```

この場合は配列が 2 つ入っているのでパターン 1 とパターン 2 ボタンが表示され、それぞれをクリックすると一括で指定したフィールドを選択できる

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
