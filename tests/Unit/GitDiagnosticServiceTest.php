<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class GitDiagnosticServiceTest extends TestCase
{
    protected GitDiagnosticService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GitDiagnosticService;
    }

    public function test_diagnoses_ssh_publickey_failure(): void
    {
        $output = "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.";
        $result = $this->service->diagnoseCloneFailure($output, 'git@github.com:vendor/package.git');

        $this->assertSame('ssh_auth', $result->type);
        $this->assertTrue($result->isAuthIssue());
        $this->assertStringContainsString('SSH Key', $result->title);
        $this->assertCount(4, $result->actionableSteps);
        $this->assertStringContainsString('ssh-add', $result->actionableSteps[0]);
    }

    public function test_diagnoses_https_auth_failure(): void
    {
        $output = "fatal: Authentication failed for 'https://github.com/vendor/package.git/'";
        $result = $this->service->diagnoseCloneFailure($output, 'https://github.com/vendor/package.git');

        $this->assertSame('https_auth', $result->type);
        $this->assertTrue($result->isAuthIssue());
        $this->assertStringContainsString('HTTPS', $result->title);
        $this->assertStringContainsString('gh auth login', $result->actionableSteps[0]);
    }

    public function test_diagnoses_repository_not_found_for_private_repo(): void
    {
        $output = "remote: Repository not found.\nfatal: repository 'https://github.com/vendor/package.git/' not found";
        $result = $this->service->diagnoseCloneFailure($output, 'https://github.com/vendor/package.git');

        $this->assertSame('not_found_or_private', $result->type);
        $this->assertTrue($result->isAuthIssue());
        $this->assertStringContainsString('Repository Not Found', $result->title);
    }

    public function test_diagnoses_network_connectivity_failure(): void
    {
        $output = "fatal: unable to access 'https://github.com/vendor/package.git/': Could not resolve host: github.com";
        $result = $this->service->diagnoseCloneFailure($output, 'https://github.com/vendor/package.git');

        $this->assertSame('network_timeout', $result->type);
        $this->assertFalse($result->isAuthIssue());
        $this->assertStringContainsString('DNS', $result->title);
    }

    public function test_diagnoses_unknown_generic_failure(): void
    {
        $output = 'fatal: some unexpected git internal error';
        $result = $this->service->diagnoseCloneFailure($output, 'git@github.com:vendor/package.git');

        $this->assertSame('unknown', $result->type);
        $this->assertFalse($result->isAuthIssue());
        $this->assertStringContainsString('Failed', $result->title);
    }
}
