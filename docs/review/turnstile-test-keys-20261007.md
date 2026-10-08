# Turnstile公式テストキーの認証失敗

公式の成功用テストキーを設定していても、OMFがSiteverify応答のホスト名とサイトURLを比較し、認証成功の応答を拒否していた。通常版の実API応答は `success: true`、ホスト名は `example.com` で、サイトの `fam-security-wp.test` と一致しなかった。

`classes/class-trait-captcha.php` に、公式テストサイトキー・成功用テスト秘密キー・公式ダミートークンが揃い、Siteverifyが認証成功を返した場合だけホスト名比較を省略する処理を追加した。API呼び出しは省略しない。通常キー、通常トークン、テストキーとの混在、reCAPTCHAは従来のホスト名検証を維持する。通信エラー、不正な応答、認証失敗は拒否する。

## 検証

- `tests/turnstile.php` の18項目が成功。公式テスト応答、通常キーの一致・不一致、設定の混在、トークン欠落、HTTP・通信・JSONエラー、失敗用テスト秘密キーを検証した。`npm run test:php` に追加し、既存PHPテストを含めて成功した。
- 開発元と設置済みプラグインのPHP構文、差分の空白検査、修正ファイルの一致を確認した。
- 設置済みOMFからCloudflareの実APIを呼び、異なるホスト名の公式テスト応答で認証が成功した。メールは送っていない。
- ブラウザーでの入力→確認→送信、実メール到達の再確認は未実施。

検証先：`/Users/Yosuke/Herd/fam-security-wp`、`https://fam-security-wp.test`、DB `fam_security`。既存の通常版へ修正を反映した。FSE版は変更していない。新規サイト・DB・サーバープロセスは作成していない。再確認用スクリプトと結果を `validation/turnstile-test-keys-20261007/` に保持する。

仕様の参照先：[Cloudflare公式テストキーの説明](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)。
