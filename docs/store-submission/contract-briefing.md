# EC-CUBE 社との基本契約交渉用 説明資料

EC-CUBE 公式オーナーズストアに **決済プラグイン** を公開する場合、 EC-CUBE 社
(= 株式会社イーシーキューブ) との基本契約 / NDA 締結が技術審査の前提条件。
本資料は D user (= 株式会社 uniple 法人代表) が EC-CUBE 営業窓口と契約交渉を
進める際の説明用。

## 1. プラグイン概要

### プラグイン名
**uniple checkout**

### 提供する決済機能
日本円ステーブルコイン **JPYC** (= 資金決済法第 2 条第 5 項に基づく**電子決済
手段**、 JPYC 株式会社発行 / 関東財務局長第 00099 号 資金移動業者) によるカート
決済。

### 法令上の重要事項
- JPYC は **電子決済手段** であり **暗号資産ではない** (= 法令上明確に区別)
- 加盟店側は **uniple が PSP として介在する設計のため、 電子決済手段等取引業
  (資金決済法第 2 条第 10 項) の登録は不要**
- JPYC Prepaid (= 旧 JPYC、 前払式支払手段) とは別ブランド、 本 plugin は新 JPYC
  (= 電子決済手段) のみを対象

### 対応する EC-CUBE バージョン
- EC-CUBE 4.3.x (= MVP 検証済み)
- EC-CUBE 2.17.2-p2 / 2.25.0 (= 別 plugin、 別 repo)

## 2. 決済フロー (= PCI DSS 非保持化の根拠)

### 加盟店 EC-CUBE サーバ ⇄ 顧客 ⇄ uniple ⇄ blockchain の責任境界

```
[顧客ブラウザ] ─→ [加盟店 EC-CUBE]                   [uniple Hosted Checkout]                [blockchain]
     │                  │                                       │                                  │
     │   1. 注文確定     │                                       │                                  │
     │ ─────────────→   │                                       │                                  │
     │                  │  2. createSession                     │                                  │
     │                  │ ─────────────────────────────────────→│                                  │
     │                  │  3. checkoutUrl 返却                  │                                  │
     │                  │ ←─────────────────────────────────────│                                  │
     │  4. uniple へ redirect (= 加盟店 server を経由しない)                                          │
     │ ──────────────────────────────────────────────────────→  │                                  │
     │  5. wallet 接続 + 署名 (= JPYC 残高確認、 加盟店 server を経由しない、                          │
     │      クレジットカード情報も発生しない)                       │                                  │
     │ ←──────────────────────────────────────────────────────  │                                  │
     │                                                          │   6. on-chain Tx broadcast       │
     │                                                          │ ────────────────────────────────→│
     │                                                          │   7. Tx confirm                  │
     │                                                          │ ←────────────────────────────────│
     │                                          8. webhook 配信 (= 加盟店 server に完走通知)         │
     │                  │ ←─────────────────────────────────────│                                  │
     │                  │  9. order status = 入金済み                                              │
     │  10. successUrl 着地 → サンクスページ                                                        │
     │ ←─────────────────────────────────────────────────────  │                                  │
```

### 加盟店 EC-CUBE サーバを通過するデータ
- 注文情報 (= order_id, payment_total, customer 情報)
- uniple session ID (= ucs_xxx)
- webhook payload (= 完走通知、 Tx hash 等の chain 上 evidence)

### 加盟店 EC-CUBE サーバを **通過しないデータ**
- **クレジットカード情報** (= そもそも本 plugin はクレジットカード決済では
  ない、 JPYC 決済なのでカード情報は流通しない)
- **wallet 秘密鍵 / signing secret** (= wallet で署名 → blockchain で verify、
  uniple サーバも知らない)
- **顧客の wallet 内残高** (= chain 上 public information のみ uniple が読める)

### PCI DSS 非保持化の整理
- **そもそもクレジットカード情報を扱わない** ため、 PCI DSS の対象外
- ただし「決済情報の非保持化」 という EC-CUBE 公式ガイドラインの精神に整合する
  ため、 wallet 秘密鍵 / signing secret は加盟店 server を経由しない設計
  (= リダイレクト型 + webhook 型)

## 3. uniple 側の責任分担

| 項目 | 責任 |
|---|---|
| Hosted Checkout UI 提供 | uniple |
| wallet 接続 (= WalletConnect v2 / MetaMask / HashPort) | uniple |
| chain への Tx broadcast + confirm 監視 | uniple |
| webhook 配信 (= 完走通知、 7 attempts / 約 3 日間 retry) | uniple |
| 加盟店向け credentials 発行 (= API key / Webhook secret、 admin UI で self-serve) | uniple |
| KYC (= 加盟店 onboarding) | uniple (= JPYC 株式会社の KYC を上流で活用) |
| AML / トラベルルール対応 | uniple |
| JPYC 残高 / 円転 | uniple (= JPYC EX 経由) |

## 4. 加盟店 (= 本 plugin 利用者) の責任分担

| 項目 | 責任 |
|---|---|
| EC-CUBE サーバ運用 (= HTTPS、 webhook 受信端点) | 加盟店 |
| plugin インストール / 設定 (= API key 投入) | 加盟店 |
| 注文 / 商品管理 | 加盟店 |
| 顧客対応 (= 一次窓口、 必要に応じて uniple サポートにエスカレ) | 加盟店 |
| 自動返金不可のため、 加盟店から購入者へ JPYC 直送で返金対応 | 加盟店 |

## 5. 商標 / プレスキット遵守

- 「JPYC」 は JPYC 株式会社の登録商標、 plugin 内で使用する際は
  presskit (= JPYC Logo Guideline v1.1, 2025-08-12 制定) に準拠
- plugin 設定画面 + README に **必須 3 行免責**を明示済:
  1. 「本サービス／プラグインは JPYC 株式会社による公式コンテンツではありません。」
  2. 「『JPYC』は JPYC 株式会社の提供するステーブルコインです。」
  3. 「JPYC 及び JPYC ロゴは、JPYC 株式会社の登録商標です。」
- plugin 独自アイコン (= JPYC Blue `#16449A` の derivative) を使用、 JPYC ロゴ
  自体は使わない (= プレスキット §6 「ロゴ変形・装飾追加 NG」 遵守)

## 6. EC-CUBE 社との契約で論点になりそうな項目

### 6.1 商務条件
- **販売モデル**: 無料 plugin or 有料 plugin
  (D user 判断事項、 公式ストア手数料との関係も含め決定要)
- **公式ストア手数料**: 有料 plugin の場合の販売手数料率
- **NDA 範囲**: plugin 設計 / 商務条件 / user 数 / 売上 等

### 6.2 技術条件
- **PCI DSS 非保持化対応**: 上記 §2 の決済フロー図で説明可能
- **サンドボックス提供**: 審査担当者向けに `SECRET-sandbox-info.md` で提供
  (= 別途、 D user → EC-CUBE 審査担当者に zip パスワード付きで送付)
- **保守 SLA**: D user (= 株式会社 uniple) でサポート期間 / 一次応答時間を決定

### 6.3 法令 / 規制
- **JPYC = 電子決済手段の説明**: 上記 §1 法令上の重要事項参照
- **加盟店登録不要の根拠**: uniple PSP 介在モデル (= uniple が JPYC EX 経由で
  円転 / 即時決済代行)
- **AML / KYC**: uniple 側で完結 (= JPYC 株式会社の KYC を上流活用、 加盟店は
  別途登録不要)

## 7. 想定スケジュール

| 段階 | 担当 | 想定期間 |
|---|---|---|
| 1. EC-CUBE 営業窓口に問い合わせ | D user | 1-2 営業日 |
| 2. 基本契約条件ヒアリング + NDA | D user / 法務 | 1-2 週間 |
| 3. plugin 側技術審査 (= 自動テスト + 機能審査 + セキュリティ審査) | EC-CUBE 社 | 数日〜数週間 |
| 4. 修正対応 (= 指摘あれば) | plugin dev | 都度数日 |
| 5. 承認 + 公開 | D user (= 公開ボタン押下) | 1 営業日 |

## 8. 同梱資料

D user が EC-CUBE 営業窓口に提示する資料一式:
- 本契約交渉用 briefing (= 本書)
- plugin 概要 (= README.md)
- 統合ガイド (= docs/integration-guide.md §10 実装メモ含む)
- e2e smoke baseline (= docs/e2e-smoke-baseline.md、 MVP 完成 evidence)
- spec シート文案 (= docs/store-submission/spec-sheet.md)
- ロゴ画像 (= docs/store-submission/images/logo-338x252.png)

問い合わせ先 (= EC-CUBE 営業窓口):
- https://www.ec-cube.net/contact/ もしくは
- 公式 FAQ「オーナーズストアにプラグインを公開したい」: https://support.ec-cube.net/hc/ja/articles/360038541792
