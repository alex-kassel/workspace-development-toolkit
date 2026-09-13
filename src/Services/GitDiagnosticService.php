<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitDiagnosticResult;

final readonly class GitDiagnosticService
{
    /**
     * Diagnose a git clone failure output and produce actionable troubleshooting steps.
     */
    public function diagnoseCloneFailure(string $rawOutput, string $repoUrl): GitDiagnosticResult
    {
        $trimmed = trim($rawOutput);

        // 1. SSH publickey denied
        if (str_contains($trimmed, 'Permission denied (publickey)')
            || (str_contains($trimmed, 'Could not read from remote repository') && (str_starts_with($repoUrl, 'git@') || str_starts_with($repoUrl, 'ssh://')))) {
            return new GitDiagnosticResult(
                type: 'ssh_auth',
                title: 'Authentication Issue: SSH Key Missing or Rejected',
                explanation: 'Git could not authenticate with GitHub (or your Git host) using your current SSH keys.',
                actionableSteps: [
                    'Check if your SSH agent has identities loaded: ssh-add -l',
                    'Add your private SSH key to the agent: ssh-add ~/.ssh/id_ed25519 (or ~/.ssh/id_rsa)',
                    'Verify your connection to GitHub: ssh -T git@github.com',
                    'Ensure your public key is added to your account: https://github.com/settings/keys',
                ],
                rawOutput: $trimmed,
            );
        }

        // 2. HTTPS credentials / token missing
        if (str_contains($trimmed, 'Authentication failed')
            || str_contains($trimmed, 'could not read Username')
            || str_contains($trimmed, 'terminal prompts disabled')) {
            return new GitDiagnosticResult(
                type: 'https_auth',
                title: 'Authentication Issue: HTTPS Credentials / Token Missing',
                explanation: 'This repository is private and requires HTTPS authentication credentials or a Personal Access Token.',
                actionableSteps: [
                    'Authenticate with GitHub CLI: gh auth login',
                    'Or clone via SSH instead: re-run the command with the --ssh flag',
                    'Or configure a Personal Access Token (PAT) with "repo" scope in git-credential',
                ],
                rawOutput: $trimmed,
            );
        }

        // 3. Repository not found / 404 (for private repos)
        if (str_contains($trimmed, 'Repository not found') || str_contains($trimmed, 'repository not found')) {
            return new GitDiagnosticResult(
                type: 'not_found_or_private',
                title: 'Repository Not Found or Access Denied',
                explanation: 'The remote host reported that the repository was not found. For private repositories, this means your account does not have read access or you are not authenticated.',
                actionableSteps: [
                    'Verify that you have read access to the repository on GitHub',
                    'If using SSH, verify your active key has access: ssh -T git@github.com',
                    'If using HTTPS, verify your token has the "repo" scope enabled',
                ],
                rawOutput: $trimmed,
            );
        }

        // 4. Network or DNS connectivity
        if (str_contains($trimmed, 'Could not resolve host')
            || str_contains($trimmed, 'Connection timed out')
            || str_contains($trimmed, 'Failed to connect to')) {
            return new GitDiagnosticResult(
                type: 'network_timeout',
                title: 'Network or DNS Connectivity Issue',
                explanation: 'Git was unable to establish a network connection to the remote repository host.',
                actionableSteps: [
                    'Check your internet connection and DNS configuration',
                    'Ensure your firewall, VPN, or corporate proxy is not blocking Git traffic (port 22 for SSH, 443 for HTTPS)',
                ],
                rawOutput: $trimmed,
            );
        }

        // 5. Generic fallback
        return new GitDiagnosticResult(
            type: 'unknown',
            title: 'Git Clone Operation Failed',
            explanation: 'Git exited with an unclassified error during the clone operation.',
            actionableSteps: [
                'Verify that Git is installed and available in your PATH',
                'Verify the repository URL and target path syntax',
                'Ensure you have read permissions for the target repository',
            ],
            rawOutput: $trimmed,
        );
    }
}
