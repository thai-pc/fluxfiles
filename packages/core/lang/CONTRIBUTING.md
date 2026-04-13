# Adding a new language to FluxFiles

Thanks for helping translate FluxFiles! This guide walks you from a fresh fork all the way to a merged Pull Request.

## Quick start (TL;DR)

```bash
# 1. Fork on GitHub (click "Fork" on https://github.com/thai-pc/fluxfiles)

# 2. Clone your fork and enter the lang dir
git clone git@github.com:YOUR-USERNAME/fluxfiles.git
cd fluxfiles/packages/core/lang

# 3. Create a branch for your language (use the ISO 639-1 code)
git checkout -b i18n/add-th

# 4. Copy English as the starting template
cp en.json th.json

# 5. Edit th.json — translate values, keep keys + {placeholders} untouched

# 6. Verify
cd ../..   # back to packages/core
php tests/test-i18n.php

# 7. Commit and push to your fork
git add lang/th.json
git commit -m "i18n: add Thai (th)"
git push -u origin i18n/add-th

# 8. Open a PR from your fork to thai-pc/fluxfiles:master
gh pr create --title "i18n: add Thai (th)" --body "Adds Thai translation." --repo thai-pc/fluxfiles
# (or use the GitHub UI — the 'Compare & pull request' banner appears after push)
```

---

## Step-by-step

### 1. Fork the repo

Go to <https://github.com/thai-pc/fluxfiles> and click **Fork** (top-right). This creates `github.com/YOUR-USERNAME/fluxfiles` that you can push to freely.

### 2. Clone your fork

```bash
git clone git@github.com:YOUR-USERNAME/fluxfiles.git
cd fluxfiles
```

Optionally, add the upstream remote so you can pull in updates later:

```bash
git remote add upstream git@github.com:thai-pc/fluxfiles.git
git fetch upstream
```

### 3. Create a feature branch

Never commit directly to `master` on your fork — branch names make the PR easier to review:

```bash
git checkout -b i18n/add-{code}     # e.g. i18n/add-th
```

### 4. Create the translation file

```bash
cp packages/core/lang/en.json packages/core/lang/{code}.json
```

The `{code}` must be an [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) lowercase code (e.g. `th`, `pl`, `sv`).

### 5. Fill in `_meta`

```json
{
  "_meta": {
    "locale":    "th",
    "name":      "ภาษาไทย",
    "direction": "ltr",
    "version":   "1.20.0",
    "authors":   ["Your Name"]
  }
}
```

For RTL languages (Arabic, Hebrew, Persian, Urdu), set `"direction": "rtl"`.

### 6. Translate values

- **Do NOT translate keys** — only the string values on the right side.
- Keep `{placeholder}` variables exactly as they are (e.g. `{count}`, `{name}`). You may move them around in the sentence if grammar requires, but don't rename them.
- If a key can't be translated yet, keep the English value — all keys from `en.json` must be present.

### 7. Verify locally

Run the i18n test suite — it validates JSON, key completeness, placeholder preservation, and plural rules:

```bash
cd packages/core
php tests/test-i18n.php
```

Or check a single key manually:

```bash
php -r "
  require __DIR__ . '/api/I18n.php';
  \$i = new FluxFiles\I18n(__DIR__ . '/lang', 'th');
  echo \$i->t('upload.drop_hint') . PHP_EOL;
  echo \$i->t('file.items', ['count' => 5]) . PHP_EOL;
"
```

All 16+ languages must pass before your PR is merged.

### 8. Commit and push

```bash
git add packages/core/lang/{code}.json
git commit -m "i18n: add {Language Name} ({code})"
git push -u origin i18n/add-{code}
```

### 9. Open a Pull Request

**Using the GitHub CLI:**

```bash
gh pr create \
  --repo thai-pc/fluxfiles \
  --base master \
  --title "i18n: add {Language Name} ({code})" \
  --body "Adds {language} translation for v1.20+.\n\nTested: \`php tests/test-i18n.php\` passes."
```

**Using the GitHub web UI:**

1. Push your branch (step 8).
2. GitHub shows a yellow "Compare & pull request" banner on your fork — click it.
3. Confirm base is `thai-pc/fluxfiles:master` and compare is `YOUR-USERNAME:i18n/add-{code}`.
4. Use the same title format: `i18n: add {Language Name} ({code})`.

### 10. Respond to review

A maintainer will review. Address feedback by pushing more commits to the same branch — the PR updates automatically.

---

## Updating an existing translation

Same flow — fork, branch (`i18n/update-{code}`), edit, test, PR. Use a title like `i18n: update Thai (th) — fix metadata labels`.

## Rules (quick reference)

- Do NOT delete or rename any key.
- All keys present in `en.json` must exist in your file.
- Keep `{placeholder}` names exactly as in English; only reorder if grammar requires.
- If unsure, leave the English value as a fallback rather than omitting the key.
- Run `php tests/test-i18n.php` before pushing.

## Need help?

Open an issue at <https://github.com/thai-pc/fluxfiles/issues> with the `i18n` label and describe what you're stuck on.
