#!/usr/bin/env bash
# Verify release version single source of truth for the EC-CUBE 2 plugin.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

extract_static_value() {
    local file="$1"
    local name="$2"
    php -r '
    $file = $argv[1];
    $name = $argv[2];
    $src = file_get_contents($file);
    $pattern = "/\\$" . preg_quote($name, "/") . "\\s*=\\s*[\x27\"]([^\x27\"]+)[\x27\"]/";
    if (preg_match($pattern, $src, $m)) {
        echo $m[1];
    }
    ' "${file}" "${name}"
}

extract_const_value() {
    local file="$1"
    local name="$2"
    php -r '
    $file = $argv[1];
    $name = $argv[2];
    $src = file_get_contents($file);
    $pattern = "/const\\s+" . preg_quote($name, "/") . "\\s*=\\s*[\x27\"]([^\x27\"]+)[\x27\"]/";
    if (preg_match($pattern, $src, $m)) {
        echo $m[1];
    }
    ' "${file}" "${name}"
}

PLUGIN_VERSION="$(extract_static_value plugin_info.php PLUGIN_VERSION)"
LOCAL_VERSION="$(extract_static_value plugin_info.php LOCAL_VERSION)"
CLIENT_VERSION="$(extract_const_value lib/UnipleJpyc_Client.php PLUGIN_VERSION)"
CHANGELOG_VERSION="$(awk '/^## \[[0-9]+\.[0-9]+\.[0-9]+\]/{gsub(/^## \[/, ""); gsub(/\].*/, ""); print; exit}' CHANGELOG.md)"

[[ "${PLUGIN_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "plugin_info.php PLUGIN_VERSION is not semver: ${PLUGIN_VERSION}"
[[ "${LOCAL_VERSION}" == "${PLUGIN_VERSION}" ]] || fail "plugin_info.php LOCAL_VERSION (${LOCAL_VERSION}) != PLUGIN_VERSION (${PLUGIN_VERSION})"

if [[ -n "${CLIENT_VERSION}" ]]; then
    [[ "${CLIENT_VERSION}" == "${PLUGIN_VERSION}" ]] || fail "UnipleJpyc_Client::PLUGIN_VERSION (${CLIENT_VERSION}) != plugin_info.php (${PLUGIN_VERSION})"
fi

[[ -n "${CHANGELOG_VERSION}" ]] || fail "CHANGELOG.md の最新 version が読めません"
[[ "${CHANGELOG_VERSION}" == "${PLUGIN_VERSION}" ]] || fail "CHANGELOG.md latest (${CHANGELOG_VERSION}) != plugin_info.php (${PLUGIN_VERSION})"

echo "OK: version ${PLUGIN_VERSION}"
echo " - plugin_info.php: PLUGIN_VERSION / LOCAL_VERSION"
echo " - lib/UnipleJpyc_Client.php: PLUGIN_VERSION"
echo " - CHANGELOG.md: latest entry"
