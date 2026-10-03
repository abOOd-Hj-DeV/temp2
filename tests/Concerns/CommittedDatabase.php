<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Erasure and after-commit tests need genuine commits, not a rollback-only harness. */
trait CommittedDatabase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->app[Kernel::class]->setArtisan(null);

        // A subsequent rollback-only test must not reuse our committed fixtures.
        // Do not run migration down() here: erasure triggers make that unsafe.
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }
}
