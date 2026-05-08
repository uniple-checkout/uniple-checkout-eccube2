# uniple Merchant Integration Spec (= 加盟店向け導入仕様書)

加盟店が任意のカートシステムに **uniple checkout** および **uniple checkout
for LINE** を組み込むための統合仕様書。 plugin docs を入口、 Merchant API 本体
仕様は uniple 本体 docs (= [merchant-api](https://uniple.io/docs/merchant-api))
を SSOT として参照する 2 段構成。

> 📍 **本書の位置付け**
>
> - 上位文書: 本書 (= カート非依存の汎用 spec)
> - 下位文書: カート別 reference 実装 (= EC-CUBE 4/2 plugin docs、 将来 WP / Shopify)
> - SSOT: uniple 本体 [merchant-api](https://uniple.io/docs/merchant-api) (= API endpoint 仕様)

## サービスブランド体系

| サービス名 | 経路 | 完走場所 |
|---|---|---|
| **uniple checkout** | JPYC ウォレット直結 (= WC 直 / autopay) | uniple Hosted Checkout 画面 (ブラウザ) |
| **uniple checkout for LINE** | LINE トーク経由 | LINE app 内のトーク画面 |

両者は加盟店側で **並列に提供**することも、 **片方のみ採用**することも可能。

---

# Part 1: 共通 spec (= 全カート共通)

## 1.1 認証

### Merchant API Key (= Bearer)
- 形式: `ums_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` (= 64 hex)
- 用途: uniple Merchant API へのリクエスト認証
- 発行: uniple admin `/admin/merchants/` で加盟店ごとに self-serve 発行
- 保管: 加盟店側カートの設定 / DB に暗号化保管推奨
- 有効期限: 無期限 (= rotation は admin UI で旧 key 即時無効化 + 新 key 発行)

### Webhook Signing Secret
- 形式: 64 hex (= `b20e8fd0737c0700680fe0ddc64ef926cfbbe6533772e365cce18da5fdd8e50a` 風)
- 用途: webhook ペイロードの HMAC-SHA256 署名検証
- header: `X-Uniple-Signature: sha256=<hex>`
- 計算: `hash_hmac('sha256', $rawBody, $webhookSecret)` (= raw body、 JSON parse 前)
- 比較: `hash_equals($expected, $provided)` で timing-safe

> ⚠️ **重要: 平文 secret は発行直後の section でのみ表示**
>
> uniple admin で API key / Webhook secret を発行すると、 **画面下部に平文で 1 度
> だけ表示**される。 必ず発行直後にコピー、 安全な場所に保管。 後から再表示不可。

## 1.2 経路選択

### uniple checkout (= ウォレット直結)

```
[加盟店カート 注文確定]
  ↓
[加盟店 server: POST https://uniple.io/api/merchant/checkout/sessions]
  ↓ checkoutUrl 取得
  ↓
[user を https://uniple.io/checkout/{sessionId}?wc=1 に redirect]
  ↓
[uniple Hosted Checkout で wallet 接続 + 署名 + 完走]
  ↓
[加盟店 webhook 受信 → order を完走化]
  ↓
[user を successUrl に着地 → サンクスページ表示]
```

### uniple checkout for LINE (= LINE トーク経由)

```
[加盟店カート 注文確定]
  ↓
[加盟店 server: POST https://uniple.io/api/intent]
  ↓ /lq/cs/{ucs} URL 取得
  ↓
[user を /lq/cs/{ucs} に redirect (= LINE app 起動)]
  ↓
[LINE トークで「決済へ進む」 / 「キャンセル」 メッセージ受信]
  ↓ user が LINE トーク内で 1 タップ完走
  ↓
[加盟店 webhook 受信 → order を完走化]
  ↓
[LINE トークに完了通知メッセージ届く]
```

加盟店は **LINE OA (= 公式アカウント) と LINE Login channel を運用済み** が
前提条件。 詳細は [Part 2.7](#27-line-経路統合-uniple-checkout-for-line) 参照。

### 経路選択 UX 例 (= demo merchant)

加盟店の商品ページから 2 ボタンで明示する例:

```html
<a class="cta" data-engine="line">LINE で買う (uniple checkout for LINE)</a>
<a class="cta cta-secondary" data-engine="wc">JPYC ウォレットで買う</a>
```

EC 標準決済画面に「**uniple checkout**」 単一の選択肢として並べる例:

```html
<label><input type="radio" value="uniple" />
  uniple checkout (= 内部で LINE / WC 経路選択)
</label>
```

## 1.3 webhook event spec

### 受信エンドポイント (= 加盟店側で実装)
加盟店 server に POST で配信される。 加盟店側で:

- raw body 取得 (= JSON parse 前)
- `X-Uniple-Signature` header の HMAC-SHA256 検証
- 冪等性確保 (= 同 event を複数回受信しても order は 1 回だけ完走化)

### event 種類

| event | 意味 | order 状態 |
|---|---|---|
| `checkout.session.completed` | 完走 (= 入金確定) | 入金済みに status 更新 |
| `checkout.session.canceled` | user キャンセル | キャンセル化 |
| `checkout.session.expired` | TTL 経過 | キャンセル化 |
| `checkout.session.failed` | 完走失敗 | キャンセル化 |

### payload 構造 (= 抜粋)

```json
{
  "event": "checkout.session.completed",
  "data": {
    "sessionId": "ucs_xxx",
    "merchantOrderId": "<加盟店内部 order id>",
    "amountJpyc": "55",
    "txHash": "0x...",
    "payId": "sp_v3_..."
  }
}
```

詳細は uniple 本体 [merchant-api §webhook](https://uniple.io/docs/merchant-api)
参照 (= SSOT、 仕様変更があれば本書も追従)。

### 配送 retry
配送失敗時 (= 加盟店 server の non-2xx 応答 / timeout) は **7 attempts / 約 3 日間**
で retry: 1m → 5m → 30m → 2h → 6h → 24h → 48h。 加盟店 server は冪等処理必須。

## 1.4 決済フロー (= PCI DSS 非保持化の根拠)

### 加盟店 ⇄ 顧客 ⇄ uniple ⇄ blockchain の責任境界

```
[顧客ブラウザ] ─→ [加盟店 server]                   [uniple Hosted Checkout]                [blockchain]
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

### 加盟店 server を通過しないデータ
- **クレジットカード情報** (= JPYC 決済のためカード情報そのものが発生しない)
- **wallet 秘密鍵 / signing secret** (= wallet で署名 → blockchain で verify)

### PCI DSS 非保持化
- そもそもクレジットカード情報を扱わないため PCI DSS 対象外
- ただし「決済情報の非保持化」 ガイドラインの精神に整合 (= リダイレクト型 + webhook 型)

## 1.5 法令準拠

### JPYC = 電子決済手段
- 法令: 資金決済法第 2 条第 5 項に基づく**電子決済手段**
- 発行: JPYC 株式会社 (= 関東財務局長第 00099 号 資金移動業者)
- 1 JPYC = 1 円で発行・償還
- **「暗号資産」 ではない** (= 法令上明確に区別、 表記禁止)

### 加盟店の登録要否
- uniple が PSP として介在する設計のため、 **加盟店の電子決済手段等取引業
  (資金決済法第 2 条第 10 項) 登録は不要**

### 表記ルール
| 文脈 | 推奨表記 | NG 表記 |
|---|---|---|
| 加盟店向け | 「日本円ステーブルコイン (電子決済手段) 決済」 | 「暗号資産決済」 |
| 法令文脈 | 「資金決済法上の電子決済手段」 | 「仮想通貨」「暗号資産」「資金決済手段」 |

### presskit 必須 3 行免責表記
JPYC 株式会社の Logo Guideline (= v1.1, 2025-08-12 制定) に準拠するため、 加盟店
の決済画面 + 設定 UI に**必ず**:

1. 「本サービス／プラグインは JPYC 株式会社による公式コンテンツではありません。」
2. 「『JPYC』は JPYC 株式会社の提供するステーブルコインです。」
3. 「JPYC 及び JPYC ロゴは、JPYC 株式会社の登録商標です。」

### ブランドカラー
- JPYC Blue: `#16449A` (= primary)
- ⚠️ `#E3AD17` (ゴールド) は **JPYC Prepaid (別ブランド)**、 使わない
- ガイドライン §6: ロゴ変形・装飾追加 NG、 UI accent としての brand color 使用は OK

## 1.6 責任分界

| 領域 | 責任主体 |
|---|---|
| Hosted Checkout UI / wallet 接続 / 署名検証 / on-chain Tx broadcast | uniple 本体 |
| Merchant API (= createSession / webhook 配信) | uniple 本体 |
| 加盟店向け credentials 発行 | uniple 本体 (= /admin/merchants/ で self-serve) |
| 障害時 SLA / status 通知 | uniple 本体 (= status page 参照、 加盟店は独自 SLA 保証しない) |
| KYC / AML / トラベルルール | uniple 本体 (= JPYC 株式会社の KYC を上流活用) |
| 加盟店 server 運用 (= HTTPS / webhook 受信端点) | 加盟店 |
| 注文 / 商品管理 | 加盟店 |
| 顧客対応 一次窓口 | 加盟店 |
| 自動返金未対応 → 加盟店から購入者へ JPYC 直送で対応 | 加盟店 |

---

# Part 2: カート別 reference 実装

## 2.1 EC-CUBE 4.x (= 4.3.x / 4.2.x)

公式 plugin 提供:
- repo: `app/Plugin/UnipleJpyc/`
- 詳細: [docs/integration-guide.md](./integration-guide.md)
- インストール: 公式オーナーズストア (= 審査中) または tar.gz アップロード
- 対応経路: **uniple checkout (= JPYC ウォレット直結)** ✅、 LINE 経路は MVP 後

## 2.2 EC-CUBE 2.x (= 2.17.2-p2 / 2.25.0)

別 plugin 提供:
- repo: `data/downloads/plugin/UnipleJpyc/`
- 詳細: [README.md (= 2 系版)](https://github.com/<owner>/uniple-eccube2-plugin/blob/main/README.md)
- 注意: 2.17 系 MODULE_REALDIR は `/data/downloads/module/` (= 旧 `/data/module/` ではない)
- 対応経路: **uniple checkout** ✅、 LINE 経路は MVP 後

## 2.3 EC-CUBE 4.0.x (= bambina.me 等の旧バージョン)

⚠️ **現 plugin は 4.0 系で動作しない**:
- 4.0 系: Symfony 3.x / 4.x、 Doctrine 2.5/2.6、 plugin-installer 0.0.x
- 4.3 系 (= 現 plugin): Symfony 6.4、 Doctrine 後期版、 plugin-installer ^2.0
- 互換 layer は事実上書き直し (= Symfony 3 / 6 で大幅 breaking change)

加盟店向け推奨:
1. **EC-CUBE 4.3 へのアップグレード** (= 公式推奨パス、 セキュリティ的にも望ましい)
2. または「**4.0 系対応版を別途開発**」 (= ニーズ大なら別 repo で対応版 plugin 開発、 1-2 週間目安)

## 2.4 WordPress / WooCommerce

🟡 **未実装** (= 仕様のみ、 将来対応):

WC は「**Custom Payment Gateway**」 機構を提供:
- `WC_Payment_Gateway` を継承した PHP class 1 つで決済方式を追加可能
- 規模感: 1-2 週間で MVP plugin 開発可能 (= 独立 repo `uniple-checkout-woocommerce` 想定)

加盟店ニーズあり次第、 別途開発予定。 仕様要点:
- WP 標準 hook (= `woocommerce_thankyou` 等) で完走判定
- WP REST API でカスタム webhook endpoint 追加
- 既存 [Part 1](#part-1-共通-spec--全カート共通) の認証 / 経路選択 / webhook spec をそのまま使える

## 2.5 Shopify

🟡 **未実装** (= 仕様のみ、 将来対応):

Shopify は「**Shopify Payment App**」 で App Store 提出が標準。 審査基準厳しい。

代替案:
- **Manual Payment 経由 + uniple webhook で order を完走化** (= 加盟店向け簡易統合)
- 規模感: 公式 App Store 提出 1-2 ヶ月、 Manual Payment 経由なら 1-2 週間

## 2.6 独自カート (= 一般化された統合手順)

API spec を満たせば任意のカートシステムで実装可能。 必要な実装:
1. Merchant API call (= `POST /api/merchant/checkout/sessions` で session 作成)
2. user redirect (= `/checkout/{sessionId}?wc=1` または LINE 経路)
3. webhook receiver (= HMAC-SHA256 検証 + 冪等処理 + order 完走化)
4. successUrl / cancelUrl handler

### 言語別 reference 実装

**PHP** (= 段階 2 で本書に最小コード例追加予定):
- 加盟店候補が EC-CUBE 系 / WordPress 系で PHP 主体のため、 PHP の最小コード例
  を本書 (= plugin Spec) で整備
- 既存 EC-CUBE 4 plugin の `Service/UnipleClient.php` (= API client) と
  `Controller/UnipleWebhookController.php` (= webhook 検証) を抜粋して汎用化
- 段階 2 commit で追加

**Node.js (Express)** (= 既に uniple 本体 docs にあり):
- uniple 本体 [merchant-api §11「サンプル統合」](https://uniple.io/docs/merchant-api#11)
  に Express 80 行程度の reference 実装あり (= sessions 作成 → checkoutUrl
  redirect → success return → webhook 検証)
- 重複コピーを避け、 本書では link 参照のみ

**Python / Ruby / Go 等** (= 将来):
- 加盟店ニーズあり次第、 段階 3 以降で順次追加
- HMAC-SHA256 + JSON HTTP は標準ライブラリで対応可能、 各言語で同等実装可能

### 候補カート

BASE / STORES / makeshop / カラーミー (= 日本国内 SaaS カート)、 独自フルスクラッチ
EC、 等。 SaaS 系は外部 API call 制限の可能性あり、 加盟店側で SaaS 制約を要確認。

## 2.7 LINE 経路統合 (= uniple checkout for LINE)

### Phase 1 (= 現在 live、 MVP)

uniple 共通 LINE OA (= 1 つの uniple 公式アカウント) を全加盟店共通で使用。
加盟店ごとの独自 LINE OA 関連付けは uniple 担当者が admin UI 経由で内部運用、
**加盟店 self-service onboarding は未対応**。

= 加盟店向け = 「LINE 経路を有効化したい」 旨を uniple サポート (= support@uniple.io)
に連絡 → uniple 側で関連付け処理。 channel ID / channel secret 等の入力は加盟店側
で不要。

### Phase 2 (= 未着手、 リリース時期未定)

加盟店ごとの独自 LINE OA を加盟店 self-service で関連付ける UI / API 整備予定。
MerchantSite schema には Phase 2 用の field (= `lineOaOfficialId` /
`lineOaChannelId` / `lineOaChannelAccessTokenHash` / `lineOaChannelSecretHash`) が
既に定義済み、 実装接続待ち。

Phase 2 リリース後は加盟店が自社 LINE OA を直接紐付け可能になり、 顧客との
LINE トーク完走画面が加盟店ブランドで表示される。

### 加盟店観点での接続仕様

1. **uniple 担当者 (Phase 1) または加盟店 self-service (Phase 2)** で LINE OA を
   関連付け
2. 加盟店 server: `POST https://uniple.io/api/intent` で intent 作成
3. user を `/lq/cs/{ucs}` URL に redirect (= LINE app 起動)
4. LINE トーク内で完走 → webhook 受信 (= 通常経路と同じ event spec)
5. LINE トーク内で完了通知メッセージ届く

### API 仕様 SSOT

LINE 経路の `/api/intent` 挙動 (= talkIngressUrl / pcCheckoutUrl /
lineOaMessageUrl / launchUrl) と経路使い分けは uniple 本体
[merchant-api §6.5](https://uniple.io/docs/merchant-api#65) で全部公開済 (=
SSOT)。 加盟店向けには §6.5 と §6.5.1 (= 経路使い分け) で十分。

LINE bot 内部仕様 (= 確認カード template / push trigger / 完了通知の内部実装) は
uniple 内部 docs に閉じ、 加盟店向け公開は当面なし。

### demo merchant 実装例 (= reference)

`https://checkout.uniple.io/demo-merchant/ec-payment.html` で動作確認可能:
- back-end: `POST /demo-merchant/api/checkout` → uniple `/api/intent`
- webhook: `POST /demo-ec/api/webhook/uniple`

---

# Part 3: 加盟店 onboarding

## 3.1 credentials 発行

1. uniple admin (= `/admin/merchants/`) にログイン
2. 加盟店設定 → 「**新規 API key 発行**」
3. **平文 secret は発行直後の section に 1 度だけ表示**、 必ずコピー
4. 加盟店カートの plugin / コード設定に投入

## 3.2 URL 登録

uniple admin の加盟店設定で 3 つの URL を登録:

| URL 種類 | 値 (= 例) |
|---|---|
| Webhook 受信 URL | `https://merchant.example.com/uniple/webhook` |
| Allowed Success URL | `https://merchant.example.com/uniple/return` |
| Allowed Cancel URL | `https://merchant.example.com/uniple/cancel` |

EC-CUBE plugin 利用時は plugin 設定画面に表示される URL をそのまま登録。

## 3.3 開発環境 / 本番切替え

uniple は **test mode endpoint を持たない**ため、 開発環境でも実 JPYC 決済が
走る。 動作確認用の最小金額 (= 50 JPYC 程度) で smoke 推奨。

## 3.4 トラブルシューティング

### webhook が届かない
- 加盟店 server が HTTPS で公開されているか
- TRUSTED_PROXIES 設定 (= reverse proxy 経由 HTTPS の場合)
- 加盟店 server が non-2xx 応答していないか (= retry されるが詰まる)
- uniple admin で webhook URL が正しく登録されているか

### 注文が PAID にならない
- webhook 配信は届いているが署名検証で reject されている可能性
- HMAC-SHA256 計算: raw body + secret で `hash_hmac('sha256', body, secret)`
- timing-safe 比較: `hash_equals($expected, $provided)`

### 金額不一致 (`amount_mismatch` ログ)
- 加盟店側で session 作成時に渡した `amountJpyc` と、 webhook payload の
  `amountJpyc` が一致するか確認 (= 整数文字列で完全一致が必須)

### uniple API 一時障害 (= 5xx)
- transient エラー、 加盟店側で自動 retry **しない** (= 二重決済 risk)
- canonical な復旧経路は user 手動 retry (= cart に戻って再 checkout)
- 加盟店向け microcopy 例: 「決済システムが一時的にご利用できません。 数分後に再度お試しください。」

### HashPort で「接続が不安定です」 等の signing 不調
HashPort wallet で連続購入 / 時間経過後 / 別 wallet 切替後の setup で signing
失敗する場合 (= `permit2_sign_error` / connector idle / app 内 modal 重複)、
**HashPort アプリで一度ログアウト → 再ログイン**を user に案内する (= WC v2
daemon の clean reset、 多くのケースで完走)。

dapp 側で WC v2 protocol 標準対策 (= AppKit dispose / retry 抑止 / localStorage
clear) は uniple 本体で実施済みだが、 HashPort app 内 daemon までは触れないため、
user 側手動操作が必要。

加盟店向け microcopy 例:
> 「ウォレット接続が不安定です。 HashPort アプリで一度ログアウトしてから
> 再ログインの上、 もう一度「支払う」 をお試しください。」

### HashPort 古いウォレットで setup 不可
HashPort リリース直後に作成された古い wallet で **EIP-7702 化** されたものは
Permit2 EIP-1271 検証で revert する既知症状あり (= uniple 側救済不可、 影響
user 数少)。 加盟店経由でこの種の user が来た場合は **HashPort アプリ内で新規
wallet 作成**を案内する。

詳細は EC-CUBE 4 plugin の [docs/integration-guide.md §8 トラブルシューティング](./integration-guide.md#8-トラブルシューティング)
+ [§9 既知の制約](./integration-guide.md#9-既知の制約-mvp) 参照
(= 同じ知見が他カートでも使える)。

---

# サポート

- uniple Merchant API spec: https://uniple.io/docs/merchant-api
- 加盟店サポート窓口: support@uniple.io
- 公式 Web サイト: https://uniple.io

---

<div align="center">
  <strong>© uniple inc.</strong><br>
  <small>JPYC及びJPYCロゴは、JPYC株式会社の登録商標です。</small>
</div>
