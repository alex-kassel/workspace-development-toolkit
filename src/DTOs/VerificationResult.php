<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public string $status,
        public ?string $reason = null,
        public ?AuditReport $certificate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'status' => $this->status,
            'reason' => $this->reason,
            'certificate' => $this->certificate?->toArray(),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
