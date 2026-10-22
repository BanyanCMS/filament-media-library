<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public static function make(string $name = 'Editor'): self
    {
        // Idempotent, so a test that signs in twice as the same editor does not collide on the unique email.
        return self::query()->firstOrCreate(['email' => strtolower($name).'@example.test'], ['name' => $name, 'password' => 'x']);
    }
}
