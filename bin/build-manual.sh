#!/usr/bin/env bash
# uniple Checkout — 公式ストア提出用 マニュアル PDF 生成 (EC-CUBE 2 系)
#
# usage:
#   bin/build-manual.sh [output_dir]
#
# 動作:
#   README.md (= 全 docs を含む 2 系の SSOT) を pandoc で PDF 化。
#   出力: docs/store-submission/manual.pdf
#
# 必要なもの:
#   - pandoc (= sudo apt install pandoc)
#   - texlive-xetex + texlive-lang-japanese (= 日本語 PDF、 sudo apt install
#     texlive-xetex texlive-lang-japanese) もしくは weasyprint
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

OUTPUT_DIR="${1:-docs/store-submission}"
mkdir -p "${OUTPUT_DIR}"

OUT_PDF="${OUTPUT_DIR}/manual.pdf"

TMP_MD=$(mktemp --suffix=.md)
trap 'rm -f "${TMP_MD}"' EXIT

if ! command -v pandoc &>/dev/null; then
    echo "ERROR: pandoc がインストールされていません" >&2
    echo "       sudo apt install pandoc texlive-xetex texlive-lang-japanese" >&2
    exit 1
fi

# README + 既存 docs を結合
{
    echo "---"
    echo "title: uniple Checkout — 加盟店マニュアル"
    echo "subtitle: EC-CUBE 2 系プラグイン (β)"
    echo "author: 株式会社 uniple"
    echo "date: $(date +%Y-%m-%d)"
    echo "documentclass: report"
    echo "geometry: margin=2.5cm"
    echo "lang: ja"
    echo "---"
    echo ""
    cat README.md
} > "${TMP_MD}"

PANDOC_OPTS=(
    --from=markdown
    --to=pdf
    --output="${OUT_PDF}"
    --variable=mainfont:"Noto Sans CJK JP"
    --variable=monofont:"Noto Sans Mono CJK JP"
    --variable=geometry:margin=2.5cm
    --toc
    --toc-depth=3
)

if pandoc --pdf-engine=xelatex "${PANDOC_OPTS[@]}" "${TMP_MD}" 2>/dev/null; then
    ENGINE="xelatex"
elif pandoc --pdf-engine=weasyprint "${PANDOC_OPTS[@]}" "${TMP_MD}" 2>/dev/null; then
    ENGINE="weasyprint"
else
    echo "ERROR: pandoc PDF 生成に失敗" >&2
    echo "       sudo apt install texlive-xetex texlive-lang-japanese" >&2
    exit 1
fi

PDF_SIZE=$(du -h "${OUT_PDF}" | awk '{print $1}')
echo "✅ マニュアル PDF 生成完了: ${OUT_PDF}"
echo "   エンジン: ${ENGINE}"
echo "   ファイルサイズ: ${PDF_SIZE}"
