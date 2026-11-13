<?php

test('redirects the root page to the CRM', function () {
    $response = $this->get(route('home'));

    $response->assertRedirectToRoute('laravel-crm.dashboard');
});

test('loads the compiled CRM assets without using the host hot file', function () {
    $response = $this->get(route('laravel-crm.login'));

    $response
        ->assertOk()
        ->assertSee('vendor/laravel-crm/assets/', false)
        ->assertDontSee('0.0.0.0:5174', false);
});
