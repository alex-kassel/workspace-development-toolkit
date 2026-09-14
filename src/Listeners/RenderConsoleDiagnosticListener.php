<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Listeners;

use AlexKassel\WorkspaceDevelopmentToolkit\Events\ConsoleDiagnosticDispatched;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ConsoleUiRenderer;

class RenderConsoleDiagnosticListener
{
    public function __construct(
        protected ConsoleUiRenderer $renderer,
    ) {}

    /**
     * Handle the console diagnostic event.
     */
    public function handle(ConsoleDiagnosticDispatched $event): void
    {
        $this->renderer->render($event);
    }
}
