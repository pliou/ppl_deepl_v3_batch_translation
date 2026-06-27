<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Enum;

enum TranslationMode: string
{
    case CreateMissingRecordsOnly = 'create_missing_records_only';
    case TranslateMissingOnly = 'translate_missing_only';
    case TranslateSelectedSkipExisting = 'translate_selected_skip_existing';
    case RetranslateSelected = 'retranslate_selected';
    case UpdateEmptyFieldsOnly = 'update_empty_fields_only';
    case PreviewOnly = 'preview_only';

    public static function fromRequestValue(string $value): self
    {
        return self::tryFrom($value) ?? self::TranslateMissingOnly;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::TranslateMissingOnly->value => 'Select only not translated',
            self::RetranslateSelected->value => 'Select everything',
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::TranslateMissingOnly => 'Select only not translated',
            self::RetranslateSelected => 'Select everything',
            self::CreateMissingRecordsOnly => 'Create missing records only',
            self::TranslateSelectedSkipExisting => 'Translate selected, skip existing',
            self::UpdateEmptyFieldsOnly => 'Update empty fields only',
            self::PreviewOnly => 'Preview only',
        };
    }

    public function translatesText(): bool
    {
        return $this !== self::CreateMissingRecordsOnly;
    }

    public function allowsOverwrite(): bool
    {
        return $this === self::RetranslateSelected;
    }

    public function isPreviewOnly(): bool
    {
        return $this === self::PreviewOnly;
    }
}
