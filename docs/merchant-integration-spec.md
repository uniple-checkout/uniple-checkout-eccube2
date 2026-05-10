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

> ℹ️ **経路選択 (= LINE 経由 / WC 直 / 両方) の SSOT は uniple 本体側
> (= MerchantSite.checkoutMode) に一元管理**されています。 加盟店契約と
> 紐づく設定 (= LINE 利用料の請求対象) のため、 plugin 側で経路指定する
> 必要はありません。 経路変更は uniple サポート (= support@uniple.io)
> まで。 詳細は [移行メモ (= Phase 1/2 段階移行)](#移行メモ) 参照。

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

| 言語 | 場所 |
|---|---|
| **PHP** | 本書 §2.6.1 (= plain PHP 最小コード例、 framework 非依存) |
| **Node.js (Express)** | uniple 本体 [merchant-api §11「サンプル統合」](https://uniple.io/docs/merchant-api#11) (= Express 80 行 reference) |
| **Python / Ruby / Go 等** | 将来追加 (= 加盟店ニーズあり次第)、 HMAC-SHA256 + JSON HTTP は標準ライブラリで対応可能 |

### 候補カート

BASE / STORES / makeshop / カラーミー (= 日本国内 SaaS カート)、 独自フルスクラッチ
EC、 等。 SaaS 系は外部 API call 制限の可能性あり、 加盟店側で SaaS 制約を要確認。

## 2.6.1 PHP 最小コード例 (= framework 非依存、 plain PHP)

加盟店カートを **plain PHP** で実装する場合の最小コード例。 `curl` /
`php://input` / `hash_hmac` の 標準関数のみ使用、 composer / Guzzle 等に依存
しない。 加盟店環境 (= EC-CUBE / WordPress / 独自) で composer 利用可能なら、
[既存 EC-CUBE 4 plugin の Service/UnipleClient.php](https://github.com/<owner>/uniple-eccube4-plugin/blob/main/Service/UnipleClient.php)
を読むと Guzzle ベースの fuller 版が見られる。

> ⚠️ 下記コードは **学習・移植用 reference**。 本番運用では加盟店側で例外処理 /
> ログ / 認証情報の保管 (= 環境変数 + 暗号化) / HTTPS 必須化 / DB トランザクション
> を適切に補強すること。

### Step 1: createSession (= uniple session 作成)

```php
<?php
// uniple_create_session.php (= 加盟店「ご注文確定」 button から呼ばれる想定)

const UNIPLE_API_BASE   = 'https://uniple.io';
const UNIPLE_API_KEY    = getenv('UNIPLE_MERCHANT_API_KEY'); // = 環境変数で保管
const MERCHANT_LABEL    = '加盟店表示名 (例: KANA LIVING)';

/**
 * uniple Hosted Checkout session を作成、 checkoutUrl を返す。
 *
 * @param int    $orderId        加盟店内部の注文 ID
 * @param int    $amountJpyc     決済金額 (= JPYC 整数)
 * @param string $itemName       商品名 (= uniple checkout に表示)
 * @param string $successBaseUrl 加盟店 successUrl (= ?cs query を uniple が append)
 * @param string $cancelBaseUrl  加盟店 cancelUrl
 * @param string $webhookUrl     加盟店 webhook 受信 endpoint (= HTTPS 必須)
 * @return array{sessionId: string, checkoutUrl: string}
 * @throws RuntimeException upstream error / network error
 */
function uniple_create_session(
    int $orderId,
    int $amountJpyc,
    string $itemName,
    string $successBaseUrl,
    string $cancelBaseUrl,
    string $webhookUrl
): array {
    $merchantOrderId = sprintf('order-%d-%s', $orderId, bin2hex(random_bytes(4)));

    $body = [
        'amountJpyc'        => (string) $amountJpyc,
        'currency'          => 'JPY',
        'merchantOrderId'   => $merchantOrderId,
        'merchantLabel'     => MERCHANT_LABEL,
        'description'       => $itemName,
        'lineItems'         => [
            ['name' => $itemName, 'quantity' => 1, 'amountJpyc' => $amountJpyc],
        ],
        'successUrl'        => $successBaseUrl,
        'cancelUrl'         => $cancelBaseUrl,
        'webhookUrl'        => $webhookUrl,
        'splitEngine'       => 'v3',
    ];

    $ch = curl_init(UNIPLE_API_BASE . '/api/merchant/checkout/sessions/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . UNIPLE_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $rawResp = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno   = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 || $rawResp === false) {
        throw new RuntimeException('uniple_session_unreachable');
    }

    $payload = json_decode($rawResp, true);
    if ($status !== 200 || empty($payload['ok'])) {
        // 5xx 等は加盟店側で自動 retry しない (= 二重決済 risk)
        throw new RuntimeException('uniple_session_failed: status=' . $status);
    }

    // ⚠️ 加盟店内部 order id ↔ uniple session id の mapping を DB に保存
    // (= 後続 webhook + successUrl 着地で逆引きするため)
    db_save_intent_mapping($orderId, $payload['sessionId'], (string) $amountJpyc);

    return [
        'sessionId'   => $payload['sessionId'],
        'checkoutUrl' => $payload['checkoutUrl'],
    ];
}
```

### Step 2: user redirect (= /checkout/{sessionId}?wc=1)

```php
<?php
// 加盟店「ご注文確定」 ハンドラから呼ぶ

$session = uniple_create_session(
    $orderId       = 123,                                    // 加盟店内部 order id
    $amountJpyc    = 55,                                     // JPYC 整数
    $itemName      = '15cm 両手鍋',
    $successBaseUrl = 'https://merchant.example.com/uniple/return',
    $cancelBaseUrl  = 'https://merchant.example.com/uniple/cancel',
    $webhookUrl     = 'https://merchant.example.com/uniple/webhook'
);

// EC-CUBE 内部 order id を session に保存 (= ?cs fallback でも逆引き可能だが
// session 経路を主とする = Plan P 方式)
session_start();
$_SESSION['uniple_pending_order_id'] = $orderId;

// uniple checkout に redirect
header('Location: ' . $session['checkoutUrl'] . '?wc=1', true, 302);
exit;
```

> ℹ️ **`?wc=1` query について (= compatibility note)**
>
> 上記コードでは互換維持のため `?wc=1` query を付与していますが、
> Phase 1 release (= 2026-05-10) から少なくとも 90 日の移行期間中の
> **legacy 互換措置**です。 経路選択 (= LINE 経由 / WC 直 / 両方) の SSOT
> は uniple 本体側 (= MerchantSite.checkoutMode) で一元管理され、 加盟店
> 側で経路指定する必要はありません。 Phase 2 実施 (= 2026-08-08 以降、
> uniple changelog で告知) 後は plain `$session['checkoutUrl']` への redirect
> に変更されます。 詳細は [移行メモ](#移行メモ) 参照。

### Step 3: webhook receiver (= HMAC-SHA256 検証 + 冪等性確保)

```php
<?php
// uniple_webhook.php (= 加盟店 webhook URL の公開エンドポイント)
// HTTPS 必須、 uniple admin の Webhook 受信 URL に登録した URL

const WEBHOOK_SECRET = getenv('UNIPLE_WEBHOOK_SECRET'); // = 環境変数

// 1. raw body 取得 (= JSON parse 前、 署名検証は raw 必須)
$rawBody   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_UNIPLE_SIGNATURE'] ?? '';

// 2. HMAC-SHA256 検証 (= timing-safe 比較必須)
$provided = preg_replace('/^sha256=/', '', trim($sigHeader));
$expected = hash_hmac('sha256', $rawBody, WEBHOOK_SECRET);
if (strlen($provided) !== strlen($expected) || !hash_equals($expected, $provided)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'invalid_signature']));
}

// 3. payload parse
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'invalid_json']));
}

$event       = $payload['event']                ?? '';
$sessionId   = $payload['data']['sessionId']    ?? '';
$amountJpyc  = $payload['data']['amountJpyc']   ?? '';
$txHash      = $payload['data']['txHash']       ?? '';

// 4. 冪等性確保 (= idempotency_key で同一 event 重複処理を防ぐ)
//    raw body の hash + sessionId + event 種別 を key として DB に保存
$idempotencyKey = $event . ':' . $sessionId;

// 疑似 SQL (= MySQL / PostgreSQL / SQLite 等で同等)
//   CREATE TABLE webhook_log (
//     idempotency_key VARCHAR(255) PRIMARY KEY,
//     event_type      VARCHAR(64),
//     session_id      VARCHAR(64),
//     received_at     DATETIME,
//     processed_at    DATETIME NULL
//   );

if (db_webhook_already_processed($idempotencyKey)) {
    http_response_code(200);
    exit(json_encode(['ok' => true, 'duplicate' => true]));
}
db_record_webhook_received($idempotencyKey, $event, $sessionId);

// 5. event 別処理 (= completed のみ order 完走化)
if ($event === 'checkout.session.completed' || $event === 'checkout.completed') {
    $mapping = db_get_intent_mapping_by_session_id($sessionId);
    if (!$mapping) {
        // mapping 不在 (= demo / test 環境からの混入) → 200 で受領
        http_response_code(200);
        exit(json_encode(['ok' => true, 'note' => 'mapping_not_found']));
    }

    // 金額検証 (= 整数文字列で完全一致)
    if ((string) $amountJpyc !== (string) $mapping['amount_jpyc']) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'amount_mismatch']));
    }

    // order 完走化 (= 加盟店内部の注文 status を「入金済み」 に更新)
    db_mark_order_paid($mapping['order_id'], $sessionId, $txHash);
}

// 6. 処理完了を記録 + 200 で応答
db_record_webhook_processed($idempotencyKey);
http_response_code(200);
exit(json_encode(['ok' => true]));
```

### Step 4: successUrl handler (= user 着地 + 注文完了画面)

```php
<?php
// uniple_return.php (= 加盟店 successUrl の着地点)
// uniple は完走時に ?cs / ?orderId / ?txHash 等を append する

session_start();

// primary lookup: session 経路 (= Plan P 方式)
$ecOrderId  = (int) ($_SESSION['uniple_pending_order_id'] ?? 0);
// fallback: ?cs query (= 別タブ / session 切れの救済)
$unipleCs   = (string) ($_GET['cs'] ?? '');

$mapping = null;
if ($ecOrderId > 0) {
    $mapping = db_get_intent_mapping_by_order_id($ecOrderId);
}
if ($mapping === null && $unipleCs !== '') {
    $mapping = db_get_intent_mapping_by_session_id($unipleCs);
}

// 認可 check (= ログイン顧客の場合は加盟店内部 customer_id 一致確認)
$authorized = true;
if ($mapping && function_exists('current_logged_in_customer_id')) {
    $loginCustomerId = current_logged_in_customer_id();
    if ($loginCustomerId && $mapping['customer_id'] !== $loginCustomerId) {
        $authorized = false;
    }
}

// 完走判定 + 完走画面 redirect
if ($mapping && $mapping['status'] === 'paid' && $authorized) {
    // ✅ 注文完了画面へ
    // 加盟店標準のサンクスページに redirect、 必要なら session に order_id を
    // 復元してテンプレ側で注文番号を表示
    $_SESSION['shopping_complete_order_id'] = $mapping['order_id'];
    header('Location: /shopping/complete', true, 302);
    exit;
}

// ⏳ 完走未到着 / pending 表示
header('Location: /shopping/complete?pending=1', true, 302);
exit;
```

### Step 5: 注意点 (= 実運用で必須)

#### 5.1 認証情報の保管
- `UNIPLE_MERCHANT_API_KEY` / `UNIPLE_WEBHOOK_SECRET` は **環境変数 (= `.env`)
  + サーバ側 secret store** で保管。 ソースコードに直書きしない。
- 加盟店 DB に保存する場合は AES-256-GCM 等で暗号化、 復号鍵は別管理。

#### 5.2 冪等性 (= 同 event 多重弾き)
- webhook 配信は failure 時 7 attempts / 約 3 日間 retry されるため、 同じ event
  が複数回届く可能性あり。
- 上記コードのように `idempotency_key` を DB の UNIQUE 制約で防ぐ実装が canonical。
- in-memory hash や file-based lock は推奨しない (= multi-server 環境で破綻)。

#### 5.3 HTTPS 必須
- webhook 受信 URL は **public な HTTPS endpoint**でなければならない (=
  uniple 側からの配送)。
- Let's Encrypt / Cloudflare 等で証明書取得、 reverse proxy 経由なら
  `X-Forwarded-Proto` 適切設定。

#### 5.4 整合性 (= db transaction)
- `db_mark_order_paid` は order の status 更新と webhook log の processed_at 更新
  を同一トランザクションで行う。 片方だけコミットされる中途半端な状態を避ける。

#### 5.5 自動 retry 非実装
- 加盟店側で uniple API が 5xx を返したからといって**自動 retry しない**。
  upstream で先行 request が遅延処理されて成立した場合に二重決済 risk。
- 復旧経路は user 手動 retry (= cart に戻って再 checkout) が canonical。
- microcopy 例: 「決済システムが一時的にご利用できません。 数分後に再度お試しください。」

## 2.7 LINE 経路統合 (= uniple checkout for LINE)

> ℹ️ **LINE 経路の有効化は加盟店契約時に uniple admin で設定**されます。
> uniple checkout for LINE は LINE 利用料を伴う有料機能のため、 経路選択
> (= `wc_only` / `line_only` / `both`) は MerchantSite.checkoutMode で SSOT
> 1 元管理されます。 加盟店から経路変更を希望される場合は uniple サポート
> (= support@uniple.io) までご連絡ください (= MVP では加盟店 self-serve は
> 未対応)。

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

## 移行メモ

経路選択 SSOT 化の Phase 1/2 段階移行については別 docs に集約しています:
[migration-notes.md](migration-notes.md) を参照ください。

主要トピック:
- Phase 1 (= 2026-05-10 ~) = docs / 案内文 SSOT 化、 内部 `?wc=1` は legacy 互換で残置
- Phase 2 (= 2026-08-08 以降、 最早) = `?wc=1` 完全廃止
- `?wc=1` query 自動付与は Phase 1 release から少なくとも 90 日の互換措置
- 加盟店側の追加対応は Phase 1/2 とも不要

---

<div align="center">
  <strong>© uniple inc.</strong><br>
  <small>JPYC及びJPYCロゴは、JPYC株式会社の登録商標です。</small>
</div>
