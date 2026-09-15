<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use Closure;

abstract class BasePackageProcessor
{
    /**
     * Process the given package context.
     */
    abstract public function process(PackageContext $context);

    /**
     * Pipeline pass-through handler.
     */
    public function handle(PackageContext $context, Closure $next): mixed
    {
        $this->process($context);

        return $next($context);
    }
}
