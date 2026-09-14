<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos;

final class FuzzPayloadGenerator
{
    /**
     * Array of malicious path traversal and filesystem breakout vectors.
     *
     * @return list<string>
     */
    public static function pathTraversalVectors(): array
    {
        return [
            '../../../../../../etc/passwd',
            '..\\..\\..\\..\\windows\\system32',
            '/etc/shadow',
            '/root/.ssh/id_rsa',
            'packages/../../outside',
            'packages/../../../var/log',
            '.',
            '..',
            '/',
            '//',
            '~/',
            './.././../secret',
            'packages/./../.././..',
            "acme/\0traversal",
        ];
    }

    /**
     * Array of malformed, invalid, or extreme package/workspace name inputs.
     *
     * @return list<string>
     */
    public static function malformedInputs(): array
    {
        return [
            '',
            '   ',
            "\t\n",
            '/only-name',
            'vendor/',
            'vendor//package',
            'vendor/package/extra-part',
            'vendor\\package',
            '@#$%^&*()',
            'acme/🚀-emoji-pkg',
            '<script>alert(1)</script>',
            str_repeat('a', 500).'/'.str_repeat('b', 500),
            "vendor/pkg\nbreak",
            '   vendor/pkg   ',
            '--unknown-flag-injection',
            '$(rm -rf /)',
            '; echo injection;',
        ];
    }

    /**
     * Combined suite of all crazy fuzz payloads.
     *
     * @return list<string>
     */
    public static function allFuzzPayloads(): array
    {
        return array_values(array_unique(array_merge(
            self::pathTraversalVectors(),
            self::malformedInputs()
        )));
    }
}
