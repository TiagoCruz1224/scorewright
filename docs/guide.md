# Guide: did the prompt change make the assistant better?

This guide shows how to work with Scorewright, from an empty project to an answer you can defend. It follows one
example from start to end: the support assistant of the demo data, before and after a prompt change.

You build the evaluation yourself, with the demo's files, so you see every step. Then, because scoring 96 answers by
hand takes a while, you read the results of the same evaluation fully scored, in the demo project.

**Contents:** [What you will do](#what-you-will-do) · [The concepts](#the-concepts) ·
[The steps](#the-steps) · [The answer](#the-answer) · [Use it for your own system](#use-it-for-your-own-system)

## What you will do

You look after the support assistant of a fictional outdoor-gear shop. Its first prompt, `baseline-v1`, gives short
answers. A new prompt, `tuned-v2`, adds the shop's policies, a rule to apologise and a friendlier tone. Is it better?
And where is it worse?

You will:
1. create a project, and import 50 test cases and the answers of both prompts;
2. write a rubric: what makes an answer good, and how much each part counts;
3. start a blind evaluation, and score a few answers with the keyboard;
4. read the results and the comparison of "Release check", the same evaluation with all 96 answers scored;
5. export the scores as a CSV file.

**How long it takes:** about 30 minutes, once the stack runs. Scoring all 96 answers would take most of an hour more,
so you score six to learn how, and read the rest.

**Before you start:**
- Follow the [Quick start](../README.md#quick-start): the stack runs, and `app:demo` has loaded the demo data.
- Open http://localhost:5471 and sign in as `demo.owner@example.com`, with the password `scorewright-demo`. You use
  the demo owner so that you can open the demo project at the end. The project you build is a new one, next to it.
- The files are in [backend/demo](../backend/demo), in your clone of the repository: `cases.csv`,
  `run-baseline-v1.csv` and `run-tuned-v2.json`. Every case and answer in them is made up.

## The concepts

**Dataset and version.** A dataset is a list of test cases. A case is an input to send to the AI system, and
optionally an id, a reference answer and tags. Each import of a file makes a new version, and a version never changes,
so a result always refers to the exact cases that were scored.

**Run.** A run holds the answers that one model or prompt gave to the cases of one dataset version. You run your system
outside Scorewright, then import its answers. A run may leave cases unanswered.

**Rubric.** A rubric lists the criteria that answers are scored on. People score a 1–5 scale or a pass or fail, with
guidance that says how to choose. The app scores the automatic checks, such as a length limit or a pattern. Each
criterion has a weight, for the overall score. Once an evaluation uses a rubric, it is locked.

**Evaluation.** An evaluation applies a rubric to one to five runs of the same version. Each run gets a letter: A, B,
C…. People score every answer, and the automatic checks run in the background. When the owner closes it, its scores
never change again.

**Blind mode and roles.** In blind mode, the people who score do not see which run wrote an answer, and the answers of
each case come in a random order. So what they think of a prompt cannot sway their scores. A project has three roles:
- **owners** manage the project and its data, start and close evaluations, and can score too;
- **evaluators** score and comment, and see the results of a blind evaluation only once it is closed;
- **viewers** read.

## The steps

The labels in **bold** are the app's own: buttons, links, fields and the project's tabs (**Overview**, **Datasets**,
**Runs**, **Rubrics**, **Evaluations**, **Members**, **Settings**).

### 1. Create the project

On **Projects**, choose **Create project**. **Name:** `Support assistant`; the **Description** is optional. Choose
**Create project**.

The project opens on its **Overview**. Its **Next steps** are the next four steps of this guide: **Import a dataset**,
**Import runs**, **Create a rubric** and **Start an evaluation**.

### 2. Import the test cases

Look at `cases.csv` first. Each line after the header is a case:

```csv
id,input,reference,tags
refund-1,"I was charged twice for order #418220. Can I get one charge back?","Apologise, confirm the double charge on order #418220 and refund one of the two charges within 5 to 10 working days.","billing, refund"
refund-3,How long does a refund take to reach my card?,5 to 10 working days after we receive the return.,refund
```

- **`id`:** the case's key. The run files refer to it.
- **`input`:** what the assistant was asked. It is the only column that is required.
- **`reference`:** a good answer. The people who score read it, and the exact-match check compares with it. 4 of the
  50 cases have none: some questions have no single right answer.
- **`tags`:** separated by commas, in one cell. The results are broken down by tag, so tag what you will want to read
  apart: the topic (`refund`, `delivery`) and the risk (`prompt-injection`, `refusal`).

**First, an invalid file,** to see what happens. Copy `cases.csv` to `cases-broken.csv`, and in a text editor:
- on line 3, change the id `refund-2` to `refund-1`;
- on line 4, delete the input, so the line starts with `refund-3,,5 to 10`.

Choose **Import a dataset**. **Name:** `Support cases`. **CSV or JSON file:** `cases-broken.csv`. Choose **Check and
import**.

![New dataset, after an invalid file: "Import rejected: nothing was saved", 2 errors in 2 lines, then each error with its line, field and how to fix it: line 3, a duplicate id; line 4, an empty input](guide/import-rejected.png)

- **An import saves all or nothing.** Nothing was saved, and no dataset was created.
- **Every error has its line,** as a spreadsheet numbers it (the header is line 1), its field, and how to fix it.
- **The form keeps** the name and the file you chose.

**Then the real file.** **Choose another file** takes you to the file field. Pick `cases.csv`, and choose **Check and
import** again.

![The dataset "Support cases": "Version v1 created: 50 cases.", the version v1 from cases.csv, and its first cases with their input, reference and tags](guide/dataset.png)

- The dataset has one version, **v1**, with 50 cases. Below are the cases, which you can filter by **Tag** or **Text**.
- To change a case, fix the file and choose **Import new version**: it becomes v2, and v1 stays as it was.

### 3. Import the answers of both runs

A run file has two columns: `id`, the case it answers, and `output`, the answer. It can be CSV or JSON:

```csv
id,output
refund-1,You were charged twice. Contact billing.
```

```json
[
  {"id": "refund-1", "output": "Sorry about that! I can see two charges for order #418220, and I have refunded one of them. It reaches your card in 5 to 10 working days."}
]
```

In **Runs**, choose **Import run**:
- **Dataset version:** `Support cases v1 · 50 cases`;
- **Run label:** `baseline-v1`;
- **Model description:** optional. Say what made the answers, for example "The first support prompt.";
- **CSV or JSON file:** `run-baseline-v1.csv`.

Choose **Check and import**: "Run baseline-v1 imported: 50 answers (50 of 50 cases)."

Do it again with the label `tuned-v2` and the file `run-tuned-v2.json`: "46 answers (46 of 50 cases)". The new prompt
left 4 cases unanswered. That is allowed: unanswered cases are not scored, and the comparison uses only the cases that
both runs answered.

The run's page does not show its answers. You see them when you score them, where blind mode applies.

### 4. Write the rubric

In **Rubrics**, choose **New rubric**. **Rubric name:** `Support quality`. Then choose **Add criterion** five times,
and fill each one in:

| | Kind | Name | Settings | Weight |
|---|---|---|---|---|
| 1 | **Scale 1–5** | `Tone` | **Guidance:** `1: rude or cold. 3: polite but generic. 5: warm and clear, without filler.` | `2` |
| 2 | **Pass or fail** | `Follows the policy` | **Guidance:** `Pass when the answer keeps to the shop's rules: returns within 30 days, one refund per order, nothing about other customers, no made-up codes, dates or reviews.` | empty |
| 3 | **Automatic check** | `Mentions the order number` | **Check:** **Matches a regular expression**. **Pattern:** `#\d{6}` | empty |
| 4 | **Automatic check** | `Matches the reference` | **Check:** **Exact match with the reference**, with **Ignore case** ticked | empty |
| 5 | **Automatic check** | `Short enough` | **Check:** **Length limits**. **Maximum:** `160` | `0.5` |

Choose **Save rubric**. **Move up** and **Move down** change the order, which is the order the people who score see.

![The rubric "Support quality": 5 criteria, 2 manual and 3 automatic. Tone, a 1–5 scale with its guidance and weight 2; Follows the policy, pass or fail; Mentions the order number, the regex #\d{6}; Matches the reference, an exact match ignoring case; Short enough, at most 160 characters, weight 0.5](guide/rubric.png)

**Why each criterion:**
- **Tone, a 1–5 scale,** because the new prompt was meant to be friendlier, and friendliness comes in degrees. The
  guidance says what 1, 3 and 5 mean, so two people give the same answer the same score. Its weight is 2: tone is what
  the change was for.
- **Follows the policy, pass or fail,** because a rule is kept or broken: there is no half a refund too many. The
  guidance lists the rules, so the people who score do not need to know the shop.
- **Mentions the order number, a regex,** because a customer who gives an order number expects an answer about that
  order. `#\d{6}` passes when the answer has `#` and six digits. Most cases have no order number, and their answers fail
  for both runs alike, so read this one as a difference between the runs, not as a score of its own.
- **Matches the reference, an exact match,** because a few questions have one right answer, word for word, such as
  how long a refund takes. Most good answers are worded otherwise, so expect a low pass rate. The 4 cases without a
  reference are "not checked": a check that cannot run never counts as a fail.
- **Short enough, at most 160 characters,** because a support answer should fit in a few lines. It is a guard rail:
  it catches a prompt that makes the answers long. Its weight is 0.5, because length matters less than tone or the
  rules.

**The weights** set how much each criterion counts in the overall score; an empty weight counts as 1. The overall
score turns each criterion into a number from 0 to 100 (a 1–5 average as (average − 1) / 4 × 100, a pass rate as it
is), then averages them with the weights.

Until an evaluation uses it, you can change the rubric with **Edit rubric**. Then it is locked. To change it, use
**Duplicate**, and change the copy: the results of the earlier evaluations keep their meaning.

### 5. Start a blind evaluation

In **Evaluations**, choose **New evaluation**:
- **Name:** `Baseline vs tuned`;
- **Dataset version:** `Support cases v1 · 50 cases`;
- **Runs:** tick `baseline-v1` and `tuned-v2`. They get their letters in the order of the list, oldest first: the
  baseline is A, and the new prompt is B. The comparison then reads "B is … higher than A";
- **Rubric:** `Support quality · 5 criteria`;
- **Blind mode:** keep it on (it is on by default).

The form warns that `tuned-v2` does not answer every case: for those 4 cases, the one answer can only be the
baseline's. The foot of the form says what is to do: "96 answers to score · 2 manual criteria · 3 automatic".

![New evaluation: the name Baseline vs tuned, the version Support cases v1, both runs ticked with their letters A and B, the rubric, Blind mode on with the warning about tuned-v2, and "96 answers to score · 2 manual criteria · 3 automatic"](guide/new-evaluation.png)

Choose **Start evaluation**. The rubric is now locked.

### 6. The automatic checks

The evaluation's page opens. The automatic checks have already started: two workers run them in the background, and
the page updates every few seconds. On the development stack they take a few seconds.

![The evaluation Baseline vs tuned, open, in blind mode: manual scoring 0 of 96 answers, automatic checks "96 of 96 · done", and the progress by criterion: the two manual criteria at 0 / 96, the three checks at 96 / 96](guide/evaluation.png)

- **Automatic checks** says "96 of 96 · done" and "Every answer is checked." Each check gives every answer a Pass or a
  Fail, or "not checked" when it cannot run.
- **Progress by criterion** shows what is left: the manual criteria at 0 / 96.
- You can score while the checks run. If the workers stop, the page says the checks have stalled; **Run checks again**
  starts them again.

### 7. Invite an evaluator (optional)

Scoring goes faster, and is fairer, with more than one person. In **Members**, under **Invite a member**:
- **Email:** `demo.evaluator@example.com`;
- **Role:** **Evaluator**.

Choose **Invite**: "Demo Evaluator was added as an evaluator." Only people who already have an account can be added:
they choose **Register** first. There is no e-mail.

To see what the evaluator sees, sign in as `demo.evaluator@example.com` in a private window. The project's
**Overview** lists the evaluation under **Your evaluations**, with **Start scoring** or **Continue scoring**. Its
results are locked: "Results unlock when the owner closes this evaluation". Two people can score the same answer, and
the last save wins: agree beforehand on who scores what.

### 8. Score with the keyboard

On the evaluation's page, choose **Start scoring**. The page shows one answer at a time: the test case with its tags,
its input and its reference answer, then the answer, with "run hidden". On the right are the criteria to score.

![Score answers in blind mode: test case refund-1, with its input, reference answer and the answer, run hidden. On the right, Tone and Follows the policy scored, the three automatic checks, and the comment box. At the bottom, "Saved at" and "1 of 96 scored"](guide/scoring.png)

| Key | What it does |
|---|---|
| <kbd>1</kbd>–<kbd>5</kbd> | Scores the 1–5 scale: Tone |
| <kbd>P</kbd> <kbd>F</kbd> | Pass or fail: Follows the policy |
| <kbd>J</kbd> | The next answer with missing scores |
| <kbd>K</kbd> | The previous answer |
| <kbd>C</kbd> <kbd>Esc</kbd> | Goes to the comment box, and leaves it |

- **Every score saves at once.** The bar at the bottom says "Saved at 10:42" and how many answers have all their
  scores: "1 of 96 scored". <kbd>J</kbd> waits until the save is done.
- **An answer is scored** when every manual criterion has a score. The automatic checks are shown, but you do not
  score them.
- **A comment** is one per answer, apart from the scores, and saves when you leave the box. It shows on the case's
  page and in the export.
- **The keys never fire** while you type in a box, or with <kbd>Ctrl</kbd>, <kbd>Alt</kbd> or <kbd>Cmd</kbd>. The
  **Keyboard shortcuts: on** button turns them off.

Score six answers, the two of `refund-1`, `refund-2` and `refund-3`, with the guidance in front of you. Notice
"Answer 1 of 2 for this case": in blind mode, the first answer is not always A's.

### 9. Close the evaluation

When everyone has finished, the owner closes the evaluation. On its page, under **Close the evaluation**, choose
**Close evaluation**, then **Close evaluation** again to confirm.
- **Closing is final:** nobody can score any more, and the results stop changing.
- **In blind mode,** the evaluators can then see the results.
- **While the checks still run,** the app does not close it: "Not closed." Wait until they are done.

Close yours now, to see how it goes: the confirmation says "with 90 answers still unscored". Its results hold only
your six answers. For the rest of this guide, open the same evaluation fully scored: in **Projects**, open **Support
assistant (demo)**, then **Evaluations**, then **Release check**. It is closed, and every one of its 96 answers is
scored.

### 10. Read the results

In "Release check", choose **Results**.

![Results of Release check: the overall score, A 36.6 and B 64.9, then each criterion per run, with bars](screenshots/results.png)

**By criterion,** A is `baseline-v1` and B is `tuned-v2`:

| Criterion | Measure | A | B | What it says |
|---|---|---|---|---|
| Overall score | Weighted, 0–100 | 36.6 | 64.9 | B is better overall. The rows below say where. |
| Tone | Average, 1–5 | 2.3 | 4.7 | The change worked where it was meant to. |
| Follows the policy | Pass rate | 78% | 98% | B keeps the rules much more often. |
| Mentions the order number | Pass rate, automatic | 4% | 17% | B names the order more often. Read the difference, not the rate. |
| Matches the reference | Pass rate, automatic | 4% | 5% | Too strict for free text: it says little here. 4 and 2 answers are "not checked". |
| Short enough | Pass rate, automatic | 100% | 100% | B's longer, kinder answers still fit in 160 characters. |
| Answers with every score | Coverage | 50 of 50 | 46 of 46 | Read this first: no answer is missing a score. |

A run that left cases unanswered is scored on its own answers only: B on 46, A on 50.

**How often each score was given, and the tags,** further down the page:

![Results, further down: "Tone: how often each score was given", where A got mostly 2s and B only 4s and 5s, then "Tone by tag", with B higher than A on every tag, most on prompt-injection, +2.9](guide/results-by-tag.png)

- **The distribution** shows what an average hides. A got 33 of its 50 Tone scores at 2. B got only 4s and 5s:
  "Scored 4 or 5: A on 6 of 50 answers, B on 46 of 46 answers."
- **By tag,** B is higher than A on every tag, and most on `prompt-injection` (+2.9): the baseline gave in to those
  messages, and curtly. A tag with few cases, such as `smalltalk` with 3, moves a lot with one answer: read it with
  care.
- Both are for Tone, the rubric's first 1–5 scale. **Show this chart as a table** gives the same numbers as a table.

### 11. Compare the runs

Choose **Compare**. At the top, **Run** and **Compared with** choose the two runs; **Update** applies them. With more
than two runs, you can compare any two.

![Compare: B against A, with each difference in words, such as "B is 2.4 points higher than A" on Tone, then each criterion with B − A](screenshots/compare.png)

- **The summary** gives the overall score and the first three criteria, with each difference in words:
  "▲ B is 28.3 points higher than A", "▲ B is 22 percentage points higher than A".
- **Only the cases both runs answered count:** "On the 46 cases both runs answered". That is why Follows the policy
  reads 76% for A here, and 78% in Results: the 4 cases that B skipped weigh on neither side.
- **By criterion** gives B − A for every criterion, and **Tone by tag** the same per tag.

**The largest disagreements** are where to read the answers. They are the cases where A and B got the most different
scores: a pass against a fail first, then each point apart on the 1–5 scale.

![Largest disagreements: rows with the case, the criterion, the input, A's score, B's score and B − A. Most are the order number and the policy, where B passes and A fails; refund-3 and product-4 go the other way](guide/disagreements.png)

- The list has at most 20 rows, a case and a criterion each. Here, most are the order number, which B mentions, and
  the policy, which A broke.
- **Two go the other way,** and they are the ones to read:
  - `refund-3`, where A copied the reference word for word and B, correct, worded it otherwise: a limit of the exact
    match;
  - `product-4`, where B makes up a restock date that the shop does not have: a real policy failure.

**The case page:** choose a case, such as `refund-6`. It shows the input and the reference answer, then each run's
answer with all its scores and its comment. **Previous** and **Next disagreement** go through the list.

![Case refund-6, "Largest disagreement 5 of 18 on Tone": the message asks for a refund twice. A refunded twice, with Tone 1, Follows the policy Fail and a comment; B declined politely, with Tone 5 and Follows the policy Pass](guide/case.png)

Here, the customer asks the assistant to break a rule. A refunded the order twice; B declined politely and offered to
check the first refund.

### 12. Export the results

On **Results** or **Compare**, choose **Export results (CSV)**. The file is `release-check-results.csv`, with one row per
answer: 96 rows.

| Columns | Hold |
|---|---|
| `case_key`, `tags`, `input`, `reference` | the case |
| `run_letter`, `run_label`, `output` | the run and its answer |
| one column per criterion | `1` to `5`, `pass`, `fail`, or `check_error:missing_reference` when a check could not run |
| `comment`, `comment_by` | the comment and who wrote it |

It opens in a spreadsheet. A cell that starts with `=`, `+`, `-` or `@` gets a `'` in front, so no formula can run.
The columns are in [docs/api.md](api.md#export).

## The answer

Yes: `tuned-v2` is better. It is kinder on every tag, it keeps the rules on 98% of the cases where the baseline keeps
them on 76%, and its answers are still short.

It is not better everywhere. On `product-4`, it makes up a restock date. That is the next prompt change, and the next
evaluation: import its answers as a new run of the same dataset version, such as `tuned-v3`, and score it with the
same rubric, so the numbers stay comparable.

## Use it for your own system

### Put your answers in the import format

1. **Collect the test cases** in a CSV or JSON file, with the columns of `cases.csv`. Take real questions (without
   personal data) and add the hard ones: requests to break a rule, prompt injections, questions to refuse. Give each
   case an `id` and tags, and a reference answer where there is one right answer.
2. **Run your system** on every input, outside Scorewright, and write one answer per case, with the case's `id`. For
   example, in Python, with your own `answer()` function:

   ```python
   import csv

   def answer(text):
       ...  # call your model or assistant with this prompt, and return its answer as a string

   with open('cases.csv', newline='', encoding='utf-8') as cases, open('run-tuned-v3.csv', 'w', newline='', encoding='utf-8') as run:
       writer = csv.writer(run)
       writer.writerow(['id', 'output'])
       for case in csv.DictReader(cases):
           writer.writerow([case['id'], answer(case['input'])])
   ```

3. **Import each prompt or model as a run,** with a label that says what it is, such as `tuned-v3` or
   `model-x-temperature-0`. Keep its settings in **Model description**.

The rules, field by field, are in [docs/api.md: Import format](api.md#import-format) and
[Run files](api.md#run-files). The app has them too: **CSV and JSON format**, under the file field.

### Write good criteria

- **One thing per criterion.** "Polite and correct" is two criteria: an answer can be one without the other.
- **Pass or fail for rules, a 1–5 scale for degrees.**
- **Say in the guidance what the ends mean,** in words about your data: "5: warm and clear, without filler" helps
  more than "5: excellent".
- **Automatic checks for what a machine can see for sure:** a format (**Valid JSON**), a word that must not appear
  (**Does not contain a text**), a length, a pattern. Leave judgement to people.
- **Try the rubric on a few answers first.** Score ten answers with someone else; where you disagree, make the
  guidance clearer. Once an evaluation uses the rubric, it is locked.
- **Few, simple weights:** 1 for most, 2 for what the change is about, 0.5 for what matters less.
- **Keep the same rubric** for the evaluations you want to compare.

### Common mistakes

- **Runs of different versions.** An evaluation takes the runs of one dataset version. When you add cases, import a
  new version, and run every system again on it.
- **Ids that do not match.** A run's `id` must be a case id of the version, or the import is rejected
  (`unknown_case_key`). Keep an explicit `id` column in your cases.
- **A spreadsheet that changes the file.** Saving from a spreadsheet can change the encoding (it must be UTF-8), turn
  an id such as `0012` into `12`, or a long one into `1.2E+15`. Look at the file in a text editor before you
  import it.
- **Blind mode off to save time.** The person who scores then knows which answer is the new prompt's. Keep it on.
- **Closing too early.** Closing is final. Check **Answers with every score** first; the confirmation also says how
  many answers are still unscored.
- **Reading only the overall score.** It mixes every criterion. Read the criteria, the tags and the largest
  disagreements, and open the cases.
- **An exact match on free text.** It fails good answers that are worded otherwise, as on `refund-3`. Use it for
  short answers with one right form.
- **Too few cases per tag.** A tag with three cases moves a lot with one answer. Aim for ten or more cases per tag that
  you want to read.
