# OMF-014 設定・フォームのエクスポート／インポート

- 起票日：2026-10-16
- ブランチ：`feature/import-export`（develop から作成）
- 目的：ローカルで作ったフォーム・設定を、本番へ管理画面だけで移せるようにする。環境ごとに記事IDが変わっても動くこと。

## 決定事項（2026-10-16 利用者確認済み）

- 秘密情報は既定で含めない。書き出し画面のチェックで含められる。取り込み時、ファイルに無い秘密情報は取り込み先の値を残す（OMF-017 で変更：秘密情報の書き出しは廃止。詳細は `docs/tasks/port-ui.md`）。現在は書き出さず、ファイルにあっても取り込まず、取り込み先の値を残して警告する
- 取り込み先に同じスラッグのフォームがあれば上書き（記事IDは取り込み先のまま＝送信データ `omf_db_{ID}` も引き継ぐ）。無ければ新規作成。実行前に確認画面で新規／上書きの一覧を見せる
- 上書き時、取り込み先にあってファイルに無い `cf_omf_*` メタは削除して移行元と一致させる（2026-10-16 変更）。ただし `cf_omf_mail_id`、`cf_omf_condition_id`（ファイルに `condition_targets` が無い場合）、`includes_secrets=false` のファイルでの秘密情報（Slack Webhook URL）は残す。削除対象は確認画面に表示する（OMF-017 で変更：削除は、ファイルに含まれる項目（parts）に属するキーの範囲に限る。`includes_secrets` は廃止し、秘密情報は常に残す）
- 表示条件の投稿／固定ページID（`cf_omf_condition_id`）は、書き出し時に「投稿タイプ＋パス」に変換し、取り込み時に取り込み先のIDへ戻す。見つからないページは確認画面と結果で警告する

## 書き出す内容

### 設定（オプション）

| 区分 | オプション | 扱い |
| --- | --- | --- |
| 一般 | `omf_is_rest_api` `omf_retention_days` `omf_recaptcha_score` `omf_recaptcha_field_name` | 常に含める |
| 認証キー | `omf_recaptcha_site_key` `omf_recaptcha_secret_key` `omf_turnstile_site_key` `omf_turnstile_secret_key` `omf_google_client_id` `omf_google_client_secret` | 書き出さない・取り込まない（OMF-017 で変更。旧仕様は「秘密情報も含める」の時だけ、組で扱う） |
| 対象外 | `omf_google_redirect_uri`（環境のURLに依存）、Google 認証トークン（サイト固有の鍵で暗号化）、`omf_delivery_*` `omf_postal_*` `omf_private_upload_directory` `omf_parallel_diagnostic` `omf_delivery_cron_key` などの内部状態 | 含めない |

### フォーム（`original_mail_forms` 投稿）

- 投稿：タイトル・スラッグ・ステータス
- メタ：`cf_omf_` で始まる設定すべて。ただし次は別扱い
  - `cf_omf_mail_id`（メールIDの連番）：含めない。取り込み先の値を維持（新規作成時は未設定）
  - `cf_omf_slack_webhook_url`：秘密情報。書き出さない・取り込まない（OMF-017 で変更。旧仕様は「秘密情報も含める」の時だけ）
  - `cf_omf_condition_id`：IDを `{post_type, path}` の配列に変換して書き出す
- 連携ページ：`cf_omf_select` がこのフォームのスラッグになっているページを `{post_type, path}` で書き出し、取り込み時に見つかったページへ同じ連携を設定する（見つからなければ警告）
- 送信データ（`omf_db_{ID}`）は含めない

## ファイル形式（JSON）

```json
{
  "format": "omf-export",
  "version": 1,
  "plugin_version": "1.2.0",
  "exported_at": "2026-10-16T12:00:00+09:00",
  "source_url": "http://localhost/example",
  "includes_secrets": false,
  "settings": { "omf_is_rest_api": "0" },
  "forms": [
    {
      "slug": "contact",
      "title": "お問い合わせ",
      "status": "publish",
      "meta": { "cf_omf_validation": [] },
      "condition_targets": [{ "post_type": "page", "path": "contact" }],
      "linked_pages": [{ "post_type": "page", "path": "contact" }]
    }
  ]
}
```

- `settings` や `forms` はファイルに含まれる時だけ取り込む（キーが無ければ触らない）
- `format` と `version` が一致しないファイルは拒否する

## 画面

- 「設定」画面に「エクスポート・インポート」タブ（既存の設定タブの作りに合わせる）
- エクスポート（OMF-017 で変更：範囲3択・フォームと項目の選択に刷新。秘密情報の選択は廃止）：「設定を含める」「フォーム：すべて／選択（一覧からチェック）」「秘密情報も含める（注意文つき）」→ JSON をダウンロード（`omf-export-{サイト}-{日時}.json`）
- インポート：JSON をアップロード → 確認画面（設定の変更有無・秘密情報の有無、フォームごとの新規／上書き、見つからないページの警告）→ 実行 → 結果（作成・上書き件数、警告）

## 安全性

- `manage_options` 権限と nonce 必須（書き出し・確認・実行のすべて）
- アップロードは JSON のみ・サイズ上限 2MB。`unserialize` は使わない
- 受け付けるのは上記の許可リストのオプションと `cf_omf_` メタだけ。それ以外のキーは無視して警告
- 管理画面方式の項目定義（`cf_omf_field_schema`）は、既存の `OMF_Field_Schema` の検証を通らなければ、そのフォームの取り込みを中止して理由を表示する
- 確認画面と実行の間は、アップロード内容を利用者ごとの一時データ（transient）で保持し、実行後に削除する

## タスク

| ID | 状態 | 内容 |
| --- | --- | --- |
| OMF-014-1 | 完了 | 書き出し処理（設定・フォーム・ID→パス変換・連携ページ）と JSON 生成 |
| OMF-014-2 | 完了 | 取り込み処理（検証・照合・上書き／新規・パス→ID変換・連携ページ設定・結果と警告） |
| OMF-014-3 | 完了 | 管理画面（設定のタブ・書き出しフォーム・アップロード・確認画面・結果） |
| OMF-014-4 | 完了 | テスト（`tests/import-export.php` を `test:php` に追加）と readme |
| OMF-014-5 | 完了 | ローカル2環境間（例：corp-oji の書き出し → 別のローカルWP へ取り込み）で動作確認 |

## 動作確認結果（2026-10-16）

corp-oji（フォーム551 `massyou`）から書き出し、新規の検証用WP（ページIDが異なる環境）へ取り込んで確認した。

- 新規取り込み：フォームID 15 で作成。表示条件のページはパスから取り込み先のID（12）に変換。連携ページ3件（massyou・confirm・complete）に `cf_omf_select` を設定。存在しないページは確認画面と結果で警告。corp-oji との差は `cf_omf_condition_id`（ID変換）・`cf_omf_mail_id`（含めない）・Slack Webhook（秘密情報なし）だけ
- 上書き取り込み：確認画面に「上書き ID 15」「削除されるメタ：cf_omf_stray」を表示。実行後、記事ID 15 のまま・`cf_omf_mail_id`（99）は維持・ファイルに無い `cf_omf_stray` は削除・`cf_omf_` 以外のメタは維持・書き換えたタイトルは移行元の値に戻った。`condition_targets` が空のファイルでは表示条件が空になる
- 画面からの書き出し：JSON の添付ファイル（`omf-export-…json`）として返る。既定では Slack Webhook URL・認証キーを含まず、「秘密情報も含める」で両方含まれる。`cf_omf_mail_id` は常に含まない
- 不正なファイル：形式違い・バージョン違い・JSON でないファイルは、それぞれ理由を表示して拒否
