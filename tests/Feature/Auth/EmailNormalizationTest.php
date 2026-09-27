<?php

use App\Enums\Role;
use App\Models\User;

/*
 * Module 01 regression.
 *
 * User::email() declared its return type as
 * Illuminate\Database\Eloquent\Attributes\Attribute, but the class Laravel actually
 * provides is Illuminate\Database\Eloquent\Casts\Attribute. Eloquent's
 * hasAttributeMutator() compares the method's declared return type name against the real
 * class, so the mismatch made it report "no mutator" instead of raising an error: the
 * method was silently dead, and every address was stored exactly as submitted, spaces and
 * all. These tests pin the behaviour the model documents, because a wrong namespace breaks
 * it quietly rather than loudly.
 */

test('an email address is stored trimmed and lower cased', function () {
    $user = userWithRole(Role::ADMIN, ['email' => '  MiXeD.CaSe@Example.TEST  ']);

    expect($user->fresh()->email)->toBe('mixed.case@example.test');
});

test('the email mutator is actually registered', function () {
    $user = new User;

    // Guards the regression directly: a wrong Attribute namespace makes this false while
    // every other test in the suite still passes.
    expect($user->hasAttributeMutator('email'))->toBeTrue();
});

test('a user can be found by a differently cased address', function () {
    $user = userWithRole(Role::ADMIN, ['email' => 'Registrar@Example.test']);

    expect(User::query()->where('email', 'registrar@example.test')->first()?->id)->toBe($user->id);
});
