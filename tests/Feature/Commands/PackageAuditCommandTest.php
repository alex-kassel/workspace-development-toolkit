<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\VerificationResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CertificateVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageAuditCommandTest extends TestCase
{
    protected function scaffoldPackage(string $relPath, string $name): string
    {
        $dir = base_path($relPath);
        File::ensureDirectoryExists($dir.'/src');
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => $name]));
        File::put($dir.'/.gitattributes', "/tests export-ignore\n");
        File::put($dir.'/README.md', "# Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        return $dir;
    }

    public function test_package_audit_requires_name_argument(): void
    {
        $this->artisan('package:audit')
            ->expectsOutputToContain('Please specify a package in vendor/package format')
            ->assertFailed();
    }

    public function test_package_audit_fails_on_nonexistent_package(): void
    {
        $this->artisan('package:audit', ['package' => 'nonexistent/pkg'])
            ->expectsOutputToContain('Package [nonexistent/pkg] not found')
            ->assertFailed();
    }

    public function test_package_audit_executes_successfully_and_outputs_fingerprint(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldPackage('packages/acme/my-pkg', 'acme/my-pkg');

        // Stub PackageVerifier so checks return passed
        $stub = $this->createStub(PackageVerifier::class);
        $stub->method('checkComposer')->willReturn(new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkPint')->willReturn(new CheckResult('pint', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkPhpstan')->willReturn(new CheckResult('phpstan', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkTests')->willReturn(new CheckResult('tests', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkIsolated')->willReturn(new CheckResult('isolated', 'acme/my-pkg', 'passed', 'OK'));

        $this->app->instance(PackageVerifier::class, $stub);
        $this->app->forgetInstance(PackageAuditor::class);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result('');
            }
            if (str_contains($cmd, 'rev-parse') && str_contains($cmd, '^{tree}')) {
                return Process::result("mockTree123\n");
            }
            if (str_contains($cmd, 'rev-parse')) {
                return Process::result("mockCommit456\n");
            }
            if (str_contains($cmd, 'symbolic-ref')) {
                return Process::result("refs/heads/main\n");
            }
            if (str_contains($cmd, 'tag')) {
                return Process::result("v1.0.0\n");
            }

            return Process::result('OK');
        });

        $this->artisan('package:audit', ['package' => 'acme/my-pkg', '--no-commit' => true])
            ->expectsOutputToContain('Auditing package [acme/my-pkg]')
            ->expectsOutputToContain('passed all audit checks')
            ->expectsOutputToContain('Fingerprint:')
            ->assertSuccessful();
    }

    public function test_package_audit_json_flag_outputs_machine_readable_report(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldPackage('packages/acme/my-pkg', 'acme/my-pkg');

        $stub = $this->createStub(PackageVerifier::class);
        $stub->method('checkComposer')->willReturn(new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkPint')->willReturn(new CheckResult('pint', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkPhpstan')->willReturn(new CheckResult('phpstan', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkTests')->willReturn(new CheckResult('tests', 'acme/my-pkg', 'passed', 'OK'));
        $stub->method('checkIsolated')->willReturn(new CheckResult('isolated', 'acme/my-pkg', 'passed', 'OK'));

        $this->app->instance(PackageVerifier::class, $stub);
        $this->app->forgetInstance(PackageAuditor::class);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result('');
            }
            if (str_contains($cmd, 'rev-parse') && str_contains($cmd, '^{tree}')) {
                return Process::result("mockTree123\n");
            }
            if (str_contains($cmd, 'rev-parse')) {
                return Process::result("mockCommit456\n");
            }
            if (str_contains($cmd, 'symbolic-ref')) {
                return Process::result("refs/heads/main\n");
            }
            if (str_contains($cmd, 'tag')) {
                return Process::result("v1.0.0\n");
            }

            return Process::result('OK');
        });

        $this->artisan('package:audit', ['package' => 'acme/my-pkg', '--json' => true, '--no-commit' => true])
            ->expectsOutputToContain('"verdict": "PASSED"')
            ->assertSuccessful();
    }

    public function test_package_audit_verify_flag_verifies_certificate(): void
    {
        Workspace::add('packages', null, true);
        $pkgDir = $this->scaffoldPackage('packages/acme/my-pkg', 'acme/my-pkg');

        // Create mock AUDIT.json
        $auditJson = [
            'schema_version' => '1.0.0',
            'auditor_version' => '1.0.0',
            'package' => 'acme/my-pkg',
            'version' => '1.0.0',
            'audit' => [
                'commit' => 'mockCommit',
                'tree_hash' => 'mockTree',
                'branch' => 'main',
                'timestamp' => '2026-09-13T00:00:00+00:00',
                'environment' => ['php' => '8.3.0'],
            ],
            'checks' => [
                'test_check' => [
                    'check' => 'test_check',
                    'package' => 'acme/my-pkg',
                    'status' => 'passed',
                    'output' => 'OK',
                    'duration_seconds' => 0.1,
                ],
            ],
            'verdict' => 'PASSED',
            'fingerprint' => 'mockFingerprint',
        ];
        File::put($pkgDir.'/AUDIT.json', json_encode($auditJson, JSON_PRETTY_PRINT));

        // Stub CertificateVerifier
        $stubVerifier = $this->createStub(CertificateVerifier::class);
        $stubVerifier->method('verify')->willReturn(
            new VerificationResult(
                verified: true,
                status: 'VERIFIED',
                certificate: AuditReport::fromArray($auditJson),
            )
        );

        $this->app->instance(CertificateVerifier::class, $stubVerifier);

        $this->artisan('package:audit', ['package' => 'acme/my-pkg', '--verify' => true])
            ->expectsOutputToContain('Audit Certificate VERIFIED for package [acme/my-pkg]')
            ->assertSuccessful();
    }
}
