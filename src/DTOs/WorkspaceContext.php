<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

class WorkspaceContext
{
    /**
     * @param  array<int, string>  $workspaces
     * @param  array<int, array{processor: string, status: string, message: string}>  $steps
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly array $workspaces = [],
        public readonly ?string $defaultWorkspace = null,
        public readonly bool $isSelf = false,
        public readonly ?string $selfPackagePath = null,
        public readonly bool $force = false,
        public readonly bool $skipCleanup = false,
        public array $steps = [],
    ) {}

    public function recordStep(string $processor, string $status, string $message): void
    {
        $this->steps[] = [
            'processor' => $processor,
            'status' => $status,
            'message' => $message,
        ];
    }

    public function manifestPath(): string
    {
        return $this->rootPath.DIRECTORY_SEPARATOR.'workspace.json';
    }

    public function workspacePath(?string $workspace = null): ?string
    {
        $ws = $workspace ?? $this->defaultWorkspace;

        if ($ws === null) {
            return null;
        }

        $clean = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $ws), DIRECTORY_SEPARATOR);

        return $this->rootPath.DIRECTORY_SEPARATOR.$clean;
    }

    public function agentsPath(): string
    {
        return $this->rootPath.DIRECTORY_SEPARATOR.'AGENTS.md';
    }

    public function boostJsonPath(): string
    {
        return $this->rootPath.DIRECTORY_SEPARATOR.'boost.json';
    }

    public function runnerPath(): string
    {
        return $this->rootPath.DIRECTORY_SEPARATOR.'workspace';
    }
}
