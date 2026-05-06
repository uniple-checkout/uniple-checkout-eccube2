#!/usr/bin/env bash
# uniple JPYC Checkout for EC-CUBE 2.x — 公式ストア提出用 zip パッケージング
#
# usage:
#   bin/package.sh [output_dir]
#
# 動作:
#   1. plugin リポジトリ root で git ls-files が返す tracked file 全部を zip に詰める
#      (= .git / 開発用ファイル等は git 管理外なので自動除外)
#   2. EC-CUBE 2 系の plugin install zip 形式: plugin_info.php が zip root に
#      ある形 (= 4 系と異なり Code/ サブディレクトリは無し)
#   3. zip ファイル名は <Code>-<version>.tar.gz (= EC-CUBE 2 公式は tar.gz が
#      標準だが zip も受け付ける)、 ここでは zip で出力
#   4. 出力先: 第 1 引数があればそこ、 なければ build/ 配下
#
# 公式ストア提出時の確認チェックリスト:
#   - plugin_info.php の PLUGIN_CODE / PLUGIN_NAME / PLUGIN_VERSION 整合
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

ZIP_NAME="${CODE}-${VERSION}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

[[ -f "${ZIP_PATH}" ]] && rm -f "${ZIP_PATH}"

TRACKED_FILES=$(git ls-files)
if [[ -z "${TRACKED_FILES}" ]]; then
    echo "ERROR: git ls-files が空、 plugin リポジトリ root で実行してください" >&2
    exit 1
fi

# PHP ZipArchive で zip 作成、 EC-CUBE 2 系は zip root に直接 plugin file を配置
ABS_ZIP_PATH="${PLUGIN_DIR}/${ZIP_PATH}"
export ABS_ZIP_PATH TRACKED_FILES
php <<'PHPEOF'
<?php
$zipPath = getenv('ABS_ZIP_PATH');
$files   = explode("\n", trim(getenv('TRACKED_FILES')));

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: PHP ZipArchive 拡張が無効、 php-zip パッケージをインストールしてください\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERROR: zip open 失敗: $zipPath\n");
    exit(1);
}

$count = 0;
foreach ($files as $file) {
    if ($file === '' || !is_file($file)) continue;
    // EC-CUBE 2 系: zip root に直接 plugin file を配置 (= 4 系のような Code/ なし)
    if (!$zip->addFile($file, $file)) {
        fwrite(STDERR, "WARN: add 失敗: $file\n");
        continue;
    }
    $count++;
}

if (!$zip->close()) {
    fwrite(STDERR, "ERROR: zip close 失敗\n");
    exit(1);
}
echo "added: $count files\n";
PHPEOF

if [[ ! -f "${ZIP_PATH}" ]]; then
    echo "ERROR: zip 生成失敗" >&2
    exit 1
fi

ZIP_SIZE=$(du -h "${ZIP_PATH}" | awk '{print $1}')

echo "✅ パッケージ作成完了: ${ZIP_PATH}"
echo "   zip 内構造: <plugin files> (= 2 系は zip root 直下、 Code/ なし)"
echo "   ファイルサイズ: ${ZIP_SIZE}"
echo
echo "公式ストア提出時:"
echo "  - EC-CUBE 管理画面 > オーナーズストア > プラグイン管理 > プラグインアップロード"
echo "  - plugin_info.php の PLUGIN_VERSION (= ${VERSION}) を提出ごとに bump"
echo "  - README + 実装メモ section の最新版が含まれていること確認"
