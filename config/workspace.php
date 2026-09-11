<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Git Repository Template
    |--------------------------------------------------------------------------
    |
    | When cloning or restoring packages, this template is used to construct
    | the Git clone URL for any package that does not specify an explicit URL.
    |
    | The placeholder `{package}` will be replaced with the package name,
    | e.g. "alex-kassel/workspace-development-toolkit".
    |
    */
    'repository_template' => 'git@github.com:{package}.git',

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    |
    | The default timeout in seconds for background processes such as Git clone
    | or Composer require / install / dump-autoload operations.
    |
    */
    'process_timeout' => 300,

    /*
    |--------------------------------------------------------------------------
    | Quality Checks Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for package:check command and verifier suite.
    |
    */
    'quality_checks' => [
        'composer_validate' => true,
        'pint' => true,
        'phpstan' => ['enabled' => true, 'level' => 8],
        'tests' => true,
    ],

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
    | When `workspace:clone --recursive` is run, any dependencies belonging to these
    | organizations will be automatically cloned into the workspace as well.
    |
    */
    'trusted_organizations' => [],

    /*
    |--------------------------------------------------------------------------
    | Skills Configuration
    |--------------------------------------------------------------------------
    |
    | Target directory and auto-publishing settings for workspace agent skills.
    |
    */
    'skills_path' => null,
    'auto_publish_skill' => true,
];
