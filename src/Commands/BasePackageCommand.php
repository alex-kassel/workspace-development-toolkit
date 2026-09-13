<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Console\Command;

abstract class BasePackageCommand extends Command
{
    protected WorkspaceManager $workspace;

    protected ComposerManager $composer;

    public function __construct(?WorkspaceManager $workspace = null, ?ComposerManager $composer = null)
    {
        parent::__construct();

        $this->workspace = $workspace ?? app(WorkspaceManager::class);
        $this->composer = $composer ?? app(ComposerManager::class);
    }

    /**
     * Standardized renderer for WorkspaceException errors and their actionable solutions.
     */
    protected function handleWorkspaceException(WorkspaceException $e): int
    {
        $this->error($e->getMessage());
        if ($e->getSolution()) {
            $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
        }

        return self::FAILURE;
    }

    /**
     * Standardized warning renderer when a chosen alias or name conflicts with another package in the workspace.
     */
    protected function warnIfDuplicateAlias(string $alias, string $currentPath): void
    {
        $duplicates = $this->workspace->findDuplicateAliases($alias, $currentPath);
        if (empty($duplicates)) {
            return;
        }

        $this->newLine();
        $this->warn("Notice: The alias/name [{$alias}] is also used by another package:");
        foreach ($duplicates as $duplicate) {
            $this->line("  • {$duplicate}");
        }
        $this->newLine();
        $this->line("  <comment>Hint:</comment> Both packages will work normally in Composer, but resolving by short name '{$alias}' will be ambiguous.");
        $this->line('  If you wish to differentiate them, you can assign a unique alias:');
        $this->line("  <info>php artisan package:alias {$currentPath} UniqueAlias</info>");
    }

    /**
     * Normalize a raw package input string (e.g. windows slashes, whitespace).
     */
    protected function normalizePackageInput(string $input): string
    {
        return str_replace('\\', '/', trim($input));
    }
}
