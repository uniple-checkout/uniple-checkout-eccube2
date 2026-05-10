# 移行メモ (= migration notes)

本 plugin の SSOT 化移行 (= 経路選択 = LINE 経由 / WC 直 を uniple 本体側
admin で一元管理) に伴う Phase 1 → Phase 2 の段階移行記録。 加盟店 / plugin
利用者向けに公開する SSOT。

> ℹ️ 本ドキュメントは uniple 本体 changelog と内容を同期しています。 公式の
> 最終情報 (= Phase 2 実施日確定通知 等) は uniple changelog を参照ください。

---

## 1. 背景 (= 設計合意)

uniple checkout for LINE は加盟店に LINE 利用料を請求する有料機能のため、
経路選択 (= `wc_only` / `line_only` / `both`) は **加盟店契約と紐づく billable
設定**として、 uniple 本体の MerchantSite 設定で SSOT 1 元管理する方針が
2026-05-10 に確定。

= plugin 側 docs / 案内文は「経路は uniple admin で管理、 plugin 側で経路
指定不要」 に統一する。 設計詳細は uniple Claude / plugin Claude の合意ログ
(= `drafts/uniple-claude-checkout-mode-proposal.md` +
`drafts/uniple-claude-checkout-mode-response-r1.md`) 参照。

---

## 2. 段階移行 (= Phase 1 / Phase 2)

| Phase | 期間 | plugin 側変更 | uniple 本体側変更 |
|---|---|---|---|
| **Phase 1** | 2026-05-10 ~ | docs / 案内文 SSOT 化 (= **本 commit**)、 内部 `?wc=1` 付与は **legacy 互換で残置** | MerchantSite.checkoutMode + lineUsageEnabled + environment + AuditLog 追加 + Hosted Checkout routing 拡張 |
| **Phase 2** | 2026-08-08 以降 (= 最早) | plugin 内部 `?wc=1` 付与ロジック削除 | `?wc=1` query reject (= legacy hit 廃止) |

---

## 3. Phase 2 実施 trigger (= SLA)

uniple 本体側で下記すべて満たした場合、 uniple Claude から plugin Claude へ
「Phase 2 実施日確定」 通知 → plugin 側 commit chain 着手。

- **(a)** Phase 1 release 日 (= **2026-05-10**) から **少なくとも 90 日** 経過
- **(b)** production merchant の `?wc=1` legacy hit が **30 日連続で 0**
  (= daily aggregate)
- **(c)** 母集団 = MerchantSite.environment == `'production'` のみ
  (= internal / test 除外)
- **(d)** 観測パイプライン正常性 OK + 分母 (= active production merchant 数) +
  除外件数明示

= **最早 trigger 候補日**: **2026-08-08 周辺** (= 90 日経過、 30 日連続 0 を
同時達成済の場合)

90 日内に 1 件でも legacy hit 発生 = 30 日 window 再 start。 90 日経過後も hit
が続く = 0 達成日から 30 日待って Phase 2 確定。

---

## 4. `?wc=1` query の自動付与について (= compatibility note)

互換維持のため、 plugin は **Phase 1 release (= 2026-05-10) から少なくとも
90 日** の移行期間中に限り `?wc=1` query を自動付与しています。
**加盟店側の追加設定は不要**です。 削除時期 (= Phase 2 実施日) は uniple
公式 changelog でお知らせします。

= 内部実装は legacy 互換のため `?wc=1` 付与継続、 docs / 案内文は「経路指定
不要」 に統一されます。 加盟店から見える挙動 / API には影響しません。

---

## 5. 加盟店向けに必要な対応 = なし

Phase 1 では加盟店側の対応は **一切不要**です。 既存の plugin 設定 + 既存の
checkoutUrl 経路はそのまま動作します。

Phase 2 実施時 (= 2026-08-08 以降、 uniple changelog で告知) は plugin update
公開 → 加盟店は通常の plugin update 手順でアップデート反映。 既存設定は
そのまま維持。

---

## 6. 経路選択を変更したい加盟店向け

経路選択 (= LINE 経由 / WC 直 / 両方) の変更は、 uniple admin で運営側のみ
変更可。 加盟店から経路変更を希望される場合は uniple サポート
(= support@uniple.io) までご連絡ください。

= MVP では加盟店 self-serve は提供されません。 契約状態 + LINE 利用料
変更を伴うため、 uniple 運営側で契約合意確認 + admin で変更操作 +
加盟店通知 のフローを経由します。

## 6.5 LINE 利用料の課金タイミング (= D user 経営判断)

uniple checkout for LINE は加盟店契約と紐づく **有料機能**ですが、 経路選択
release (= step 2) と billing system 連携は段階分離されています:

- **無料期間** = step 2 release (= 経路選択先行 release) ~ billing system
  release 間は LINE 経路を使用しても **加盟店への料金請求は発生しません**
- **記録は継続** = 利用期間は uniple AuditLog に記録され、 後日方針変更時に
  遡って参照可能 (= 現状方針は無料扱い、 後追い請求への変更可能性は理論上
  保持)
- **billing release 後** = 通常の月額 / 従量課金プランに移行 (= 詳細は
  uniple サポートへ確認)

= 早期普及優先の経営判断、 billing 構築までの暫定措置。 加盟店通知は uniple
admin から個別送信されます。

## 6.6 緊急停止 (= kill-switch) について

経路選択は MerchantSite.checkoutMode の snapshot で session 単位に固定されます
が、 緊急時 (= 加盟店契約取消 / 問題発覚時) には uniple 本体側で **強制
`wc_only` への倒し込み** (= MerchantSite.lineUsageDisabledAt を set)
が可能です。

- 発動権限 = uniple engineering on-call + 経営層通知 (= AuditLog で D user
  即時可視化)
- 影響 = 設定後の新規 session は強制 `wc_only` (= 既存 LINE 経路 session
  は完走まで継続、 新規のみ切替)
- 加盟店通知 = 発動時に email + admin dashboard で告知

加盟店側で「経路を強制的に WC 直に倒したい」 等の緊急要望がある場合は
uniple サポート (= support@uniple.io) までご連絡ください。

---

## 7. 関連 docs / 公式情報

- **uniple 本体 docs**: `https://uniple.io/docs/merchant-api` (= API SSOT)
- **plugin Spec**: [merchant-integration-spec.md](merchant-integration-spec.md)
  (= plugin SSOT、 §1.2 経路選択 / §2.7 LINE 経路統合 / §2.6.1 PHP 例)
- **plugin README**: [../README.md](../README.md)
  (= EC-CUBE 2.x 詳細、 実装メモ section)

---

## 8. 更新履歴

| 日付 | 内容 |
|---|---|
| 2026-05-10 | Phase 1 release (= docs SSOT 化 + 案内文追加 + 移行メモ新設) |
| (TBD) | Phase 2 実施 (= uniple changelog 告知後) |
