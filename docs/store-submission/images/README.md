# 公式ストア提出用 画像 (= アイコン / ロゴ)

EC-CUBE 公式ストア (= eccube.co.jp/products/) 申請時に必要な画像 2 種類:

| 用途 | サイズ | ファイル |
|---|---|---|
| ミニアイコン (= プラグイン一覧 サムネイル) | 50 × 50 px | `icon-50x50.png` |
| ロゴ画像 (= プラグイン詳細 ヘッダー) | 338 × 252 px | `logo-338x252.png` |

## SVG ソース

`src/` 配下に SVG ソースを置いている。 デザイン変更時はここを編集 →
`bin/build-images.sh` で PNG 再生成。

- `src/icon-50x50.svg` = JPYC Blue 円 + 白 U 字 (= uniple logomark の単純化)
- `src/logo-338x252.svg` = JPYC Blue 円 + 白チェック + 「JPYC Checkout」 / 「powered by uniple」 / 法令分類 caption

## デザイン方針

- **JPYC Blue (= `#16449A`)** を primary color に使用
  - JPYC 公式 Logo Guideline PDF (= 2025-08-12 制定) 準拠
  - source: https://corporate.jpyc.co.jp/company/presskit/
- **JPYC ロゴ自体は使用しない** (= JPYC presskit §6 「ロゴ変形・装飾追加 NG」 を厳格に守る、 UI accent としての brand color のみ使用)
- `#E3AD17` (ゴールド) は **JPYC Prepaid (別ブランド)** で本体ではないので使わない

## PNG 生成手順

```bash
# 必要なツールをインストール (= いずれか 1 つ)
sudo apt install librsvg2-bin   # (推奨) rsvg-convert
# or:
sudo apt install inkscape
# or:
sudo apt install imagemagick

# SVG → PNG 変換
bash bin/build-images.sh

# 出力:
#   docs/store-submission/images/icon-50x50.png
#   docs/store-submission/images/logo-338x252.png
```

## 外注代替案

公式ストア審査向けに**プロのデザイナーに発注したい**場合は、 上記 SVG ソース
+ デザイン方針を仕様書として渡せる。 制作要件:

- 50x50 px PNG (= 透過 OK)
- 338x252 px PNG (= 透過 OK)
- JPYC Blue `#16449A` を primary color
- JPYC ロゴ自体は使用しないこと (= プレスキット §6 違反回避)
- uniple ブランドの一貫性を維持 (= uniple 公式サイト https://uniple.io/ の
  visual identity に整合)

成果物は本ディレクトリ (`docs/store-submission/images/`) に PNG として配置すれば
申請に使える。
