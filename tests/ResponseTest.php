<?php

namespace Inertia\Tests;

use Mockery;
use Inertia\LazyProp;
use Inertia\OnlyNode;
use Inertia\Response;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Fluent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Tests\Stubs\FakeResource;
use Illuminate\Http\Response as BaseResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ResponseTest extends TestCase
{
    public function test_can_macro(): void
    {
        $response = new Response('User/Edit', []);
        $response->macro('foo', function () {
            return 'bar';
        });

        $this->assertEquals('bar', $response->foo());
    }

    public function test_server_response(): void
    {
        $request = Request::create('/user/123', 'GET');

        $user = ['name' => 'Jonathan'];
        $response = new Response('User/Edit', ['user' => $user], 'app', '123');
        $response = $response->toResponse($request);
        $view = $response->getOriginalContent();
        $page = $view->getData()['page'];

        $this->assertInstanceOf(BaseResponse::class, $response);
        $this->assertInstanceOf(View::class, $view);

        $this->assertSame('User/Edit', $page['component']);
        $this->assertSame('Jonathan', $page['props']['user']['name']);
        $this->assertSame('/user/123', $page['url']);
        $this->assertSame('123', $page['version']);
        $this->assertSame('<div id="app" data-page="{&quot;component&quot;:&quot;User\/Edit&quot;,&quot;props&quot;:{&quot;user&quot;:{&quot;name&quot;:&quot;Jonathan&quot;}},&quot;url&quot;:&quot;\/user\/123&quot;,&quot;version&quot;:&quot;123&quot;}"></div>', $view->render());
    }

    public function test_xhr_response(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $user = (object) ['name' => 'Jonathan'];
        $response = new Response('User/Edit', ['user' => $user], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Edit', $page->component);
        $this->assertSame('Jonathan', $page->props->user->name);
        $this->assertSame('/user/123', $page->url);
        $this->assertSame('123', $page->version);
    }

    public function test_resource_response(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $resource = new FakeResource(['name' => 'Jonathan']);

        $response = new Response('User/Edit', ['user' => $resource], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Edit', $page->component);
        $this->assertSame('Jonathan', $page->props->user->name);
        $this->assertSame('/user/123', $page->url);
        $this->assertSame('123', $page->version);
    }

    public function test_lazy_resource_response(): void
    {
        $request = Request::create('/users', 'GET', ['page' => 1]);
        $request->headers->add(['X-Inertia' => 'true']);

        $users = Collection::make([
            new Fluent(['name' => 'Jonathan']),
            new Fluent(['name' => 'Taylor']),
            new Fluent(['name' => 'Jeffrey']),
        ]);

        $callable = static function () use ($users) {
            $page = new LengthAwarePaginator($users->take(2), $users->count(), 2);

            return new class($page, JsonResource::class) extends ResourceCollection {
            };
        };

        $response = new Response('User/Index', ['users' => $callable], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $expected = [
            'data' => $users->take(2),
            'links' => [
                'first' => '/?page=1',
                'last' => '/?page=2',
                'prev' => null,
                'next' => '/?page=2',
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 2,
                'path' => '/',
                'per_page' => 2,
                'to' => 2,
                'total' => 3,
            ],
        ];

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Index', $page->component);
        $this->assertSame('/users?page=1', $page->url);
        $this->assertSame('123', $page->version);
        tap($page->props->users, function ($users) use ($expected) {
            $this->assertSame(json_encode($expected['data']), json_encode($users->data));
            $this->assertSame(json_encode($expected['links']), json_encode($users->links));
            $this->assertSame('/', $users->meta->path);
        });
    }

    public function test_nested_lazy_resource_response(): void
    {
        $request = Request::create('/users', 'GET', ['page' => 1]);
        $request->headers->add(['X-Inertia' => 'true']);

        $users = Collection::make([
            new Fluent(['name' => 'Jonathan']),
            new Fluent(['name' => 'Taylor']),
            new Fluent(['name' => 'Jeffrey']),
        ]);

        $callable = static function () use ($users) {
            $page = new LengthAwarePaginator($users->take(2), $users->count(), 2);

            // nested array with ResourceCollection to resolve
            return [
                'users' => new class($page, JsonResource::class) extends ResourceCollection {},
            ];
        };

        $response = new Response('User/Index', ['something' => $callable], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $expected = [
            'users' => [
                'data' => $users->take(2),
                'links' => [
                    'first' => '/?page=1',
                    'last' => '/?page=2',
                    'prev' => null,
                    'next' => '/?page=2',
                ],
                'meta' => [
                    'current_page' => 1,
                    'from' => 1,
                    'last_page' => 2,
                    'path' => '/',
                    'per_page' => 2,
                    'to' => 2,
                    'total' => 3,
                ],
            ],
        ];

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Index', $page->component);
        $this->assertSame('/users?page=1', $page->url);
        $this->assertSame('123', $page->version);
        tap($page->props->something->users, function ($users) use ($expected) {
            $this->assertSame(json_encode($expected['users']['data']), json_encode($users->data));
            $this->assertSame(json_encode($expected['users']['links']), json_encode($users->links));
            $this->assertSame('/', $users->meta->path);
        });
    }

    public function test_arrayable_prop_response(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $resource = FakeResource::make(['name' => 'Jonathan']);

        $response = new Response('User/Edit', ['user' => $resource], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Edit', $page->component);
        $this->assertSame('Jonathan', $page->props->user->name);
        $this->assertSame('/user/123', $page->url);
        $this->assertSame('123', $page->version);
    }

    public function test_promise_props_are_resolved(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $user = (object) ['name' => 'Jonathan'];

        $promise = Mockery::mock('GuzzleHttp\Promise\PromiseInterface')
            ->shouldReceive('wait')
            ->andReturn($user)
            ->mock();

        $response = new Response('User/Edit', ['user' => $promise], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Edit', $page->component);
        $this->assertSame('Jonathan', $page->props->user->name);
        $this->assertSame('/user/123', $page->url);
        $this->assertSame('123', $page->version);
    }

    public function test_xhr_partial_response(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'User/Edit']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'partial']);

        $user = (object) ['name' => 'Jonathan'];
        $response = new Response('User/Edit', ['user' => $user, 'partial' => 'partial-data'], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $props = get_object_vars($page->props);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('User/Edit', $page->component);
        $this->assertFalse(isset($props['user']));
        $this->assertCount(1, $props);
        $this->assertSame('partial-data', $page->props->partial);
        $this->assertSame('/user/123', $page->url);
        $this->assertSame('123', $page->version);
    }

    public function test_lazy_props_are_not_included_by_default(): void
    {
        $request = Request::create('/users', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $lazyProp = new LazyProp(function () {
            return 'A lazy value';
        });

        $response = new Response('Users', ['users' => [], 'lazy' => $lazyProp, 'nested' => ['lazy' => $lazyProp]], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertSame([], $page->props->users);
        $this->assertObjectNotHasAttribute('lazy', $page->props);
        $this->assertFalse(isset($page->props->nested->lazy));
    }

    public function test_lazy_props_are_included_in_partial_reload(): void
    {
        $request = Request::create('/users', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy']);

        $lazyProp = new LazyProp(function () {
            return 'A lazy value';
        });

        $response = new Response('Users', ['users' => [], 'lazy' => $lazyProp], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertObjectNotHasAttribute('users', $page->props);
        $this->assertSame('A lazy value', $page->props->lazy);
    }

    public function test_nested_lazy_props(): void
    {
        $request = Request::create('/users');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy.nested.prop,nonLazy.another,nonLazy']);

        $access = 0;
        $trap = 0;
        $expectedLazy = new LazyProp(function () use (&$access) {
            $access++;

            return 'A lazy value';
        });
        $trapLazy = new LazyProp(function () use (&$trap) {
            return $trap++;
        });

        $lazyProp = new LazyProp(function () use (&$access, $trapLazy, $expectedLazy) {
            $access++;

            return [
                'prop' => $expectedLazy,
                'another' => $trapLazy,
                'nonLazy' => 'ok',
            ];
        });
        $nonLazyProp = function () use (&$access, $trapLazy, $expectedLazy) {
            return [
                'prop' => $trapLazy,
                'another' => $expectedLazy,
                'leafNode' => 'ok',
            ];
        };

        $response = new Response('Users', ['users' => [], 'lazy' => ['nested' => $lazyProp, 'notAsked' => 'ok', 'trapLazy' => $trapLazy], 'nonLazy' => $nonLazyProp], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertObjectNotHasAttribute('users', $page->props);
        $this->assertEquals(json_decode(json_encode([
            'lazy' => [
                'nested' => [
                    'prop' => 'A lazy value',
                ],
            ],
            'nonLazy' => [
                'another' => 'A lazy value',
                'leafNode' => 'ok',
            ],
        ])), $page->props);

        $this->assertEquals(0, $trap);
        $this->assertEquals(3, $access);
    }

    public function test_backward_compat_packed_array(): void
    {
        $request = Request::create('/users');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        // Note: wildcards are a new feature and packed arrays support for them is not planned so lazy.packed.* would not work in this case
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy.packed.prop']);

        $lazy = new LazyProp(fn () => 'A lazy value');

        $response = new Response('Users', ['lazy' => ['packed' => ['not_packed' => 'ok']], 'lazy.packed.prop' => $lazy], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertEquals(json_decode(json_encode([
            'lazy' => [
                'packed' => [
                    'prop' => 'A lazy value',
                ],
            ],
        ])), $page->props);
    }

    public function test_wildcard_lazy_props(): void
    {
        $request = Request::create('/users');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy.*']);

        $lazy = new LazyProp(fn () => 'A lazy value');

        $lazyProp = new LazyProp(fn () => [
            'prop' => $lazy,
            'another' => $lazy,
            'nonLazy' => 'ok',
        ]);

        $response = new Response('Users', ['lazy' => ['nested' => $lazyProp, 'lazy' => $lazy, 'second' => fn () => 'sec'], 'nope' => 'no'], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertEquals(json_decode(json_encode([
            'lazy' => [
                'nested' => [
                    'nonLazy' => 'ok',
                ],
                'lazy' => 'A lazy value',
                'second' => 'sec',
            ],
        ])), $page->props);
    }

    public function test_semi_wildcard_lazy_props(): void
    {
        $request = Request::create('/users');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy.*.prop']);

        $lazy = new LazyProp(fn () => 'A lazy value');

        $lazyProp = new LazyProp(fn () => [
            'prop' => $lazy,
            'another' => $lazy,
            'nonLazy' => 'nope',
        ]);

        $response = new Response('Users', ['lazy' => ['nested' => $lazyProp, 'other' => $lazyProp]], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertEquals(json_decode(json_encode([
            'lazy' => [
                'nested' => [
                    'prop' => 'A lazy value',
                ],
                'other' => [
                    'prop' => 'A lazy value',
                ],
            ],
        ])), $page->props);
    }

    public function test_deep_wildcard_lazy_props(): void
    {
        $request = Request::create('/users');
        $request->headers->add(['X-Inertia' => 'true']);
        $request->headers->add(['X-Inertia-Partial-Component' => 'Users']);
        $request->headers->add(['X-Inertia-Partial-Data' => 'lazy.**']);

        $lazy = new LazyProp(fn () => 'A lazy value');

        $lazyProp = new LazyProp(fn () => [
            'prop' => $lazy,
            'another' => new LazyProp(fn () => ['deep' => $lazy]),
            'nonLazy' => 'ok',
        ]);

        $response = new Response('Users', ['lazy' => ['nested' => $lazyProp, 'lazy' => $lazy, 'second' => fn () => 'sec'], 'nope' => 'no'], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData();

        $this->assertEquals(json_decode(json_encode([
            'lazy' => [
                'nested' => [
                    'prop' => 'A lazy value',
                    'another' => ['deep' => 'A lazy value'],
                    'nonLazy' => 'ok',
                ],
                'lazy' => 'A lazy value',
                'second' => 'sec',
            ],
        ])), $page->props);
    }

    public function test_resolve_only(): void
    {
        $r = new Response('Whatever', []);

        $res = $r->resolveOnly([
            'foo.baz.*',
            'baz',
            '.baz..foo.bar.',
            'foo.bar.baz',
            'foo',
        ]);

        $this->assertEquals(new OnlyNode([
            'foo.baz.*' => new OnlyNode([], false),
            'foo' => new OnlyNode([
                'baz' => new OnlyNode(['*' => new OnlyNode([], true)], false),
                'bar' => new OnlyNode([
                    'baz' => new OnlyNode([], true),
                ]),
            ], true),
            'baz' => new OnlyNode([], true),
            '.baz..foo.bar.' => new OnlyNode([], false),
            'foo.bar.baz' => new OnlyNode([], false),
            '.baz..foo' => new OnlyNode(['bar.' => new OnlyNode([], true)]),
        ]), $res);

        $this->assertEquals(new OnlyNode([], true), $r->resolveOnly([]));
    }

    public function test_top_level_dot_props_get_unpacked(): void
    {
        $props = [
            'auth' => [
                'user' => [
                    'name' => 'Jonathan Reinink',
                ],
            ],
            'auth.user.can' => [
                'do.stuff' => true,
            ],
            'product' => ['name' => 'My example product'],
        ];

        $request = Request::create('/products/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $response = new Response('User/Edit', $props, 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData(true);

        $user = $page['props']['auth']['user'];
        $this->assertSame('Jonathan Reinink', $user['name']);
        $this->assertTrue($user['can']['do.stuff']);
        $this->assertFalse(array_key_exists('auth.user.can', $page['props']));
    }

    public function test_nested_dot_props_do_not_get_unpacked(): void
    {
        $props = [
            'auth' => [
                'user.can' => [
                    'do.stuff' => true,
                ],
                'user' => [
                    'name' => 'Jonathan Reinink',
                ],
            ],
            'product' => ['name' => 'My example product'],
        ];

        $request = Request::create('/products/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $response = new Response('User/Edit', $props, 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData(true);

        $auth = $page['props']['auth'];
        $this->assertSame('Jonathan Reinink', $auth['user']['name']);
        $this->assertTrue($auth['user.can']['do.stuff']);
        $this->assertFalse(array_key_exists('can', $auth));
    }

    public function test_responsable_with_invalid_key(): void
    {
        $request = Request::create('/user/123', 'GET');
        $request->headers->add(['X-Inertia' => 'true']);

        $resource = new FakeResource(["\x00*\x00_invalid_key" => 'for object']);

        $response = new Response('User/Edit', ['resource' => $resource], 'app', '123');
        $response = $response->toResponse($request);
        $page = $response->getData(true);

        $this->assertSame(
            ["\x00*\x00_invalid_key" => 'for object'],
            $page['props']['resource']
        );
    }
}
