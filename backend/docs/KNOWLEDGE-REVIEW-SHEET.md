# Knowledge review sheet — what each source must be able to answer (F)

Companion to [`KNOWLEDGE-REVIEW-PACK.md`](KNOWLEDGE-REVIEW-PACK.md). The pack tells a reviewer *what* each source
is; this sheet tells them **what the product will ask it**, so a review is a coverage test and not just a
read-through. Every question below comes from the 28-question benchmark in `database/knowledge/benchmark` that the
retrieval layer is measured against (hit@1 0.929, hit@3 1.000, MRR 0.964).

How to use it, per source:

1. Open `/admin/knowledge`, pick the source, read the extract in the pack.
2. For each question under that source, answer **yes / partly / no** — *would a caregiver get a correct, safe
   answer from this text as it stands?*
3. Record the verdict with the review action. "Partly" is useful: it names the gap to fill later, and the answer
   will still carry the "not yet reviewed by a clinician" warning until every source is reviewed.
4. If a question has **no** owning source, that is the most valuable finding of the whole review: a benchmark
   question without a source is a question the product would answer from thin air.

> Clinical judgement belongs to the reviewer. This sheet only makes the coverage visible; it does not decide
> whether the medical content is right.

---

## Group 1 — the five sources a Thai family actually hits first

### 1. Hand, foot and mouth disease (โรคมือเท้าปาก)

| Question the product must answer | Yes/Partly/No |
|---|---|
| What are the early signs in a child, and which ones mean "go to hospital now"? | |
| How long is a child contagious, and when can they return to school? | |
| What do we do about food and drink refusal? | |
| Is it dangerous for a pregnant caregiver? | |

### 2. Leptospirosis (โรคฉี่หนู / เลปโตสไปโรซิส)

| Question the product must answer | Yes/Partly/No |
|---|---|
| Which symptoms after walking through flood water mean "see a doctor today"? | |
| Can it be caught from animals at home, not only from floods? | |
| Why is it dangerous to treat with common painkillers alone? | |
| What is the treatment window? | |

### 3. Influenza (ไข้หวัดใหญ่)

| Question the product must answer | Yes/Partly/No |
|---|---|
| How is it different from a common cold, in words a family understands? | |
| Who in the household is at risk of severe illness? | |
| When is antiviral treatment worth asking about? | |
| When is vaccination worth it, and how often? | |

### 4. Dengue (ไข้เลือดออก)

| Question the product must answer | Yes/Partly/No |
|---|---|
| Which warning signs ( bleeding, abdominal pain, vomiting ) mean emergency? | |
| What is the fever-day pattern, and why does "the fever dropped" not mean recovery? | |
| Which painkillers must be avoided? | |
| How do we protect the household from mosquitoes? | |

### 5. PM2.5 / air quality

| Question the product must answer | Yes/Partly/No |
|---|---|
| What AQI/PM2.5 level should change what a family does? | |
| Who is most at risk (children, elderly, asthma, pregnancy)? | |
| What actually helps indoors? | |
| When should a mask be used, and which kind? | |

---

## Group 2 — the remaining nine sources

For these the review is faster: confirm the source is the right authority, that the extract still matches the live
page, and that the topic is one a caregiver would plausibly ask about.

| # | id | Title (from the corpus) | Plausible for a family? | Extract matches source? | Notes |
|---|---|---|---|---|---|
| 6 | `ddc-disease-10` | อาหารเป็นพิษ (Food Poisoning) | | | common at home |
| 7 | `ddc-disease-17` | มาลาเรีย (Malaria) | | | travel-related |
| 8 | `ddc-disease-21` | โรคปอดบวม, ปอดอักเสบ (Pneumonia) | | | elderly risk |
| 9 | `ddc-disease-23` | วัณโรค (TB, ODPC5) | | | household transmission |
| 10 | `ddc-disease-25` | พิษสุนัขบ้า (Rabies) | | | dog-bite triage |
| 11 | `ddc-disease-67` | ทางเดินอาหารอักเสบเฉียบพลันจากไวรัส (Viral gastroenteritis) | | | overlaps Group 1 #1? |
| 12 | `ddc-disease-73` | การบาดเจ็บจากการจราจรทางถนน (Road traffic injury) | | | first-aid scope |
| 13 | `ddc-disease-74` | อหิวาตกโรค (Cholera) | | | outbreak only |
| 14 | `ddc-disease-75` | โรคหัด (Measles, Rubella) | | | vaccination |

The two supporting sources - `thaihealth-lepto-symptoms` and `thaihealth-pm25-symptoms` - are reviewed together
with their Group 1 topic, because they exist to cover the symptom wording a caregiver actually types.

**Current state: 0 of 16 reviewed.** Until every source is reviewed, each AI answer carries the
"not yet reviewed by a clinician" warning (`KNOWLEDGE-BASE.md`).

## Running the session (about 45 minutes, one reviewer)

```bash
cd backend

# 1. give the reviewer the platform flag (authorisation is never role-derived)
php artisan platform:grant doctor@example.test

# 2. review in the console, top to bottom of this sheet
#    https://<host>/admin/knowledge     (the warning clears per source as you go)

# 3. or record a verdict from the CLI, which is faster for a batch
php artisan knowledge:review ddc-disease-11 --reviewer="<typed name>"

# 4. prove the decisions are in the corpus, not only in the browser
php artisan knowledge:review-pack     # regenerate the pack, now showing what is reviewed
php artisan knowledge:backup          # archive the reviewed corpus
php artisan knowledge:restore-drill   # and prove the archive carries the review fields
```

The reviewer name is validated server-side (U+FFFD and letter-less names are rejected), so a Thai name typed into
the console is stored intact - but it must be **typed**, never written through a shell (root `AGENTS.md` section 7).

---

## Verdict code

| Verdict | Meaning for the product |
|---|---|
| Reviewed, accurate | The warning on AI answers is dropped for this source |
| Reviewed, gap noted | Reviewed, but the gap above is recorded; the source stays in the corpus |
| Rejected | Excluded from retrieval; a replacement is needed before the topic is answerable |

After the session: `php artisan knowledge:review <source-id> --reviewer="<name>"` (or the button in
`/admin/knowledge`), then `php artisan knowledge:restore-drill` on the latest archive to prove the decisions are
inside the backup, not just in the running container.
