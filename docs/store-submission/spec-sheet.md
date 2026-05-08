# 公式ストア提出 spec シート 文案

EC-CUBE 4 公式ストア (= eccube.co.jp/products/) 申請フォームに記入する内容
の文案。 D user (= 株式会社 uniple 法人代表) が公式ストア管理画面で記入する
際にコピペで使える形。

## 共通 template

### プラグイン名
```
uniple checkout
```

### サブタイトル / キャッチ
```
日本円ステーブルコイン JPYC (電子決済手段) によるカート決済プラグイン
```

### カテゴリ
```
決済
```

### 説明文 (= 200-400 字、 加盟店向け要約)
```
日本円ステーブルコイン JPYC (= 資金決済法第 2 条第 5 項に基づく電子決済手段、
JPYC 株式会社発行) によるカート決済を、 uniple PSP 経由で組み込みます。

uniple が PSP として介在する設計のため、 加盟店の電子決済手段等取引業 (資金
決済法第 2 条第 10 項) 登録は不要。 wallet は HashPort / MetaMask /
WalletConnect 各種対応。

決済経路:
- WC 直 (= ?wc=1): PC ブラウザ + 拡張 wallet
- cross-device QR: PC で QR 表示 → スマホ wallet で署名 → PC が完走検知 (=
  PayPay / Stripe 同 pattern)
- autopay opt-in (= ?autopay=1): 初回 setup 後の連続購入で 1 tap 完走

webhook ベースで決済完走を確実に同期し、 cart purge / サンクスページ表示も
plugin 側で完結。 加盟店追加実装不要。
```

### 動作環境

#### EC-CUBE 4 系 (= このリポジトリ、 別 plugin)
```
- EC-CUBE: 4.3.x (推奨、 MVP 検証済み) / 4.2.x (未検証)
- PHP: 8.1 以上 (= EC-CUBE 4.3 公式対応)
- データベース: MariaDB 10.6+ / MySQL 8.0+ / PostgreSQL 13+
- Web サーバ: Nginx + PHP-FPM (本番) / Apache + mod_php (本番) / php -S (開発)
- HTTPS: 必須 (= webhook 受信用)
- 推奨: TRUSTED_PROXIES + framework.yaml の trusted_proxies / trusted_headers
  設定 (= reverse proxy 経由 HTTPS)
```

#### EC-CUBE 2 系 (= 別 plugin、 別リポジトリ)
```
- EC-CUBE: 2.17.2-p2 (推奨、 MVP 検証済み) / 2.25.0 (CI 同時 confirm 予定)
- PHP: 8.0 以上 (= 2.17.2-p2 公式対応)
- データベース: MariaDB 10.6+ / MySQL 8.0+
- Web サーバ: Nginx + PHP-FPM (本番)、 php -S (開発)
- HTTPS: 必須 (= webhook 受信用)
- 注: 2.17 系の MODULE_REALDIR は /data/downloads/module/ (= 旧 /data/module/
  ではない)、 plugin は shim 配置 + module_path 相対化で対応済み
```

### 価格
```
(D user 判断 - 公式ストア手数料 / 加盟店向け課金モデルは法人代表決定事項)
```

### サポート連絡先
```
(D user 提供 - 株式会社 uniple サポート窓口メールアドレス / Web フォーム URL)
```

### 開発者情報
```
開発元: 株式会社 uniple
法人住所: (D user 提供)
担当者: (D user 提供)
公式サイト: https://uniple.io
ドキュメント: https://uniple.io/docs/merchant-api
```

### ライセンス
```
GPL-3.0-or-later (= EC-CUBE 標準ライセンス互換)
```

### 既知の制約 (= 加盟店向け事前周知)
```
- 自動返金未対応 (= 加盟店から購入者へ JPYC 直送で対応、 ノンカストディ型決済
  のため)
- LINE 経由 (/api/intent) は MVP 後の追加対応予定
- HashPort + Android 16 + LIFF IAB の特殊条件で setup 初回 signing が稀に失敗
  (= HashPort アプリ再起動で復旧)
- uniple API 一時障害時は user 手動 retry が canonical (= 自動 retry は二重
  決済 risk 回避のため非実装)
```

### 添付資料 (= 公式ストアへ別途アップロード)
```
- スクリーンショット 8 枚 (= screenshot-script.md 参照)
- README + docs (= integration-guide.md §10 実装メモ)
- e2e smoke baseline (= MVP 完成 evidence、 docs/e2e-smoke-baseline.md)
```

---

## 4 系 annex (= EC-CUBE 4 plugin 申請時の追加事項)

### plugin code
```
UnipleJpyc
```

### composer.json type
```
eccube-plugin
```

### Symfony 依存
```
- Symfony 6.4 (= EC-CUBE 4.3 同梱バージョン)
- Doctrine ORM 2.x
```

---

## 2 系 annex (= EC-CUBE 2 plugin 申請時の追加事項)

### plugin code
```
UnipleJpyc
```

### plugin_info.php PLUGIN_CODE
```
UnipleJpyc
```

### EC-CUBE 2.17 系特有の注意事項
```
MODULE_REALDIR が /data/downloads/module/ に変更されているため、 plugin は
本体 module を plugin 配下に置きつつ、 MODULE_REALDIR には本体を require する
だけの shim を配置する 2 段構成で実装。 加盟店側で特別な設定不要。
```

---

## 提出前 セルフチェック

- [ ] composer.json の version (4 系) / plugin_info.php の PLUGIN_VERSION (2 系) を
      bump 済 (= 既存提出済バージョンより上)
- [ ] zip パッケージ生成済み (= bin/package.sh 実行)
- [ ] スクリーンショット 8 枚 撮影済み (= screenshot-script.md 全項目)
- [ ] presskit 3 行免責表記 が plugin 設定画面 + README に存在
- [ ] 「電子決済手段」 表記の整合 (= 「暗号資産」 「仮想通貨」 NG 表記なし)
- [ ] 加盟店 onboarding 用 docs (= integration-guide.md §10 実装メモ) 最新
- [ ] D user 判断事項 list (= ops-d-user-checklist.md) を D user に渡し済
