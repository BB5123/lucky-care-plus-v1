# Night report - 25 October 2026 (00:25 - 08:00)

A summary of the autonomous run, in the order the work happened. Every claim below was verified on `main`
before this file was written.

## What shipped (13 pull requests)

| PR | What it did | Why it mattered |
|---|---|---|
| #38 | Thai disease corpus: HFMD, leptospirosis, two PM2.5 articles | the product had 3 sources and none of the diseases families actually ask about |
| #39 | Mobile AI summary + knowledge answers | the AI surfaces were web-only |
| #40 | Fixed Thai retrieval: titles indexed, spacing normalised, alias map; repaired mojibake in the answer copy | **"มือเท้าปาก" returned nothing at all** before this |
| #41-43 | Nightly backup that verifies its own artifact and restores it | a backup nobody has restored is a rumour |
| #44 | Retrieval benchmark with pass floors + the Qdrant decision document | replaced an opinion with measured numbers |
| #45 | Eight more DDC diseases (food poisoning, malaria, pneumonia, TB, rabies, gastroenteritis, cholera, measles) | corpus 7 -> 15 documents / 181 chunks |
| #46 | `ai:evaluate --live --base-url/--model` + the Pathumma/Kaggle recipe | a local Thai model can now be scored with the same suites |
| #47 | Repo-wide mojibake guard (`npm run check:mojibake`) and three repaired files | `PROJECT-STATE.md` had been silently destroyed by a PowerShell round-trip |
| #48 | Retention decision pack with a drift test | `dry_run` waits for a decision, not for code; the document now cannot go stale |
| #49 | Mobile appointments create/edit/cancel | a caregiver had to open a laptop to book a visit |
| #50 | `perf:smoke` load harness - and the N+1 it found | first measurement ever of the dashboard and timeline |
| #51 | ClamAV scanner behind a contract, fail closed | uploads were stored unscanned with no path to fix it |
| #52 | Mobile OCR scan + draft confirmation | OCR was web-only, and the AI still cannot write |
| #53 | PDPA data export API (scope-bounded, 15-minute link) | `data_export.*` existed with no route at all |
| #54 | Invitation list + revoke endpoints | a mistyped invite could not be withdrawn |
| #55 | Mobile family invite | invite-only onboarding needed the web app |
| #56 | Web data export card | the API had no screen |
| #57 | PROJECT-STATE refresh | keeps the handoff honest |
| #58 | Mobile data export card | closed the last export-parity gap |

## Numbers on `main` right now

| Suite | Result |
|---|---|
| backend `php artisan test` | **377 passed / 3,983 assertions** |
| web `npm test` | **126 passed / 42 files** |
| mobile `flutter test` | **116 passed** |
| `php artisan ai:evaluate` | all six suites pass (offline) |
| `php artisan knowledge:bench` | 28 questions - hit@1 0.893, hit@3 0.964, MRR 0.929 (15 docs / 181 chunks) |
| `php artisan perf:smoke` (Xdebug off) | dashboard p95 232 ms / 36 queries, timeline 299 ms / 48, tasks 216 ms / 40 |

`pint` clean (513 files), prettier clean, `check:encoding` and `check:mojibake` clean.
**58 merged PRs**, no open PRs.

## Bugs the work found (the most useful part)

1. **Thai retrieval was broken.** The title of a DDC page carries the disease name but the body never repeats
   it, and titles were not indexed - so the exact question a caregiver would ask returned **zero** sources.
2. **The answer copy was mojibake.** Every disclaimer and "not yet reviewed" warning rendered garbage, because
   the config held double-encoded Thai. Nothing failed; a human just read nonsense.
3. **`PROJECT-STATE.md` was destroyed by a PowerShell round-trip** (my own mistake). Fixed, and now impossible
   to reintroduce quietly: the mojibake guard fails CI.
4. **The task list ran ~54 queries per request** - presentation re-ran the owner lookup per row. Now ~40.
5. **The first backup workflow never dumped anything.** Three CI failures traced to one psql line
   (`datname = :'db'`, which psql does not expand inside `-c`) plus a heredoc that fed YAML to the server.
6. **The first export draft was not bounded by the requester's read grants** - a caregiver whose grant covered
   only vitals would have received medications and document metadata. The baseline (E8) says otherwise; the
   builder now filters section by section and records what it withheld.
7. **The export link lived 30 minutes**, not the blueprint's 15 (E7).

## Decisions still waiting for the owner (unchanged)

| # | Decision |
|---|---|
| D1 | Provider + DPA (AIG-010) before `AI_ENABLED=true` |
| D2 | Rotate the API keys that appeared in a chat transcript |
| D3 | Retention numbers and the privacy notice (the pack in `backend/docs/RETENTION-DECISION-PACK.md` is ready) |
| D4 | Turn ClamAV on (`DOCUMENTS_SCANNER=clamav`, `DOCUMENTS_SCAN_REQUIRED=true`) and run clamd |
| D5 | Pricing and plan entitlements |

## What I did not do, and why

- **No Qdrant.** The owner asked for it; the benchmark says lexical retrieval scores hit@3 = 0.964 with zero
  infrastructure, and the switch trigger is written down in `backend/docs/RAG-VECTOR-OPTIONS.md`. Building a
  vector store on a corpus of 181 chunks would have been motion, not progress.
- **AI stayed off.** `AI_ENABLED` is still `false`; a live evaluation exists as evidence, not as activation.
- **No knowledge-review UI.** Reviewing clinical sources needs an ops role, which does not exist in the
  authorization model; inventing one would require a change record, not a screen.
- **No mobile history pagination** - the remaining read-only gap.

## Where to start next time

Read `PROJECT-STATE.md`. Its section 5 lists the decisions above; section 6 lists the gaps. Both are current as
of this report.
