<?php

declare(strict_types=1);

/*
 * This package is the Prism side of the line, and the chat core's own
 * packaging test uses a Prism-dependent package as its negative control. So
 * this one must keep depending on Harness, on the core, and on nothing else.
 */

it('depends on the chat core and Prism Harness, and on no other third-party package', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($manifest['require']))->toBe(['php', 'illuminate/contracts', 'illuminate/support', 'particle-academy/fancy-chat-php', 'particle-academy/prism-harness'])
        ->and($manifest['require']['php'])->toBe('^8.4')
        ->and($manifest['require']['illuminate/support'])->toBe('^13.0')
        ->and($manifest['require']['particle-academy/prism-harness'])->toBe('^0.26')
        ->and(array_keys($manifest['require-dev']))->toBe(['laravel/framework', 'pestphp/pest'])
        ->and($manifest['require-dev']['pestphp/pest'])->toBe('^5.0');
});

it('installs fancy-chat-php from its git repository over https', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['repositories'])->toBe([['type' => 'vcs', 'url' => 'https://github.com/Particle-Academy/fancy-chat-php.git']]);
});
