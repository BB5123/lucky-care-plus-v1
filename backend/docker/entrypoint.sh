#!/bin/sh
#
# Seed the mutable knowledge corpus on first boot.
#
# The image ships an immutable copy of the reviewed-at-build-time corpus at
# KNOWLEDGE_SEED_PATH. The live corpus lives on a named volume (compose mounts
# `knowledgedata` at /var/www/html/database/knowledge), because review status
# is mutable state: rebuilding the image must never discard a clinician's
# decision. The seed is copied exactly once, when the volume is empty; every
# later start keeps what is already there (CR-005-FIX).
#
# Runs as www-data (the image's runtime user) and hands over to the official
# php entrypoint so `docker-php-entrypoint` keeps its normal behaviour.

set -eu

CORPUS_DIR="${KNOWLEDGE_CORPUS_PATH:-/var/www/html/database/knowledge}"
SEED_DIR="${KNOWLEDGE_SEED_PATH:-/var/www/html/database/knowledge-seed}"

if [ -d "$SEED_DIR" ]; then
    if [ -z "$(ls -A "$CORPUS_DIR" 2>/dev/null || true)" ]; then
        mkdir -p "$CORPUS_DIR"
        # `cp -R` creates the files as the running user (do not preserve the
        # image's ownership), so www-data can rewrite a source and the SQLite
        # file later.
        cp -R "$SEED_DIR"/. "$CORPUS_DIR"/
        chmod -R u+rwX "$CORPUS_DIR"
        echo "knowledge corpus: seeded from image"
    else
        echo "knowledge corpus: existing corpus kept"
    fi
fi

exec docker-php-entrypoint "$@"
