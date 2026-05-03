# uniple JPYC Checkout for EC-CUBE 2.x (β)

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

# 4. 管理画面 → プラグイン設定 → uniple JPYC Checkout で
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

## ライセンス

GPL (= EC-CUBE 2.x 標準ライセンス互換)
