# 公式ストア提出用 スクリーンショット撮影台本 (= 8 枚目安、 EC-CUBE 2.x 系)

EC-CUBE 公式ストア提出時に同梱するスクリーンショットの撮影手順と内容
(= EC-CUBE 2 系 admin UI 向け、 4 系版とは UI が異なる)。

## 撮影前の準備

- EC-CUBE 2.17.2-p2 + 本 plugin インストール済み + 有効化済み
- uniple admin で発行済みの credentials を plugin 設定画面に投入済み (= live 確認済)
- テスト商品 1 つ作成済 (= ¥50-100 程度の低額)
- 本会員顧客 1 つ作成済
- スマホ + HashPort wallet (= cross-device smoke 用、 7 枚目で使用)

## 撮影シーン

### 01. plugin 設定画面 (= admin)
- URL: `/admin/load_plugin_config.php?plugin_id=<UnipleJpyc plugin id>`
- キャプチャ対象:
  - 「⚖️ JPYC の法令上の分類」 alert (= 電子決済手段 + 加盟店登録不要)
  - 「⚠️ 返金について」 alert
  - API 接続情報 form (= API key / Webhook secret 入力欄、 値は伏字)
  - Webhook 受信 URL 表示 (= 加盟店が uniple 側に登録する URL)
  - **免責表記** (= presskit 3 行)

### 02. 商品ページ (= 顧客 view)
- URL: `/products/detail.php?product_id=<id>`
- キャプチャ対象: 商品詳細 + 「カートに入れる」 ボタン

### 03. 注文確認画面 (= shopping payment)
- URL: `/shopping/payment.php`
- キャプチャ対象: 「お支払い方法」 一覧で **「uniple JPYC ウォレット」** が
  選択可能なこと、 説明文「JPYC（日本円ステーブルコイン、電子決済手段）で
  お支払いいただけます。」 が表示

### 04. uniple Hosted Checkout 着地画面
- URL: `https://uniple.io/checkout/ucs_<sessionId>`
- キャプチャ対象: 「ウォレットを接続して支払う」 ボタン + 加盟店表示名 + 金額

### 05. wallet 接続完了 + 支払い実行画面 (= uniple Hosted Checkout 内)
- キャプチャ対象: wallet 接続済 + 残高表示 + 「支払う」 ボタン

### 06. EC-CUBE サンクスページ (= /shopping/complete.php)
- URL: `/shopping/complete.php`
- キャプチャ対象: 「ご注文完了」 + 注文番号

### 07. cross-device 完走画面 (= スマホ + PC 並列)
- スマホ画面: uniple Hosted Checkout の「✅ 決済が完了しました / PC のブラウザに
  戻ってご確認ください / 注文番号」 表示 (= JPYC 青チェック)
- PC 画面: polling 完走後の EC-CUBE 2 サンクスページ自動遷移
- → 1 枚にコラージュ

### 08. 管理画面 注文詳細
- URL: `/admin/order/edit.php?order_id=<id>`
- キャプチャ対象:
  - 注文ステータス: **「入金済み」** (= ORDER_PRE_END status_id=6)
  - お支払い方法: **「uniple JPYC ウォレット」**
  - 注文日時 / 入金日時 (= webhook 経由で同期)

## 撮影後

- 全 8 枚を `docs/store-submission/screenshots/` 配下に保存
- 個人情報 (= API key, customer email, wallet address) は伏字加工
- 解像度: 1280x800 以上 PNG

## EC-CUBE 4 系 plugin との差分

| 項目 | EC-CUBE 4 | EC-CUBE 2 |
|---|---|---|
| 設定画面 URL | `/admin/store/plugin` から | `/admin/load_plugin_config.php?plugin_id=N` |
| 注文確認 URL | `/shopping` | `/shopping/payment.php` |
| サンクスページ | `/shopping/complete` | `/shopping/complete.php` |
| 管理画面 注文詳細 | `/admin/order/<id>/edit` | `/admin/order/edit.php?order_id=<id>` |
| 設定画面 UI | Symfony admin (= bootstrap) | レガシー Smarty テンプレ |

## 撮影所要時間目安

- 4 系版と同じ: 計 2-3 時間
