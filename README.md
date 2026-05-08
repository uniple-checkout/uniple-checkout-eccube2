# uniple Checkout for EC-CUBE 2.x (β)

EC-CUBE 2 系加盟店向け、uniple checkout 決済プラグイン。

**日本円ステーブルコイン JPYC** (= 資金決済法第 2 条第 5 項に基づく電子決済手段、JPYC 株式会社発行 / 関東財務局長第 00099 号 資金移動業者) による決済を、加盟店カートで「決済方法選択肢の 1 つ」として組込む。Stripe Checkout 互換の Hosted Checkout 経路で動作、wallet は HashPort / MetaMask / WalletConnect 各種対応。

> ⚖️ **法令上の分類**: JPYC は **電子決済手段** (= 資金移動業型ステーブルコイン、1 JPYC = 1 円で発行・償還)。**暗号資産ではありません**。
>
> 加盟店側は **uniple が PSP として介在する設計のため、電子決済手段等取引業 (資金決済法第 2 条第 10 項) の登録は不要**です。

## 対応 EC-CUBE バージョン

- **EC-CUBE 2.17.2-p2** (= 推奨、MVP 開発検証バージョン)
- EC-CUBE 2.25.0 (= CI で同時 confirm 予定)
- 4 系版は別 plugin (= `app/Plugin/UnipleJpyc`、別 GitHub repo) で公開済

## 動作要件

| 項目 | 推奨 |
|---|---|
| EC-CUBE | 2.17.2-p2 / 2.25.0 |
| PHP | 8.0+ (= 2.17.2-p2 公式対応) |
| DB | MariaDB 10.6+ / MySQL 8.0+ |
| Web | Nginx + PHP-FPM (本番)、`php -S` (開発) |
| HTTPS | 必須 (= webhook 受信) |

## クイックスタート

```bash
# 1. plugin を data/downloads/plugin/UnipleJpyc/ に展開
cd /var/www/eccube2/data/downloads/plugin/
git clone https://github.com/<owner>/uniple-eccube2-plugin.git UnipleJpyc

# 2. html/plugin/UnipleJpyc/ に webhook.php を配置 (= 公式 plugin install 経由なら自動)
mkdir -p /var/www/eccube2/html/plugin/UnipleJpyc/
cp -R /var/www/eccube2/data/downloads/plugin/UnipleJpyc/html_plugin/UnipleJpyc/* \
      /var/www/eccube2/html/plugin/UnipleJpyc/

# 3. EC-CUBE 管理画面 → オーナーズストア > プラグイン管理 から
#    UnipleJpyc を install + 有効化 (= UnipleJpyc::install() が sql/install.sql 実行)

# 4. 管理画面 → プラグイン設定 → uniple Checkout で
#    apiKey + webhookSecret + merchantLabel + apiBaseUrl + mode を入力
```

## 開発時 (= 直接 SQL で table 作成 + Config 初期化)

公式 install フロー経由でなく開発時に直接 smoke する場合:

```bash
# 1. 上記 step 1-2 と同じ
# 2. SQL 直接実行
mysql -uXXX -pXXX eccube_db < /var/www/eccube2/data/downloads/plugin/UnipleJpyc/sql/install.sql
# 3. plg_uniple_jpyc_config の row id=1 に値を直接 UPDATE で設定
```

## ⚠️ 必須: presskit 準拠 3 行免責表記

JPYC 株式会社の presskit (= JPYC Logo Guideline v1.1, 2025.08.12) ガイドラインに準拠するため、**plugin 設定画面 + 本サービス利用画面に以下 3 行を必須記載**:

1. 「本サービス／プラグインは JPYC 株式会社による公式コンテンツではありません。」
2. 「『JPYC』は JPYC 株式会社の提供するステーブルコインです。」
3. 「JPYC 及び JPYC ロゴは、JPYC 株式会社の登録商標です。」

## ドキュメント

- uniple 加盟店 API spec: `https://uniple.io/docs/merchant-api`
- 4 系 plugin (= 流用元 reference): https://github.com/<owner>/uniple-eccube4-plugin

## 既知の制約 (MVP)

- 自動返金未対応 (= 加盟店から購入者へ JPYC 直送)
- LINE 経由 (`/api/intent`) は MVP 後の追加対応
- ASP / multi-tenant 拡張は別 phase
- HashPort + Android 16 + LIFF IAB の特殊条件で setup 初回 signing が稀に失敗

## 実装メモ (= return / cart / cross-device の運用仕様)

加盟店 onboarding および公式ストア審査向けの設計判断記録。

### 経路の独立性 (= cross-over fallback 禁止)

uniple は 3 経路をサポート: **LINE 経由 (`/lq/*`) / WC 直 (`?wc=1`) / autopay
opt-in (`?autopay=1`)**。 各経路は完全に独立した UX flow であり、 plugin / uniple
ともに **経路をまたぐ自動 fallback は実装しない**。 LINE 完走失敗時に WC 直に
勝手に切り替える、 autopay setup 失敗時に通常経路に戻す 等は禁止。 加盟店向け
独自実装でも同原則を維持すること。

### successUrl 仕様 (= Plan P)

plugin は uniple の `successUrl` に **plain URL** を渡す:

```
https://merchant.example/plugin/UnipleJpyc/return.php
```

uniple は完走時に下記 query を append する:

| query key | 内容 |
|---|---|
| `?cs` | uniple Checkout Session ID (= `ucs_...`) |
| `?orderId` | uniple 側注文 ID (= `pay-sp_v3_...`、 plugin の EC-CUBE 内部 ID とは別物) |
| `?txHash` | on-chain Tx hash |
| `?merchantOrderId` | session 作成時の clientReferenceId |
| `?payId` | uniple 支払い ID (= `sp_v3_...`) |

plugin は EC-CUBE 内部 order id を `$_SESSION['uniple_jpyc_pending_order_id']`
で session 経路で渡し、 return.php で復元する (= ?orderId が uniple 側 ID で
上書きされる衝突を回避)。 return URL に literal placeholder
(= `{CHECKOUT_SESSION_ID}` 等) を埋め込まないこと。

### 完走 return.php の責務

`/plugin/UnipleJpyc/return.php` 着地時に plugin は下記を冪等に処理する:

1. **mapping lookup** = primary は `$_SESSION['uniple_jpyc_pending_order_id']`、 fallback は `?cs` query
2. **認可 check** = ログイン顧客の場合は `dtb_order.customer_id` 一致確認
3. **cart purge** = `SC_CartSession_Ex::delAllProducts($cartKey)` で cart を空に
4. **session 復元** = `$_SESSION['order_id'] = $orderId` (= LC_Page_Shopping_Complete
   が注文番号表示に使用、 標準コードに「プラグインなどで order_id を取得する場合
   がある」 コメント明記あり)

uniple は successUrl を **2 回連続 GET する観測あり** (= 1 回目 user 着地、
2 回目 bot preload / browser preload 等)。 `pending_order_id` は完走 redirect 後
**keep する** (= 2 回目 hit でも mapping completed 判定が成立する)。
次回購入時に payment.php が新 order id で上書きするので残しても安全。
認可 fail 時のみ unset する。

EC-CUBE 2 系は dtb_cart テーブルが無く cart は session のみで管理されるため、
cross-device で session 跨いでも干渉せず cart 残留 race の発生条件にならない
(= 4 系 plugin で必要な customer_id ベース全 cart 物理 DELETE は 2 系では不要)。

### cross-device 完走 (= uniple Plan U1)

uniple Hosted Checkout は **mobile UA + handoff=qr marker** を検出した場合、
スマホ側で「✅ 決済が完了しました / PC のブラウザに戻ってご確認ください」
画面を表示し、 successUrl への遷移を skip する。 PC 側は polling で完走検知
→ successUrl に自動遷移する。

= cross-device (= PC 起点 → スマホ QR スキャン → スマホで wallet 完走) でも、
PC のサンクスページに自動到達し PC の login session が維持される。 業界標準
(= PayPay, Stripe, WalletConnect の dapp/wallet device 分離モデル) と整合。

### 2.17 系特有: MODULE_REALDIR の罠

EC-CUBE 2.17 系 (= Composer 化版) では `MODULE_REALDIR` が
**`/var/www/eccube2/data/downloads/module/`** に変更されている (= 旧
`/var/www/eccube2/data/module/` ではない)。 LC_Page_Shopping_LoadPaymentModule は
`dtb_payment.module_path` を MODULE_REALDIR 配下に強制する仕様のため、 plugin は
**「本体は plugin 配下、 MODULE_REALDIR には本体を require するだけの shim を
配置」** という 2 段構成で実装している。 詳細は `module/payment_shim.php` 参照。

### uniple API 一時障害時の挙動 + 自動 retry 非実装の判断

UnipleJpyc_Client::createSession で uniple API endpoint が 4xx / 5xx を返した
場合 (= rare、 transient)、 plugin は Exception を throw し module/payment.php
側で catch して EC-CUBE 標準 `SHOPPING_ERROR_URLPATH` (= `/shopping/error.php`)
にリダイレクトする。 在庫 / 注文 record / mapping / webhook いずれも触らない
defensive 動作で、 整合性は保たれる。

**plugin 側で自動 retry を実装しない判断:**

uniple API の単発失敗 (= 5xx / timeout) 時に plugin が自動 retry すると、
upstream 側で先行 request が遅延処理されて成立した場合に **二重 session 作成 →
二重決済 risk** が発生する。 user 同意なしの自動 retry は payment plugin の
原則として禁止 (= EC-CUBE 標準 / Stripe / PayPay 各社 plugin の慣行とも整合)。

= **canonical な復旧経路は user 手動 retry** (= cart に戻って再 checkout)。
EC-CUBE 標準の error 画面 + 加盟店側の microcopy で「数分後に再度お試しください」
と案内する。

加盟店側が独自に retry layer を実装したい場合、 uniple Merchant API は
`clientReferenceId` を idempotency 鍵として尊重する仕様 (= 同 clientReferenceId
の重複 POST は同 session を返す) があるため、 同 key で safe に retry 可能。
ただし plugin MVP scope には含めない。

## 責任分界

本 plugin は **uniple 本体 (= 別法人が運営する PSP インフラ) の Merchant API
を呼び出すクライアント**として動作します。 plugin が独自に決済処理を行うわけ
ではなく、 決済 / 円転 / KYC / on-chain Tx 等は uniple 本体側で完結します。

障害時 SLA / status 通知は uniple 本体 status page (= https://uniple.io) を
参照、 plugin は独自 SLA 保証しません。

### 加盟店 secret の管理境界

- **発行**: uniple 本体 `/admin/merchants/` の加盟店設定画面で self-serve 発行
- **保管**: 加盟店側で plugin の設定画面 (= `plg_uniple_jpyc_config` table) に
  投入

> ⚠️ **重要: 平文 secret は発行直後の section でのみ表示されます**
>
> uniple admin `/admin/merchants/` で API key / Webhook secret を発行すると、
> **画面下部の section に平文で 1 度だけ表示**されます。 **必ず発行直後にコピー**
> し、 安全な場所に保管してください。 後から再表示することはできません。

## ライセンス

GPL (= EC-CUBE 2.x 標準ライセンス互換)

---

<div align="center">
  <strong>© uniple inc.</strong><br>
  <small>JPYC及びJPYCロゴは、JPYC株式会社の登録商標です。</small>
</div>
