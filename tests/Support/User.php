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
        return self::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'x']);
    }
}
