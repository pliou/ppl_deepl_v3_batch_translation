<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class PermissionResult
{
    /**
     * @param string[] $reasons
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $reasons = []
    ) {}

    public static function allowed(): self
    {
        return new self(true, []);
    }

    public static function blocked(string ...$reasons): self
    {
        return new self(false, array_values(array_filter($reasons, static fn(string $reason): bool => $reason !== '')));
    }

    public function merge(self $other): self
    {
        return new self(
            $this->allowed && $other->allowed,
            array_values(array_unique(array_merge($this->reasons, $other->reasons)))
        );
    }

    /**
     * @return array{allowed: bool, reasons: string[]}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reasons' => $this->reasons,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            !empty($data['allowed']),
            array_values(array_map('strval', is_array($data['reasons'] ?? null) ? $data['reasons'] : []))
        );
    }
}
