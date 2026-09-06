<?php

namespace Splicewire\Beam\Versioning\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteActionMetadataReader;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Versioning\BeamVersioningServiceProvider;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Http\VersionsController;

/** No model boot, migrations, or database: the public declaration and serialization seams only. */
class VersionResponseContractTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(sys_get_temp_dir());
        $this->app->instance('config', new Repository(['app' => ['env' => 'testing']]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->register(LaravelDataServiceProvider::class);
        $this->app['config']->set('data.structure_caching.enabled', false);
        if (class_exists(LaravelDataSchemasServiceProvider::class)) {
            $this->app->register(LaravelDataSchemasServiceProvider::class);
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function responses(): array
    {
        $row = ['id' => 'version-1', 'version' => 1, 'readable' => 'v1', 'label' => null,
            'createdBy' => null, 'createdAt' => null, 'isHead' => true];

        return [
            'empty index' => ['index', 200, []],
            'singleton index' => ['index', 200, [$row]],
            'multiple index' => ['index', 200, [$row, [...$row, 'id' => 'version-2', 'version' => 2]]],
            'store' => ['store', 201, $row],
            'restore' => ['restore', 201, $row],
        ];
    }

    #[DataProvider('responses')]
    public function test_declared_body_round_trips_the_wire_envelope(string $method, int $status, array $data): void
    {
        $attribute = (new ReflectionMethod(VersionsController::class, $method))
            ->getAttributes(ResponseFromData::class)[0];
        $arguments = $attribute->getArguments();
        $class = $arguments[0];
        self::assertSame($status, $arguments['status'] ?? $arguments[1] ?? 200);
        self::assertNotEmpty((new ReflectionClass($class))->getAttributes(TypeScript::class));
        $wire = ['success' => true, 'data' => $data];
        $response = new JsonResponse($class::from($wire), $status);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($wire, $response->getData(true));
    }

    #[DataProvider('responses')]
    public function test_response_schema_describes_the_envelope_and_nested_cardinality(string $method, int $status, array $data): void
    {
        if (! interface_exists(Generator::class)) {
            self::markTestSkipped('Schema projection requires the suggested schemastud/laravel-data-schemas package.');
        }
        $attribute = (new ReflectionMethod(VersionsController::class, $method))
            ->getAttributes(ResponseFromData::class)[0];
        $schema = $this->app->make(Generator::class)->generate(new ReflectionClass($attribute->getArguments()[0]));
        self::assertSame('object', $schema['type']);
        self::assertSame(['success', 'data'], array_keys($schema['properties']));
        self::assertSame('boolean', $schema['properties']['success']['type']);
        $nested = $schema['properties']['data'];
        if ($method === 'index') {
            self::assertSame('array', $nested['type']);
            $nested = $nested['items'];
        }
        if (isset($nested['$ref'])) {
            $nested = $schema['$defs'][basename($nested['$ref'])];
        }
        self::assertSame('object', $nested['type']);
        self::assertSame('string', $nested['properties']['id']['type']);
        self::assertSame('integer', $nested['properties']['version']['type']);
    }

    public function test_beam_reader_uses_the_whole_body_even_with_legacy_bare_action_keys(): void
    {
        if (! class_exists(RouteReturnType::class)) {
            self::markTestSkipped('The Beam client reader is an optional consumer of this standalone versioning package.');
        }
        $router = new Router(new Dispatcher($this->app), $this->app);
        $this->app->instance('router', $router);
        (new BeamVersioningServiceProvider($this->app))->boot();
        // No model lookup occurs: these are the actual macro-mounted routes, read as declarations.
        Route::recordVersions('notes', \stdClass::class);
        Route::recordVersions('legacy-notes', \stdClass::class, data: VersionData::class);
        $meta = new RouteActionMetadataReader;
        $returns = new RouteReturnType(new ParticleResourceRegistry, new ParticleOperationRegistry, $meta);

        foreach ($router->getRoutes() as $route) {
            $operation = $route->getActionMethod();
            self::assertNull($meta->returns($route));
            self::assertFalse($meta->returnsMany($route));
            self::assertSame([
                'type' => 'Splicewire.Beam.Versioning.Data.'.($operation === 'index' ? 'VersionListResponseData' : 'VersionResponseData'),
                'many' => false,
                'returnsBody' => true,
            ], $returns->for($route));
            self::assertSame(
                ($route->getName() === 'notes.versions.'.$operation ? 'notes' : 'legacyNotes').'Versions'.ucfirst($operation),
                $meta->operationId($route),
            );
        }
        self::assertCount(6, $router->getRoutes());
    }
}
