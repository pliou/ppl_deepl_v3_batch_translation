# Batch Translation UX Regression Notes

This file documents UI and workflow decisions that must not be silently reverted during later refactors.

## Selection Preset Placement

The selection preset belongs in the right action panel, not in the top translation settings area.

The top settings area is for translation context only:

- Source language
- Target language
- Glossary
- Style rule
- Custom instructions
- Site
- Scan / Restart scan

The right action panel owns selection and write behavior:

- Selection preset
- Live selection summary
- Show selected items
- Generate DeepL translation preview
- Write selected translations
- Regenerate preview
- Discard preview
- Clear selection

The visible selection preset options are intentionally limited to:

- Select only not translated
- Select everything

The preset controls the resolved preview/write plan for the already selected scope. It must not silently change the user's manual tree or review checkboxes. Users can still include/exclude pages and elements manually; the preset then decides whether existing target-language values are skipped or included for retranslation.

Do not reintroduce the full internal mode list into the UI. Internal modes may still exist for backwards compatibility, smoke cases, or stored jobs, but users should not see six competing modes in the normal workspace.

## Existing Translations In Review

If a selected page or content element already has a target-language record, the review UI must show that existing target value before any new DeepL preview exists.

Do not render `Translation will appear here.` for already translated records. That placeholder is only valid when no target-language value exists yet.

For already translated records:

- show the source value
- show the existing translation value
- mark the row as existing/translated
- label the preview action as a retranslation preview only when the current selection preset will actually overwrite that record

Expected labels:

- `Generate retranslation preview for this page`
- `Generate retranslation preview for this element`

This keeps the user from thinking the translation was lost or not written.

Under `Select only not translated`, records that already have complete target values must not show a per-page or per-element DeepL preview button. Showing a retranslation button in that state is misleading because the preset will skip those fields. Display the existing translation and a passive `No pending translation` state instead.

Copied default-language values are not finished translations. If a target value is identical to the source value after normalization, treat that field as not translated for status and missing-only planning.

## Page Status Aggregation

Page status is aggregate status, not just the `pages` record status.

A page is `translated` only when the page fields and all selectable content elements on that page are translated. If the page record exists but one or more content elements are missing, or if only one content element has been translated while the page record is still missing, the page must be shown as `partial`.

Example:

- source page has four content elements
- target page record exists
- only one `tt_content` translation exists, such as `Quick link`
- tree and review status must be `partial`, not `translated`

## Preview Versus Write

DeepL preview and DataHandler write remain separate steps:

- `Generate DeepL translation preview` may call DeepL and stores proposals.
- `Write selected translations` writes confirmed proposals via TYPO3 DataHandler.
- Existing target values are overwritten only when the user chose `Select everything` and then confirms the generated preview/write flow.

## Visibility After Write

The visibility switch applies to both newly created and already existing target records touched by the write job.

When visibility is `On`, target records written or localized by the job should be unhidden where permissions allow. Do not limit this to records with newly written fields only, because existing translated records may otherwise stay hidden even though the user explicitly chose visibility on.

## URL Slugs After Page Title Translation

When a translated page title is written, regenerate the localized page slug through TYPO3's slug handling so frontend links match the translated title.

Example:

- source: `Team Notizen`
- target title: `Team notes`
- target slug should become `/team-notes`, not remain `/team-notizen`

Existing result logs may contain older frontend URLs from before slug regeneration. Current records and newly written results should use the corrected slugs.
