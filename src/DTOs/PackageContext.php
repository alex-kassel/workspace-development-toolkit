<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use Illuminate\Support\Str;

class PackageContext
{
    /**
     * @param  array<int, string>  $skills
     * @param  array<int, array{processor: string, status: string, message: string}>  $steps
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly string $workspace,
        public readonly string $vendor,
        public readonly string $package,
        public readonly string $relativePackagePath,
        public readonly ?string $alias = null,
        public readonly ?string $archetype = null,
        public readonly bool $install = false,
        public readonly bool $dev = false,
        public readonly bool $scaffoldSkills = true,
        public readonly array $skills = [],
        public array $steps = [],
    ) {}

    public function recordStep(string $processor, string $status, string $message): void
    {
        $this->steps[] = [
            'processor' => $processor,
            'status' => $status,
            'message' => $message,
        ];
    }

    public function fullName(): string
    {
        return "{$this->vendor}/{$this->package}";
    }

    public function vendorNamespace(): string
    {
        return Str::studly(str_replace(['.', '-'], '_', $this->vendor));
    }

    public function packageNamespace(): string
    {
        return Str::studly(str_replace(['.', '-'], '_', $this->package));
    }

    public function providerClass(): string
    {
        return "{$this->packageNamespace()}ServiceProvider";
    }

    public function packageFullPath(): string
    {
        return $this->rootPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->relativePackagePath);
    }

    public function composerJsonPath(): string
    {
        return $this->packageFullPath().DIRECTORY_SEPARATOR.'composer.json';
    }
}
