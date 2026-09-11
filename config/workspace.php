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
];
