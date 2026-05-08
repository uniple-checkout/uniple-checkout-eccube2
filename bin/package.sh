#!/usr/bin/env bash
# uniple checkout for EC-CUBE 2.x — 公式ストア提出用 tar.gz パッケージング
#
# usage:
#   bin/package.sh [output_dir]
#
# 動作:
#   1. plugin リポジトリ root で git ls-files が返す tracked file 全部を tar.gz に詰める
#      (= .git / 開発用ファイル等は git 管理外なので自動除外)
#   2. EC-CUBE 2 系の plugin install tar.gz 形式: plugin_info.php が tar.gz root に
#      ある形 (= 4 系と異なり Code/ サブディレクトリは無し)
#   3. tar.gz ファイル名は <Code>-<version>.tar.gz
#   4. 出力先: 第 1 引数があればそこ、 なければ build/ 配下
#   5. macOS の AppleDouble (._*) を除外するため COPYFILE_DISABLE=1 を設定
#
# EC-CUBE 公式ストア申請形式:
#   - 形式: tar.gz (= zip 不可、 公式 FAQ 明示)
#
# 公式ストア提出時の確認チェックリスト:
#   - plugin_info.php の PLUGIN_CODE / PLUGIN_NAME / PLUGIN_VERSION 整合
#   - PLUGIN_VERSION を bump 済 (= 既存提出済バージョンより上)
#   - README.md の動作要件 (= EC-CUBE バージョン / PHP / DB) 最新
#   - presskit 必須 3 行免責表記が plugin 設定画面 + README に存在
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

# plugin_info.php から PLUGIN_CODE / PLUGIN_VERSION 抽出
CODE=$(php -r '
$src = file_get_contents("plugin_info.php");
preg_match("/PLUGIN_CODE\s*=\s*[\x27\"]([^\x27\"]+)[\x27\"]/", $src, $m);
echo $m[1] ?? "";
')
VERSION=$(php -r '
$src = file_get_contents("plugin_info.php");
preg_match("/PLUGIN_VERSION\s*=\s*[\x27\"]([^\x27\"]+)[\x27\"]/", $src, $m);
echo $m[1] ?? "";
')

if [[ -z "${CODE}" ]] || [[ -z "${VERSION}" ]]; then
    echo "ERROR: plugin_info.php の PLUGIN_CODE または PLUGIN_VERSION が読めません" >&2
    exit 1
fi

OUTPUT_DIR="${1:-build}"
mkdir -p "${OUTPUT_DIR}"

ARCHIVE_NAME="${CODE}-${VERSION}.tar.gz"
ARCHIVE_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}"

[[ -f "${ARCHIVE_PATH}" ]] && rm -f "${ARCHIVE_PATH}"

# git ls-files から tracked file を staging に複製 (= 2 系は archive root 直下)
TMP_STAGING=$(mktemp -d)
trap 'rm -rf "${TMP_STAGING}"' EXIT

COUNT=0
while IFS= read -r file; do
    [[ -z "${file}" ]] && continue
    [[ ! -f "${file}" ]] && continue
    mkdir -p "${TMP_STAGING}/$(dirname "${file}")"
    cp "${file}" "${TMP_STAGING}/${file}"
    COUNT=$((COUNT + 1))
done < <(git ls-files)

if [[ ${COUNT} -eq 0 ]]; then
    echo "ERROR: git ls-files が空、 plugin リポジトリ root で実行してください" >&2
    exit 1
fi

# tar.gz 作成 (= COPYFILE_DISABLE で macOS AppleDouble 除外、 staging から)
# EC-CUBE 2 系は archive root に直接 plugin file を配置 (= Code/ サブディレクトリ無し)
ABS_ARCHIVE_PATH="${PLUGIN_DIR}/${ARCHIVE_PATH}"
(
    cd "${TMP_STAGING}" && \
    COPYFILE_DISABLE=1 tar \
        --exclude '.git' \
        --exclude '.DS_Store' \
        --exclude '._*' \
        -czf "${ABS_ARCHIVE_PATH}" \
        .
)

if [[ ! -f "${ARCHIVE_PATH}" ]]; then
    echo "ERROR: tar.gz 生成失敗" >&2
    exit 1
fi

ARCHIVE_SIZE=$(du -h "${ARCHIVE_PATH}" | awk '{print $1}')

echo "✅ パッケージ作成完了: ${ARCHIVE_PATH}"
echo "   archive 内構造: <plugin files> (= 2 系は archive root 直下、 Code/ なし)"
echo "   ファイル数: ${COUNT}"
echo "   ファイルサイズ: ${ARCHIVE_SIZE}"
echo
echo "公式ストア提出時:"
echo "  - EC-CUBE.net パートナーマイページ > プラグイン申請 > 新規登録"
echo "  - 形式: tar.gz (= zip 不可)"
echo "  - plugin_info.php の PLUGIN_VERSION (= ${VERSION}) を提出ごとに bump"
echo "  - README + 実装メモ section の最新版が含まれていること確認"
