#!/usr/bin/env bash
set -euo pipefail

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE_TAG="cakephp-system-test:8.2"
LOG_FILE="${ROOT}/.phpunit-system-test.log"
JUNIT_FILE="${ROOT}/.phpunit-system-test.junit.xml"
TEAMCITY_FILE="${ROOT}/.phpunit-system-test.teamcity.txt"
DB_AUTOQUOTE_LOG="${ROOT}/.phpunit-database-autoquote.log"
DB_AUTOQUOTE_JUNIT="${ROOT}/.phpunit-database-autoquote.junit.xml"
DB_AUTOQUOTE_TEAMCITY="${ROOT}/.phpunit-database-autoquote.teamcity.txt"
DOCKER_BIN="docker"
if [ "${USE_SUDO_DOCKER}" = "1" ]; then
    DOCKER_BIN="sudo docker"
fi

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
        vendor/bin/phpunit --colors=never \
            --log-junit /workspace/.phpunit-system-test.junit.xml \
            --log-teamcity /workspace/.phpunit-system-test.teamcity.txt \
            <"${STDIN_FIFO}" 2>&1 | tee /workspace/.phpunit-system-test.log
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

${DOCKER_BIN} run --rm \
    -u "$(id -u):$(id -g)" \
    -v "${ROOT}:/workspace" \
    -w /workspace \
    "${IMAGE_TAG}" \
    php scripts/check-phpunit-gate.php /workspace/.phpunit-system-test.log /workspace/.phpunit-system-test.teamcity.txt /workspace/.phpunit-system-test.junit.xml

echo "=== Database suite with CAKE_TEST_AUTOQUOTE=1 ==="
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
        umask 0002
        STDIN_FIFO="$(mktemp -u)"
        mkfifo "${STDIN_FIFO}"
        ( exec 3>"${STDIN_FIFO}"; sleep 86400 ) &
        STDIN_FIFO_HOLDER_PID=$!
        CAKE_TEST_AUTOQUOTE=1 vendor/bin/phpunit --colors=never \
            --log-junit /workspace/.phpunit-database-autoquote.junit.xml \
            --log-teamcity /workspace/.phpunit-database-autoquote.teamcity.txt \
            tests/TestCase/Database/ \
            <"${STDIN_FIFO}" 2>&1 | tee /workspace/.phpunit-database-autoquote.log
        PHPUNIT_STATUS="${PIPESTATUS[0]}"
        kill "${STDIN_FIFO_HOLDER_PID}" 2>/dev/null || true
        wait "${STDIN_FIFO_HOLDER_PID}" 2>/dev/null || true
        rm -f "${STDIN_FIFO}"
        exit "${PHPUNIT_STATUS}"
    '
DB_AUTOQUOTE_EXIT=$?
set -e

if [ "${DB_AUTOQUOTE_EXIT}" -ne 0 ]; then
    echo "Database autoquote PHPUnit exited with status ${DB_AUTOQUOTE_EXIT}." >&2
    exit "${DB_AUTOQUOTE_EXIT}"
fi

${DOCKER_BIN} run --rm \
    -u "$(id -u):$(id -g)" \
    -v "${ROOT}:/workspace" \
    -w /workspace \
    "${IMAGE_TAG}" \
    php scripts/check-phpunit-gate.php \
        /workspace/.phpunit-database-autoquote.log \
        /workspace/.phpunit-database-autoquote.teamcity.txt \
        /workspace/.phpunit-database-autoquote.junit.xml \
        /workspace/scripts/allowed-skipped-tests-database-autoquote.txt \
        database
echo "Database autoquote gate passed."
echo "System test passed."
