# Adding a new language to FluxFiles

## Steps

1. Copy `lang/en.json` to `lang/{code}.json`
   Example: Thai → `lang/th.json`

2. Fill in `_meta`:
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

3. Translate all values. **Do NOT translate keys.**
   Keep all `{placeholder}` variables exactly as they are — only change their position in the sentence if needed.

4. For RTL languages (Arabic, Hebrew, Persian):
   Set `"direction": "rtl"` in `_meta`.

5. Open a Pull Request with the title: `i18n: add {language name} ({code})`

## Testing

```bash
php -r "
  require __DIR__ . '/../api/I18n.php';
  \$i = new FluxFiles\I18n(__DIR__, '{code}');
  echo \$i->t('upload.drop_hint') . PHP_EOL;
  echo \$i->t('file.items', ['count' => 5]) . PHP_EOL;
"
```

## Rules

- Do NOT delete or rename any key
- If you cannot translate a key yet, keep the English value
- `{varname}` placeholders must be preserved — only change their position in the sentence
- All keys present in `en.json` must exist in your translation file
