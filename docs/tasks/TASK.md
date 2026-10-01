# タスク管理

完了した文書は `complete/` に置く。状態は 未着手 / 進行中 / レビュー待ち / 完了 / 保留。

| ID | 優先度 | 状態 | タスク | 依存 |
| --- | --- | --- | --- | --- |
| OMF-013 | P0 | レビュー待ち | [画面遷移・セッション構造の復元](screen-flow-session-restoration.md) | OMF-004, OMF-011 |
| OMF-001 | P0 | 完了 | [既存修正の結合検証](complete/baseline-verification.md) | — |
| OMF-002 | P1 | 完了 | [共通の項目定義と互換設計](complete/field-schema.md) | OMF-001 |
| OMF-003 | P1 | 完了 | [管理画面で項目を編集・並べ替え](complete/admin-form-builder.md) | OMF-002 |
| OMF-004 | P1 | 完了 | [関数1つで入力・確認・完了を描画](complete/managed-form-renderer.md) | OMF-002, OMF-003 |
| OMF-005 | P1 | 完了 | [カスタマイズフックの整理と追加](complete/extension-hooks.md) | OMF-002 |
| OMF-006 | P1 | 完了 | [送信時間の計測と4方式の共通設計](complete/delivery-design.md) | OMF-001 |
| OMF-007 | P1 | 完了 | [永続的な送信待ち・結果管理](complete/delivery-storage.md) | OMF-005, OMF-006 |
| OMF-008 | P2 | 完了 | [2種類の非同期送信とcron案内](complete/async-delivery.md) | OMF-007 |
| OMF-009 | P2 | 完了 | [同期並列送信と適用条件](complete/parallel-delivery.md) | OMF-007 |
| OMF-011 | P1 | 完了 | [FSE向けフォームブロック・ショートコード](complete/fse-embedding.md) | OMF-004, OMF-005 |
| OMF-012 | P1 | レビュー待ち | [管理項目の一括設定・共通バリデーション・住所自動入力](builder-input-validation.md) | OMF-003, OMF-004 |
| OMF-010 | P2 | レビュー待ち | [全方式の結合検証と利用手順](release-readiness.md) | OMF-004, OMF-005, OMF-008, OMF-009, OMF-011, OMF-013 |

レビュー: [2026-10-01](../review/complete/2026-10-01-rereview.md)

## 残り

- OMF-013: 修正・ローカル反映・検証済み、利用者レビュー待ち。専用URL・完了単回表示・3画面の独立テンプレート・動的API・foreach用の項目HTML配列・引数で文言を指定する共通ボタン出力・全設置方式の連携ON/OFFを実装。[検証記録](../guide/verification-20261001-flow.md)。
- OMF-012: 利用者確認（狭幅の実機、実送信）
- OMF-010: OMF-013の検証記録を反映済み。利用者の最終確認と実サービス確認後にリリース判断。[動作確認手順書](../guide/acceptance-checklist.md)

## 2026-10-01 載せ替え互換性の修正

原型の設定・未変更テンプレートを維持する修正を実施。手動ファイル交換後の旧方式、管理画面での新方式切り替え、旧方式への復帰、添付・REST・更新メニューを隔離試験で確認。載せ替え81項目（WP 7.1.2／PHP 8.3.33、WP 6.3／PHP 8.0.30）、既存HTTP214項目、PHP回帰9スクリプト、PHP 8.0構文80ファイルが成功。実サービス・公開Release・最終承認はOMF-010のレビュー待ちとして残す。[修正と注意点](../audit/upgrade-compatibility-20261001.md)。
