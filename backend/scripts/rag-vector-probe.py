#!/usr/bin/env python3
"""Probe whether a LOCAL embedding model improves retrieval over the tuned lexical retriever.

This is a developer tool, not part of CI and not part of the application: it needs an Ollama
server and a pulled embedding model. It exists so the claim in `docs/RAG-VECTOR-OPTIONS.md`
("lexical is enough for this corpus, and here is the measurement") can be reproduced instead of
taken on trust.

Setup:

    ollama serve
    ollama pull bge-m3          # 1024-dim multilingual, good Thai

Run from the repository root:

    python backend/scripts/rag-vector-probe.py

It reads the committed knowledge base and the 28-question benchmark, embeds the ~181 chunks
through Ollama, and prints the numbers for three retrievers:

- vector   : best chunk per document, cosine similarity
- lexical  : FTS5 MATCH on the raw question (an APPROXIMATION - the app's retriever normalises
             Thai spacing and expands aliases first, so the app scores higher; use
             `php artisan knowledge:bench` for the authoritative lexical numbers)
- hybrid   : reciprocal rank fusion of the two

Nothing is written anywhere; the corpus and the benchmark are read-only.
"""

import io
import json
import math
import os
import sqlite3
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
KB = os.path.join(ROOT, "database/knowledge/health_kb.sqlite")
BENCH = os.path.join(ROOT, "database/knowledge/benchmark/queries.json")
API = os.environ.get("OLLAMA_EMBED_URL", "http://127.0.0.1:11434/api/embed")
MODEL = os.environ.get("OLLAMA_EMBED_MODEL", "bge-m3")
BATCH = 16


def embed(texts):
    payload = json.dumps({"model": MODEL, "input": texts}).encode("utf-8")
    request = urllib.request.Request(
        API, data=payload, headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        return json.loads(response.read().decode("utf-8"))["embeddings"]


def cosine(a, b):
    dot = sum(x * y for x, y in zip(a, b))
    norm_a = math.sqrt(sum(x * x for x in a)) or 1.0
    norm_b = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (norm_a * norm_b)


def rank_vector(query_vector, chunk_vectors, chunk_ids, limit=3):
    scored = [(cosine(query_vector, cv), cid) for cv, cid in zip(chunk_vectors, chunk_ids)]
    scored.sort(reverse=True)
    best = {}
    for score, cid in scored:
        best.setdefault(cid, score)
    return [cid for cid, _ in sorted(best.items(), key=lambda pair: -pair[1])[:limit]]


def rank_lexical(connection, question, limit=3):
    try:
        rows = connection.execute(
            "select c.document_id from chunks_fts f join chunks c on c.id = f.rowid "
            "where chunks_fts match ? order by f.rank limit ?",
            (question, limit),
        ).fetchall()
    except sqlite3.Error:
        return []
    return [row[0] for row in rows]


def fuse(lexical_ids, vector_ids, k=60):
    scores = {}
    for rank, doc in enumerate(lexical_ids, start=1):
        scores[doc] = scores.get(doc, 0.0) + 1.0 / (k + rank)
    for rank, doc in enumerate(vector_ids, start=1):
        scores[doc] = scores.get(doc, 0.0) + 1.0 / (k + rank)
    return [doc for doc, _ in sorted(scores.items(), key=lambda pair: -pair[1])]


def main():
    connection = sqlite3.connect(KB)
    rows = connection.execute(
        "select c.document_id, f.title || '\n' || f.text "
        "from chunks_fts f join chunks c on c.id = f.rowid order by c.id"
    ).fetchall()

    chunk_ids = [row[0] for row in rows]
    texts = [row[1] for row in rows]
    print(f"chunks: {len(texts)}  model: {MODEL}")

    vectors = []
    for start in range(0, len(texts), BATCH):
        vectors.extend(embed(texts[start : start + BATCH]))

    questions = json.load(io.open(BENCH, encoding="utf-8"))
    print(f"questions: {len(questions)}\n")

    tally = {"vector": 0, "lexical": 0, "hybrid": 0}
    misses = {"vector": [], "lexical": [], "hybrid": []}

    for entry in questions:
        expected = set(entry["expected"])
        question = entry["question"]

        vector_ids = rank_vector(embed([question])[0], vectors, chunk_ids)
        lexical_ids = rank_lexical(connection, question)
        hybrid_ids = fuse(lexical_ids, vector_ids)

        for name, ids in (
            ("vector", vector_ids),
            ("lexical", lexical_ids),
            ("hybrid", hybrid_ids),
        ):
            if any(doc in expected for doc in ids):
                tally[name] += 1
            else:
                misses[name].append(entry["id"])

    print("retriever  hit@3 / total   rate")
    for name, hits in tally.items():
        print(f"{name:9s} {hits:3d} / {len(questions):3d}      {hits / len(questions):.3f}")

    print("\nmisses:")
    for name, ids in misses.items():
        print(f"  {name:9s} {ids}")


if __name__ == "__main__":
    main()
