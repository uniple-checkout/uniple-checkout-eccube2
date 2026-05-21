#!/usr/bin/env bash
# uniple checkout for EC-CUBE 2.x — release zip packaging
#
# usage:
#   bin/package.sh [output_dir]
#
# 動作:
#   1. plugin リポジトリ root の tracked / untracked file から、内部資料を除外して zip に詰める。
#   2. zip 内 top directory は plugin code (= UnipleJpyc)。
#   3. zip ファイル名は uniple-checkout-eccube2-<version>.zip。
#   4. 出力先: 第 1 引数があればそこ、なければ build/ 配下。
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

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

if [[ -x "bin/verify-version.sh" ]]; then
    bash bin/verify-version.sh >/dev/null
fi

OUTPUT_DIR="${1:-build}"
mkdir -p "${OUTPUT_DIR}"

ARCHIVE_NAME="uniple-checkout-eccube2-${VERSION}.zip"
ARCHIVE_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}"
TOP_DIR="${CODE}"

if [[ "${ARCHIVE_PATH}" = /* ]]; then
    ABS_ARCHIVE_PATH="${ARCHIVE_PATH}"
else
    ABS_ARCHIVE_PATH="${PLUGIN_DIR}/${ARCHIVE_PATH}"
fi

[[ -f "${ABS_ARCHIVE_PATH}" ]] && rm -f "${ABS_ARCHIVE_PATH}"

TMP_PARENT=$(mktemp -d)
trap 'rm -rf "${TMP_PARENT}"' EXIT
TMP_STAGING="${TMP_PARENT}/${TOP_DIR}"
mkdir -p "${TMP_STAGING}"

should_exclude_file() {
    local file="$1"
    case "${file}" in
        .codex|.codex/*) return 0 ;;
        .git|.git/*) return 0 ;;
        .github|.github/*) return 0 ;;
        .gitignore) return 0 ;;
        bin|bin/*) return 0 ;;
        build|build/*) return 0 ;;
        docs/store-submission|docs/store-submission/*) return 0 ;;
        docs/release-template.md) return 0 ;;
        tests|tests/*) return 0 ;;
        vendor|vendor/*) return 0 ;;
        *.tar.gz) return 0 ;;
        *.zip) return 0 ;;
        .DS_Store|*/.DS_Store) return 0 ;;
        ._*|*/._*) return 0 ;;
    esac
    return 1
}

COUNT=0
while IFS= read -r file; do
    [[ -z "${file}" ]] && continue
    [[ ! -f "${file}" ]] && continue
    if should_exclude_file "${file}"; then
        continue
    fi
    mkdir -p "${TMP_STAGING}/$(dirname "${file}")"
    cp "${file}" "${TMP_STAGING}/${file}"
    COUNT=$((COUNT + 1))
done < <(git ls-files --cached --others --exclude-standard)

if [[ ${COUNT} -eq 0 ]]; then
    echo "ERROR: package 対象 file が空です" >&2
    exit 1
fi

if command -v zip >/dev/null 2>&1; then
    (
        cd "${TMP_PARENT}" && \
        COPYFILE_DISABLE=1 zip -X -q -r "${ABS_ARCHIVE_PATH}" "${TOP_DIR}"
    )
else
    php -r '
    if (!class_exists("ZipArchive")) {
        fwrite(STDERR, "ERROR: zip command も PHP ZipArchive も利用できません\n");
        exit(2);
    }
    $root = $argv[1];
    $top = $argv[2];
    $zipPath = $argv[3];
    $base = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $top;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "ERROR: zip を作成できません: {$zipPath}\n");
        exit(3);
    }
    $files = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files, SORT_STRING);
    foreach ($files as $path) {
        $relative = $top . "/" . str_replace(DIRECTORY_SEPARATOR, "/", substr($path, strlen($base) + 1));
        if (!$zip->addFile($path, $relative)) {
            fwrite(STDERR, "ERROR: zip に追加できません: {$relative}\n");
            exit(4);
        }
    }
    if (!$zip->close()) {
        fwrite(STDERR, "ERROR: zip close に失敗しました\n");
        exit(5);
    }
    ' "${TMP_PARENT}" "${TOP_DIR}" "${ABS_ARCHIVE_PATH}"
fi

if [[ ! -f "${ABS_ARCHIVE_PATH}" ]]; then
    echo "ERROR: zip 生成失敗" >&2
    exit 1
fi

ARCHIVE_SIZE=$(du -h "${ABS_ARCHIVE_PATH}" | awk '{print $1}')

echo "✅ パッケージ作成完了: ${ARCHIVE_PATH}"
echo "   archive 内構造: ${TOP_DIR}/<plugin files>"
echo "   ファイル数: ${COUNT}"
echo "   ファイルサイズ: ${ARCHIVE_SIZE}"
