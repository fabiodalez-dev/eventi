<?php

use App\Models\User;

beforeEach(function (): void {
    testCity();
});

it('takes guests from saved to login with a clear explanation and return path', function (): void {
    $this->get('/i-miei-salvataggi')->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('account.saved'));
    $this->get('/accedi')->assertOk()->assertSee(__('account.saved.login_required'));
});

it('does not show the saved explanation on an ordinary login', function (): void {
    $this->get('/accedi')->assertOk()->assertDontSee(__('account.saved.login_required'));
});

it('keeps saved accessible to authenticated users', function (): void {
    $this->actingAs(User::factory()->create())->get('/i-miei-salvataggi')->assertOk();
});
