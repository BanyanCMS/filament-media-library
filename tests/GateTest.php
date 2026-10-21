<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Illuminate\Support\Facades\Gate;

covers(MediaLibraryGate::class);

afterEach(fn () => MediaLibraryGate::manageUsing(null));

it('admits nobody who is not signed in', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    MediaLibraryGate::manageUsing(static fn (): bool => true);

    expect(MediaLibraryGate::allowsBrowse())->toBeFalse()
        ->and(MediaLibraryGate::allowsManage())->toBeFalse()
        ->and(MediaLibraryGate::actorId())->toBeNull();
});

it('lets any signed-in user browse but nobody manage when no ability is defined', function (): void {
    $user = User::make();
    $this->actingAs($user);

    expect(MediaLibraryGate::allowsBrowse())->toBeTrue()
        ->and(MediaLibraryGate::allowsManage())->toBeFalse()
        ->and(MediaLibraryGate::actorId())->toBe((string) $user->id);
});

it('asks the fallback only while the manage ability is undefined', function (): void {
    $this->actingAs(User::make());
    MediaLibraryGate::manageUsing(static fn (): bool => true);

    expect(MediaLibraryGate::allowsManage())->toBeTrue();

    Gate::define('media.manage', static fn (): bool => false);

    expect(MediaLibraryGate::allowsManage())->toBeFalse();
});

it('honours the configured ability names', function (): void {
    config()->set('media-library.abilities', ['view' => 'library.see', 'manage' => 'library.edit']);
    Gate::define('library.see', static fn (User $user): bool => $user->name === 'Editor');
    Gate::define('library.edit', static fn (User $user): bool => $user->name === 'Editor');

    $this->actingAs(User::make('Visitor'));

    expect(MediaLibraryGate::allowsBrowse())->toBeFalse()
        ->and(MediaLibraryGate::allowsManage())->toBeFalse();

    $this->actingAs(User::make('Editor'));

    expect(MediaLibraryGate::allowsBrowse())->toBeTrue()
        ->and(MediaLibraryGate::allowsManage())->toBeTrue();
});
