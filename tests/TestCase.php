<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withSanctumToken(string $token): static
    {
        // Each simulated HTTP request should resolve the current bearer token,
        // as Laravel would do in a separate worker/request in production.
        $this->app['auth']->forgetGuards();
        return $this->flushHeaders()->withToken($token);
    }
}
