<?php

test('email unsubscribe route resolves the embedded CRM controller action', function () {
    $response = $this->get(route('laravel-crm.email-tracking.unsubscribe', [
        'token' => 'unknown-token',
    ]));

    $response->assertNotFound();
});

test('sms unsubscribe route resolves the embedded CRM controller action', function () {
    $response = $this->get(route('laravel-crm.sms-tracking.unsubscribe', [
        'token' => 'unknown-token',
    ]));

    $response->assertNotFound();
});
