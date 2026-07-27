<?php

namespace Splicewire\Beam\Versioning\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Versioning\VersioningServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Versioning\BeamVersioningServiceProvider;
use Splicewire\Beam\Versioning\Tests\Fixtures\Note;
use Splicewire\Beam\Versioning\Tests\Fixtures\NotePolicy;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        Gate::policy(Note::class, NotePolicy::class);

        // Register the seam under test the exact way a host does — one macro call lights up the
        // versions REST triplet for the throwaway Note model, authorized through NotePolicy.
        Route::middleware('api')->prefix('api')->group(function (): void {
            Route::recordVersions('notes', Note::class);
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            BeamVersioningServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('auth.providers.users.model', Fixtures\User::class);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('head_version')->nullable(); // the HEAD pin (Versionable::versionPinAttribute)
            $table->timestamps();
        });

        // The polymorphic versions store — the app owns this migration in a real deploy; the package
        // test writes the same shape rushing/laravel-versioning reads/writes.
        Schema::create('versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['versionable_type', 'versionable_id', 'version']);
            $table->index(['versionable_type', 'versionable_id']);
        });
    }
}
