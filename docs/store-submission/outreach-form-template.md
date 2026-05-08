# EC-CUBE 公式問い合わせフォーム 送信テンプレート

問い合わせ先: <https://www.ec-cube.net/contact/>

> ⚠️ **EC-CUBE 公式問い合わせフォームはファイル添付不可** のため、 必要情報を
> 本文に凝縮して送信。 詳細資料 (= contract-briefing-public PDF 等) は担当者
> からの返信メールに添付して送付する 2 段階構成。

## 件名 / 種別

問い合わせフォームの種別欄で、 該当する選択肢があれば下記キーワードに最も
近いものを選択:

- 「**プラグイン申請・公開について**」 (= ある場合は最優先)
- もしくは「**オーナーズストア掲載希望**」
- もしくは「**パートナー・販売パートナー**」 関連
- 上記いずれもない場合は「**ご相談**」 / 「**その他**」

### 件名 (= 自由入力欄がある場合)
```
【決済プラグイン公開】基本契約・審査手続きのご相談 — uniple checkout
```

## 本文 (= フォーム本文欄に貼り付け、 約 350 字 + 概要 list)

```
EC-CUBE 公式オーナーズストアでの決済プラグイン公開について、
基本契約・審査手続きのご相談です。

弊社、株式会社 uniple では、日本円ステーブルコイン JPYC
(資金決済法第 2 条第 5 項に基づく電子決済手段、JPYC 株式会社発行) による
カート決済プラグイン「uniple checkout」を開発し、e2e 決済の動作実証まで
完了しております。

決済プラグインに該当するため別途基本契約および NDA の締結が必要との
理解です。担当部署のご教示、またはご相談の進め方についてご案内いただけ
ますでしょうか。

詳細な技術仕様書 (PCI DSS 非保持化フロー、 加盟店登録要否の整理、 サンド
ボックス情報等) を別途用意しております。お手数ですがご返信メールに添付
にてお送りさせていただければ幸いです。

【プラグイン概要】
- 名称: uniple checkout
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

## 担当者からの返信受領後 (= メール添付 2 段階目)

担当者から返信メールが届いたら、 contract-briefing-public.md を PDF 化して
返信メールに添付:

```bash
cd /path/to/UnipleJpyc

# pandoc 未導入の場合:
sudo apt install pandoc texlive-xetex texlive-lang-japanese

# PDF 生成:
pandoc \
  --pdf-engine=xelatex \
  --variable=mainfont:"Noto Sans CJK JP" \
  --variable=monofont:"Noto Sans Mono CJK JP" \
  --variable=geometry:margin=2.5cm \
  --toc --toc-depth=2 \
  -o uniple-Checkout_contract-briefing.pdf \
  docs/store-submission/contract-briefing-public.md
```

## NDA 締結後の正式提供資料 (= 技術審査フェーズ用)

NDA 締結後、 EC-CUBE 社の技術審査担当者の要求に応じて段階的に提供:

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
1. 数日〜1 週間で営業担当者から返信メール
2. 返信メールに contract-briefing-public.pdf を添付して送付 (= 詳細仕様)
3. NDA テンプレ提供 → 法務 review → 締結
4. 商務条件ヒアリング (= 販売モデル、 手数料、 サポート方針)
5. 基本契約締結
6. 技術審査フェーズに入る (= tar.gz + 詳細資料を正式提出)
