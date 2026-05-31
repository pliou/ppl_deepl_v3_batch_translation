# Production Hardening Notes

This extension separates DeepL suggestions from TYPO3 writes. A job is writable only when the current backend user created it, or the current backend user is an administrator, and the job still matches the current selection.

## Fake DeepL

The smoke provider is only available in TYPO3 Development or Testing context. The smoke command deactivates the persistent context by default; keeping it active requires `--keep-fake-active` and still only works in Development or Testing.

## Source Languages

The page tree is anchored to default-language records so selections stay stable. When a non-default source language is selected, source values are read from the connected localization of each selected base record. Missing source localizations are blocked with `source_missing` and must not create target records.

TYPO3 writes still start from the base record through DataHandler `localize`. When the source language is non-default, the created target record receives the configured translation source field when TYPO3 exposes one for the table.

## Preview And Write

Provider errors or missing translated field values create a `preview_failed` audit job but no confirmed preview job. The write action stays unavailable until a `previewed` job with executable work exists.

Execution rechecks job ownership, source records, target language access, table permissions, page permissions and expected translated values immediately before each write.

## Jobs And Exports

CSV export requires the same form token and job ownership as write/discard actions. Result CSV files include stable status and error codes, base/source/target UIDs, language IDs and record links.

Use `ppl:batch-translation:cleanup-jobs` for retention cleanup. It is a dry-run unless `--execute` is provided. Use `ppl:batch-translation:execute-job --job=<uid> --be-user=<uid> --force` for prepared large preview jobs.
