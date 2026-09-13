<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Git Repository URL Template
    |--------------------------------------------------------------------------
    |
    | When cloning or restoring packages, this template is used to construct
    | the Git clone URL for any package that does not specify an explicit URL.
    |
    | The placeholder `{package}` will be replaced with the package name,
    | e.g. "alex-kassel/workspace-development-toolkit".
    |
    */
    'repository_url_template' => env('WORKSPACE_REPOSITORY_URL_TEMPLATE', env('WORKSPACE_REPOSITORY_TEMPLATE', 'git@github.com:{package}.git')),

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    |
    | The default timeout in seconds for background processes such as Git clone
    | or Composer require / install / dump-autoload operations.
    |
    */
    'process_timeout' => (int) env('WORKSPACE_PROCESS_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | README Compliance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for package:readme command and ReadmeValidator.
    |
    */
    'readme' => [
        'required_sections' => [
            'Requirements',
            'Installation',
            'Usage',
            'Testing',
            'License',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Organizations
    |--------------------------------------------------------------------------
    |
    | Vendors considered internal/trusted when performing recursive package cloning.
    | When `package:clone --recursive` is run, any dependencies belonging to these
    | organizations will be automatically cloned into the workspace as well.
    | Can be defined as an array or comma-separated list via WORKSPACE_TRUSTED_ORGANIZATIONS.
    |
    */
    'trusted_organizations' => array_values(array_filter(array_map('trim', explode(',', (string) env('WORKSPACE_TRUSTED_ORGANIZATIONS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Agent Skills Target Path
    |--------------------------------------------------------------------------
    |
    | Relative path from the project root (base_path) where AI agent skills
    | should be installed, materialized, or linked.
    |
    */
    'skills_path' => env('WORKSPACE_SKILLS_PATH', '.agents/skills'),

    /*
    |--------------------------------------------------------------------------
    | Auto-Publish Toolkit Skills
    |--------------------------------------------------------------------------
    |
    | When enabled, the toolkit automatically materializes its bundled agent
    | skills (e.g., package-scaffolding, package-audit) into your `skills_path`
    | upon application boot in console mode, ensuring AI coding agents always
    | have access to the latest workspace tools.
    |
    */
    'auto_publish_skill' => (bool) env('WORKSPACE_AUTO_PUBLISH_SKILLS', true),

    /*
    |--------------------------------------------------------------------------
    | Scaffold Agent Skills for New Packages
    |--------------------------------------------------------------------------
    |
    | When enabled, `php artisan package:make` will automatically scaffold a
    | standardized agent skill skeleton (`resources/skills/<package>/SKILL.md`)
    | inside each newly created package. Can be overridden using `--skills` or
    | `--no-skills` command options.
    |
    */
    'scaffold_agent_skills' => (bool) env('WORKSPACE_SCAFFOLD_AGENT_SKILLS', true),
];
