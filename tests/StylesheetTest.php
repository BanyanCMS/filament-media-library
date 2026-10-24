<?php

declare(strict_types=1);

$css = (string) file_get_contents(__DIR__.'/../resources/css/media.css');

it('only knows its own ml- tokens and classes', function () use ($css): void {
    expect($css)->not->toContain('--bn-')
        ->and($css)->not->toContain('bn-media')
        ->and($css)->toContain('.ml-library')
        ->and($css)->toContain('.ml-picker__option');
});

it('declares the token defaults without specificity so a host rule wins in any load order', function () use ($css): void {
    expect($css)->toContain(':where(.ml-library) {')
        ->and($css)->toContain(':where(.dark .ml-library) {');
});

it('uses only classes with the ml- prefix in its views', function (): void {
    $views = glob(__DIR__.'/../resources/views/{*,*/*}.blade.php', GLOB_BRACE);

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        expect((string) file_get_contents($view))->not->toContain('bn-media');
    }
});
