<?php

use Modularity\Core\Tenancy\Exceptions\TenantNotResolvedException;
use Modularity\Core\Tenancy\TenantContext;

beforeEach(function () {
    $this->context = new TenantContext();
});

it('is initially unset', function () {
    expect($this->context->isSet())->toBeFalse();
    expect($this->context->id())->toBeNull();
});

it('sets and retrieves tenant id', function () {
    $this->context->set(42);

    expect($this->context->isSet())->toBeTrue();
    expect($this->context->id())->toBe(42);
});

it('forget clears context', function () {
    $this->context->set(42);
    $this->context->forget();

    expect($this->context->isSet())->toBeFalse();
    expect($this->context->id())->toBeNull();
});

it('assert set returns id when set', function () {
    $this->context->set(99);

    expect($this->context->assertSet())->toBe(99);
});

it('assert set throws when not set', function () {
    expect(fn () => $this->context->assertSet())
        ->toThrow(TenantNotResolvedException::class);
});

it('overwrites tenant id', function () {
    $this->context->set(1);
    $this->context->set(2);

    expect($this->context->id())->toBe(2);
});
