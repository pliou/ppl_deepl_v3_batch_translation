# PPL DeepL V3 Batch Translation

TYPO3 14 backend workspace for controlled batch translation of `pages` and `tt_content` records through `ppl_deepl_v3_requests`.

## Related DeepL V3 Packages

The DeepL V3 line is split into focused TYPO3 extensions:

- `ppl/ppl-deepl-v3-requests` (`ppl_deepl_v3_requests`): shared request and configuration layer. It owns the DeepL API key lookup, endpoint selection, HTTP calls, language/glossary/style-rule fetches, custom instructions and shared approval storage.
- `ppl/ppl-deepl-v3-translate` (`ppl_deepl_v3_translate`): frontend content elements and backend modules for interactive text and file translation.
- `ppl/ppl-deepl-v3-batch-translation` (`ppl_deepl_v3_batch_translation`): this package. It resolves selected page trees, pages and content elements into translation plans, previews the work and writes TYPO3 records through controlled backend flows.
- `ppl/ppl-deepl-v3-extension-translator` (`ppl_deepl_v3_extension_translator`): backend audit and repair module for extension XLF files, missing translation keys and selected write actions with backups.

Batch Translation must use `ppl_deepl_v3_requests` for DeepL calls and shared approved languages, glossaries, style rules and custom-instruction presets. It must not perform raw DeepL HTTP calls itself.

## Important UI Contracts

Before changing the workspace UI, read:

- [Batch Translation UX Regression Notes](Documentation/ux-regression-notes.md)
- [Production Hardening Notes](Documentation/production-hardening.md)

That file documents decisions that should not be silently reverted, including selection preset placement, existing translation display, preview/write separation, visibility handling, and localized slug regeneration.
