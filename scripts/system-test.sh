#!/usr/bin/env bash
set -euo pipefail

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE_TAG="cakephp-system-test:8.2"
LOG_FILE="${ROOT}/.phpunit-system-test.log"
DOCKER_BIN="docker"
if [ "${USE_SUDO_DOCKER}" = "1" ]; then
    DOCKER_BIN="sudo docker"
fi

check_phpunit_log() {
    local logfile="$1"
    if rg -q '^(Warning|Deprecated):' "${logfile}" || rg -q '^PHP (Warning|Deprecated):' "${logfile}"; then
        echo "PHPUnit output contains Warning or Deprecated lines." >&2
        return 1
    fi
    if rg -q '^OK, but there were issues!' "${logfile}" \
        || rg -q '^FAILURES!' "${logfile}" \
        || rg -q '^ERRORS!' "${logfile}" \
        || rg -q '^No tests executed!' "${logfile}"; then
        echo "PHPUnit summary indicates failure." >&2
        return 1
    fi
    if rg -q '^OK \([0-9]+ tests?, [0-9]+ assertions?\)$' "${logfile}"; then
        return 0
    fi
    if rg -q '^OK, but some tests were skipped!' "${logfile}" \
        && rg -q '^Tests: [0-9]+, Assertions: [0-9]+, Skipped: [0-9]+\.$' "${logfile}"; then
        return 0
    fi
    echo "PHPUnit output missing required success summary line." >&2
    return 1
}

echo "Building ${IMAGE_TAG}..."
${DOCKER_BIN} build -f "${ROOT}/docker/system-test.Dockerfile" -t "${IMAGE_TAG}" "${ROOT}/docker"

set +e
${DOCKER_BIN} run --rm \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e COMPOSER_HOME=/tmp/composer \
    -v "${ROOT}:/workspace" \
    -w /workspace \
    "${IMAGE_TAG}" \
    bash -lc '
        set -euo pipefail
        mkdir -p /tmp/composer
        composer update --no-interaction --prefer-dist --no-progress
        echo "=== composer audit ==="
        composer audit
        php scripts/check-cluster-pins.php
        umask 0002
        STDIN_FIFO="$(mktemp -u)"
        mkfifo "${STDIN_FIFO}"
        ( exec 3>"${STDIN_FIFO}"; sleep 86400 ) &
        STDIN_FIFO_HOLDER_PID=$!
        vendor/bin/phpunit --colors=never <"${STDIN_FIFO}" 2>&1 | tee /workspace/.phpunit-system-test.log
        PHPUNIT_STATUS="${PIPESTATUS[0]}"
        kill "${STDIN_FIFO_HOLDER_PID}" 2>/dev/null || true
        wait "${STDIN_FIFO_HOLDER_PID}" 2>/dev/null || true
        rm -f "${STDIN_FIFO}"
        exit "${PHPUNIT_STATUS}"
    '
PHPUNIT_EXIT=$?
set -e

if [ "${PHPUNIT_EXIT}" -ne 0 ]; then
    echo "PHPUnit exited with status ${PHPUNIT_EXIT}." >&2
    exit "${PHPUNIT_EXIT}"
fi

check_phpunit_log "${LOG_FILE}"
echo "System test passed."
