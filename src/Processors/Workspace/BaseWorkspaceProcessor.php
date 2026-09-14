<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Closure;

abstract class BaseWorkspaceProcessor
{
    /**
     * Process workspace logic.
     *
     * @return mixed
     */
    abstract public function process(WorkspaceContext $context);

    /**
     * Pipeline pass-through handler.
     */
    public function handle(WorkspaceContext $context, Closure $next): mixed
    {
        $this->process($context);

        return $next($context);
    }
}
