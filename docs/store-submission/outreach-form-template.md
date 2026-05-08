# EC-CUBE 公式問い合わせフォーム 送信テンプレート

問い合わせ先: <https://www.ec-cube.net/contact/>

## 件名 / 種別

問い合わせフォームの種別欄で、 該当する選択肢があれば下記キーワードに最も近いものを選択:

- 「**プラグイン申請・公開について**」 (= ある場合は最優先)
- もしくは「**オーナーズストア掲載希望**」
- もしくは「**パートナー・販売パートナー**」 関連
- 上記いずれもない場合は「**ご相談**」 / 「**その他**」

### 件名 (= 自由入力欄がある場合)
```
【決済プラグイン公開】基本契約・審査手続きのご相談 — uniple JPYC Checkout
```

## 本文 (= フォーム本文欄に貼り付け)

```
EC-CUBE 公式オーナーズストアでの決済プラグイン公開について、
基本契約・審査手続きのご相談です。

弊社、株式会社 uniple では、日本円ステーブルコイン JPYC (資金決済法第 2 条第 5 項
に基づく電子決済手段、JPYC 株式会社発行) によるカート決済プラグイン
「uniple JPYC Checkout」を開発し、e2e 決済の動作実証まで完了しております。

EC-CUBE 公式オーナーズストアへの掲載を希望しており、決済プラグインに該当する
ため別途基本契約および NDA の締結が必要との理解です。担当部署のご教示、または
ご相談の進め方についてご案内いただけますでしょうか。

詳細は添付の説明資料をご参照ください。

【プラグイン概要】
- 名称: uniple JPYC Checkout
- 種別: 決済プラグイン (JPYC = 電子決済手段)
- 対応 EC-CUBE バージョン: 4.3.x / 4.2.x、および 2.17.2-p2 / 2.25.0
- 開発ステータス: MVP 完成、e2e 決済完走 確認済み
- 設計上の特徴: PCI DSS 非保持化対応 (リダイレクト型 + webhook 型)、加盟店の
  電子決済手段等取引業登録不要 (PSP 介在モデル)

ご返信お待ちしております。

株式会社 uniple
【担当者氏名】(※記入)
【メールアドレス】(※記入)
【電話番号】(※記入)
```

## 添付ファイル (= 1 通のみ)

```
ファイル: uniple-JPYC-Checkout_contract-briefing.pdf
内容: contract-briefing-public.md (= 本資料の対外用 sanitized 版) を PDF 化
```

PDF 生成手順 (= D user 側ローカル環境で実施):

```bash
cd /path/to/UnipleJpyc

# pandoc 必要 (= 未導入の場合):
sudo apt install pandoc texlive-xetex texlive-lang-japanese

# PDF 生成:
pandoc \
  --pdf-engine=xelatex \
  --variable=mainfont:"Noto Sans CJK JP" \
  --variable=monofont:"Noto Sans Mono CJK JP" \
  --variable=geometry:margin=2.5cm \
  --toc --toc-depth=2 \
  -o uniple-JPYC-Checkout_contract-briefing.pdf \
  docs/store-submission/contract-briefing-public.md
```

## 二次対応で出す資料 (= 担当者から要求あり後、 NDA 締結後)

初回問い合わせには出さない。 担当者から「詳細資料を送ってほしい」 と要求あり、
かつ NDA 締結後に正式提供:

- README.md (= プラグイン全体概要)
- docs/integration-guide.md (= 加盟店向け統合ガイド §10 実装メモ含む)
- docs/e2e-smoke-baseline.md (= MVP 完成 evidence)
- docs/store-submission/spec-sheet.md (= 公式ストア申請フォーム文案)
- docs/store-submission/screenshot-script.md (= 撮影台本)
- docs/store-submission/images/ (= ロゴ画像 PNG)
- bin/package.sh で生成した tar.gz パッケージ
- SECRET-sandbox-info.md (= 別途 zip パスワード付きで送付)

## 内部用資料 (= 対外送付しない)

これらは法人内部用、 EC-CUBE 社には送付しない:

- docs/store-submission/ops-d-user-checklist.md (= 法人内部の意思決定 checklist)
- docs/store-submission/contract-briefing.md (= 内部用 SSOT、 対外用は -public.md)
- SECRET-sandbox-info.md.template の実値入りでないテンプレ自体は OK

## 補足: 営業窓口の対応想定

EC-CUBE 公式の問い合わせは EC-CUBE 営業 / パートナーアライアンスチームが
受付。 決済プラグインは「公式決済モジュール以外との連携」 に該当し、 別途
基本契約 + NDA が前提となる旨が公式 FAQ で明示されています。

想定される進行:
1. 数日〜1 週間で営業担当者から返信
2. NDA テンプレ提供 → 法務 review → 締結
3. 商務条件ヒアリング (= 販売モデル、 手数料、 サポート方針)
4. 基本契約締結
5. 技術審査フェーズに入る (= tar.gz + 詳細資料を正式提出)
