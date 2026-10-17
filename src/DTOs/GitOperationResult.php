<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class GitOperationResult
{
    public function __construct(
        public string $repository,
        public string $action,
        public bool $success,
        public string $message = '',
        public string $output = '',
        public bool $changed = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository,
            'action' => $this->action,
            'success' => $this->success,
            'changed' => $this->changed,
            'message' => $this->message,
        ];
    }
}
