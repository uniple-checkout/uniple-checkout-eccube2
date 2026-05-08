#!/usr/bin/env bash
# uniple checkout — 公式ストア提出用 アイコン/ロゴ画像生成
#
# usage:
#   bin/build-images.sh
#
# 動作:
#   docs/store-submission/images/src/ の SVG を PNG (50x50, 338x252) に変換、
#   docs/store-submission/images/ に出力。
#
# 必要なもの (= 1 つ以上):
#   - rsvg-convert (= librsvg、 sudo apt install librsvg2-bin)
#   - inkscape
#   - ImageMagick (= convert with rsvg delegate)
#
# 公式ストア提出時:
#   - 50x50 = ミニアイコン (= プラグイン一覧 サムネイル)
#   - 338x252 = ロゴ画像 (= プラグイン詳細 ヘッダー)
#   - フォーマット: PNG 推奨 (= 透過対応)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="${PLUGIN_DIR}/docs/store-submission/images/src"
OUT_DIR="${PLUGIN_DIR}/docs/store-submission/images"

# converter 自動選択 (= 利用可能な最初のツール)
convert_svg_to_png() {
    local svg_in="$1"
    local png_out="$2"
    local width="$3"
    local height="$4"

    if command -v rsvg-convert &>/dev/null; then
        rsvg-convert -w "${width}" -h "${height}" -o "${png_out}" "${svg_in}"
    elif command -v inkscape &>/dev/null; then
        inkscape "${svg_in}" --export-type=png --export-filename="${png_out}" \
            --export-width="${width}" --export-height="${height}" 2>/dev/null
    elif command -v convert &>/dev/null; then
        convert -background none -density 300 -resize "${width}x${height}" \
            "${svg_in}" "${png_out}"
    else
        echo "ERROR: rsvg-convert / inkscape / convert (ImageMagick) のいずれもインストールされていません" >&2
        echo "       sudo apt install librsvg2-bin など" >&2
        exit 1
    fi
}

# アイコン (50x50)
echo "icon-50x50.png 生成中..."
convert_svg_to_png "${SRC_DIR}/icon-50x50.svg" "${OUT_DIR}/icon-50x50.png" 50 50

# ロゴ (338x252)
echo "logo-338x252.png 生成中..."
convert_svg_to_png "${SRC_DIR}/logo-338x252.svg" "${OUT_DIR}/logo-338x252.png" 338 252

echo
echo "✅ 画像生成完了:"
ls -la "${OUT_DIR}"/*.png 2>/dev/null

echo
echo "公式ストア提出時:"
echo "  - icon-50x50.png  → プラグイン一覧 サムネイル"
echo "  - logo-338x252.png → プラグイン詳細 ヘッダー"
echo
echo "デザインカスタマイズ:"
echo "  - SVG ソース: ${SRC_DIR}/"
echo "  - JPYC Blue: #16449A (= JPYC presskit 公式色、 reference: reference_jpyc_legal_classification.md)"
echo "  - JPYC ロゴ自体は使用していない (= プレスキット §6 ロゴ変形・装飾追加 NG 配慮、"
echo "    derivative デザインのみ)"
