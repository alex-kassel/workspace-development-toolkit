<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class AmbiguousPackageException extends WorkspaceException
{
    /**
     * @param  array<int, string>  $matches
     */
    public function __construct(
        string $packageRef,
        protected array $matches,
    ) {
        $matchesList = implode("\n", array_map(fn ($m) => "  • {$m}", $matches));

        $message = "Ambiguous package reference [{$packageRef}]. Multiple matches found across workspaces:\n{$matchesList}";

        $solution = 'Specify the workspace explicitly using [--workspace=<path>] or use the full canonical vendor/package name.';

        parent::__construct($message, $solution);
    }

    /**
     * @return array<int, string>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }

    public function errorCode(): string
    {
        return 'WS_AMBIGUOUS_PACKAGE';
    }

    public function agentInstructions(): ?string
    {
        return 'Disambiguate package reference by specifying --workspace=<workspace> or using full vendor/package name.';
    }
}
