<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

class InstallContext
{
    /**
     * @param  array<int, array{action: string, status: string, message: string}>  $steps
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly bool $isSelf = false,
        public readonly ?string $selfPackagePath = null,
        public readonly string $defaultWorkspace = 'packages',
        public readonly bool $force = false,
        public readonly bool $skipCleanup = false,
        public array $steps = [],
    ) {}

    public function recordStep(string $action, string $status, string $message): void
    {
        $this->steps[] = [
            'action' => $action,
            'status' => $status,
            'message' => $message,
        ];
    }
}
