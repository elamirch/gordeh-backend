<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * tymon/jwt-auth caches the resolved user on both the guard instance
     * (JWTGuard::$user) and its own container singletons ('tymon.jwt.auth' etc.),
     * neither of which resets between requests within a single test method (unlike
     * production, where each request gets a fresh container). Without this, a test
     * that authenticates as a second user mid-test keeps resolving auth() to the
     * first — call this before minting a token for a new actor.
     */
    protected function resetJwtState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.parser');
    }
}
