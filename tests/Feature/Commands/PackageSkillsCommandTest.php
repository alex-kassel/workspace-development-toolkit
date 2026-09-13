<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageSkillsCommandTest extends TestCase
{
    public function test_package_skills_publishes_published_skills(): void
    {
        Workspace::add('packages', null, true);
        $packageDir = base_path('packages/acme/my-pkg');
        $this->createDummyPackage('packages/acme/my-pkg', 'acme/my-pkg');

        $skillsDir = $packageDir.'/resources/skills/my-skill';
        File::ensureDirectoryExists($skillsDir);
        File::put($skillsDir.'/SKILL.md', implode("\n", [
            '---',
            'name: my-skill',
            'origin: acme/my-pkg',
            'version: 1.0.0',
            'status: published',
            'description: Operational skill for testing.',
            '---',
            '',
            '# My Skill Content',
        ]));

        $this->artisan('package:skills', ['package' => 'acme/my-pkg'])
            ->expectsOutputToContain('Materialized skill [my-skill]')
            ->assertSuccessful();

        $installedPath = base_path('.agents/skills/my-skill');
        $this->assertDirectoryExists($installedPath);
        $this->assertFileExists($installedPath.'/SKILL.md');

        $manifest = $this->getSandboxWorkspace();
        $pkgEntries = $manifest['workspaces']['packages']['packages'] ?? [];
        $foundSkills = [];
        foreach ($pkgEntries as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === 'acme/my-pkg') {
                $foundSkills = $entry['skills'] ?? [];
                break;
            }
        }
        $this->assertSame(['my-skill'], $foundSkills);
    }

    public function test_package_skills_ignores_draft_skills(): void
    {
        Workspace::add('packages', null, true);
        $packageDir = base_path('packages/acme/draft-pkg');
        $this->createDummyPackage('packages/acme/draft-pkg', 'acme/draft-pkg');

        $skillsDir = $packageDir.'/resources/skills/draft-skill';
        File::ensureDirectoryExists($skillsDir);
        File::put($skillsDir.'/SKILL.md', implode("\n", [
            '---',
            'name: draft-skill',
            'origin: acme/draft-pkg',
            'version: 0.1.0',
            'status: draft',
            'description: Draft skill.',
            '---',
            '',
            '# Draft Skill',
        ]));

        $this->artisan('package:skills', ['package' => 'acme/draft-pkg'])
            ->expectsOutputToContain('Skipping draft skill [draft-skill]')
            ->expectsOutputToContain('No published skills found to install')
            ->assertSuccessful();

        $installedPath = base_path('.agents/skills/draft-skill');
        $this->assertDirectoryDoesNotExist($installedPath);
    }

    public function test_package_skills_symlink_option_creates_symlink_or_directory(): void
    {
        Workspace::add('packages', null, true);
        $packageDir = base_path('packages/acme/symlink-pkg');
        $this->createDummyPackage('packages/acme/symlink-pkg', 'acme/symlink-pkg');

        $skillsDir = $packageDir.'/resources/skills/sym-skill';
        File::ensureDirectoryExists($skillsDir);
        File::put($skillsDir.'/SKILL.md', implode("\n", [
            '---',
            'name: sym-skill',
            'origin: acme/symlink-pkg',
            'version: 1.0.0',
            'status: published',
            'description: Symlink test skill.',
            '---',
            '',
            '# Symlink Skill',
        ]));

        $this->artisan('package:skills', [
            'package' => 'acme/symlink-pkg',
            '--symlink' => true,
        ])->assertSuccessful();

        $installedPath = base_path('.agents/skills/sym-skill');
        $this->assertDirectoryExists($installedPath);
        $this->assertFileExists($installedPath.'/SKILL.md');
    }

    public function test_package_skills_prevents_collision_from_different_package(): void
    {
        Workspace::add('packages', null, true);

        // Package A installs skill-collision
        $pkgA = base_path('packages/acme/pkg-a');
        $this->createDummyPackage('packages/acme/pkg-a', 'acme/pkg-a');
        $skillsA = $pkgA.'/resources/skills/skill-collision';
        File::ensureDirectoryExists($skillsA);
        File::put($skillsA.'/SKILL.md', implode("\n", [
            '---',
            'name: skill-collision',
            'origin: acme/pkg-a',
            'status: published',
            '---',
        ]));
        $this->artisan('package:skills', ['package' => 'acme/pkg-a'])->assertSuccessful();

        // Package B attempts to install same skill slug with different origin
        $pkgB = base_path('packages/acme/pkg-b');
        $this->createDummyPackage('packages/acme/pkg-b', 'acme/pkg-b');
        $skillsB = $pkgB.'/resources/skills/skill-collision';
        File::ensureDirectoryExists($skillsB);
        File::put($skillsB.'/SKILL.md', implode("\n", [
            '---',
            'name: skill-collision',
            'origin: acme/pkg-b',
            'status: published',
            '---',
        ]));

        $this->artisan('package:skills', ['package' => 'acme/pkg-b'])
            ->expectsOutputToContain('collision detected')
            ->assertFailed();
    }

    public function test_package_skills_remove_cleans_up_skills_and_updates_manifest(): void
    {
        Workspace::add('packages', null, true);
        $packageDir = base_path('packages/acme/remove-pkg');
        $this->createDummyPackage('packages/acme/remove-pkg', 'acme/remove-pkg');

        $skillsDir = $packageDir.'/resources/skills/rem-skill';
        File::ensureDirectoryExists($skillsDir);
        File::put($skillsDir.'/SKILL.md', implode("\n", [
            '---',
            'name: rem-skill',
            'origin: acme/remove-pkg',
            'status: published',
            '---',
        ]));

        $this->artisan('package:skills', ['package' => 'acme/remove-pkg'])->assertSuccessful();
        $this->assertDirectoryExists(base_path('.agents/skills/rem-skill'));

        // Remove skills
        $this->artisan('package:skills', [
            'package' => 'acme/remove-pkg',
            '--remove' => true,
        ])
            ->expectsOutputToContain('Removed skill [rem-skill]')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('.agents/skills/rem-skill'));

        $manifest = $this->getSandboxWorkspace();
        $pkgEntries = $manifest['workspaces']['packages']['packages'] ?? [];
        $foundSkills = [];
        foreach ($pkgEntries as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === 'acme/remove-pkg') {
                $foundSkills = $entry['skills'] ?? [];
                break;
            }
        }
        $this->assertSame([], $foundSkills);
    }

    public function test_package_delete_automatically_cleans_up_agent_skills(): void
    {
        Workspace::add('packages', null, true);
        $packageDir = base_path('packages/acme/delete-skills-pkg');
        $this->createDummyPackage('packages/acme/delete-skills-pkg', 'acme/delete-skills-pkg');

        $skillsDir = $packageDir.'/resources/skills/del-skill';
        File::ensureDirectoryExists($skillsDir);
        File::put($skillsDir.'/SKILL.md', implode("\n", [
            '---',
            'name: del-skill',
            'origin: acme/delete-skills-pkg',
            'status: published',
            '---',
        ]));

        $this->artisan('package:skills', ['package' => 'acme/delete-skills-pkg'])->assertSuccessful();
        $this->assertDirectoryExists(base_path('.agents/skills/del-skill'));

        // Now delete the package
        $this->artisan('package:delete', [
            'package' => 'acme/delete-skills-pkg',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('.agents/skills/del-skill'));
    }
}
