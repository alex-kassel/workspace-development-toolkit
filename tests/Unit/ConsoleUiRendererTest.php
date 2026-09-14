<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Events\ConsoleDiagnosticDispatched;
use AlexKassel\WorkspaceDevelopmentToolkit\Listeners\RenderConsoleDiagnosticListener;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ConsoleUiRenderer;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleUiRendererTest extends TestCase
{
    public function test_renders_error_card_with_context_and_remediation(): void
    {
        $renderer = new ConsoleUiRenderer;
        $output = new BufferedOutput;

        $event = new ConsoleDiagnosticDispatched(
            code: 'WS_WORKSPACE_NOT_FOUND',
            message: 'Workspace [pupok] is not registered.',
            severity: DiagnosticSeverity::Error,
            context: [
                'Available workspaces' => ['app/Cores', 'packages'],
            ],
            remediationSteps: [
                'php artisan workspace:add pupok',
                'php artisan workspace:list',
            ],
            agentGuidance: 'Choose an existing workspace.',
            output: $output,
        );

        $renderer->render($event);

        $rendered = $output->fetch();

        $this->assertStringContainsString('ERROR', $rendered);
        $this->assertStringContainsString('[WS_WORKSPACE_NOT_FOUND] Workspace [pupok] is not registered.', $rendered);
        $this->assertStringContainsString('Available workspaces:', $rendered);
        $this->assertStringContainsString('app/Cores', $rendered);
        $this->assertStringContainsString('packages', $rendered);
        $this->assertStringContainsString('How to fix:', $rendered);
        $this->assertStringContainsString('php artisan workspace:add pupok', $rendered);
        $this->assertStringContainsString('Agent guidance:', $rendered);
        $this->assertStringContainsString('Choose an existing workspace.', $rendered);
    }

    public function test_renders_warning_card(): void
    {
        $renderer = new ConsoleUiRenderer;
        $output = new BufferedOutput;

        $event = new ConsoleDiagnosticDispatched(
            code: 'WS_WARNING_TEST',
            message: 'Caution required.',
            severity: DiagnosticSeverity::Warning,
            output: $output,
        );

        $renderer->render($event);

        $rendered = $output->fetch();
        $this->assertStringContainsString('WARNING', $rendered);
        $this->assertStringContainsString('[WS_WARNING_TEST] Caution required.', $rendered);
    }

    public function test_listener_invokes_renderer(): void
    {
        $output = new BufferedOutput;
        $event = new ConsoleDiagnosticDispatched(
            code: 'WS_EVENT_TEST',
            message: 'Dispatched via event.',
            output: $output,
        );

        $renderer = new ConsoleUiRenderer;
        $listener = new RenderConsoleDiagnosticListener($renderer);
        $listener->handle($event);

        $rendered = $output->fetch();
        $this->assertStringContainsString('[WS_EVENT_TEST] Dispatched via event.', $rendered);
    }
}
