<?php
declare( strict_types = 1 );

use Ravlin\Routing\Router;

test( 'add uppercases methods and returns the route', function() {
	$route = ( new Router() )->add( [ 'get', 'Post' ], '/posts', 'h' );
	expect( [ $route->methods, $route->path, $route->handler ] )->toBe( [ [ 'GET', 'POST' ], '/posts', 'h' ] );
} );

test( 'match finds the first route with the same method and exact path', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	$router->add( [ 'POST' ], '/posts', 'store' );
	$router->add( [ 'GET' ], '/posts', 'dupe' );
	expect( $router->match( 'GET', '/posts' )->route?->handler )->toBe( 'list' )
		->and( $router->match( 'POST', '/posts' )->route?->handler )->toBe( 'store' );
} );

test( 'match finds no route for other methods, a trailing slash, or different case', function( string $method, string $path ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	expect( $router->match( $method, $path )->route )->toBeNull();
} )->with( [ [ 'DELETE', '/posts' ], [ 'GET', '/posts/' ], [ 'GET', '/Posts' ] ] );

test( 'a miss lists the allowed methods for the path, adding HEAD for GET and OPTIONS', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	$router->add( [ 'POST', 'GET' ], '/posts', 'store' );
	$router->add( [ 'DELETE' ], '/other', 'x' );
	expect( $router->match( 'PUT', '/posts' )->allowed )->toBe( [ 'GET', 'POST', 'HEAD', 'OPTIONS' ] );
} );

test( 'allowed only adds HEAD when GET is present', function() {
	$router = new Router();
	$router->add( [ 'POST' ], '/posts', 'store' );
	expect( $router->match( 'PUT', '/posts' )->allowed )->toBe( [ 'POST', 'OPTIONS' ] );
} );

test( 'allowed is empty for an unknown path and for a match', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	expect( $router->match( 'GET', '/nope' )->allowed )->toBe( [] )
		->and( $router->match( 'GET', '/posts' )->allowed )->toBe( [] );
} );

test( 'allowed does not repeat explicitly registered HEAD or OPTIONS', function() {
	$router = new Router();
	$router->add( [ 'HEAD', 'GET' ], '/x', 'a' );
	$router->add( [ 'OPTIONS' ], '/x', 'b' );
	expect( $router->match( 'PUT', '/x' )->allowed )->toBe( [ 'HEAD', 'GET', 'OPTIONS' ] );
} );

test( 'HEAD uses the first GET route unless a HEAD route is registered', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/x', 'get' );
	$router->add( [ 'GET' ], '/x', 'second-get' );
	$router->add( [ 'GET' ], '/y', 'get' );
	$router->add( [ 'HEAD' ], '/y', 'head' );
	expect( $router->match( 'HEAD', '/x' )->route?->handler )->toBe( 'get' )
		->and( $router->match( 'HEAD', '/y' )->route?->handler )->toBe( 'head' );
} );

test( 'add skips methods that are not valid HTTP tokens, with a warning', function() {
	[ $route, $warnings ] = capture_warnings( fn () => ( new Router() )->add( [ 'get', "PUT\nX-Evil: 1", 'GET POST', '', "GET\n", 42, null ], '/x', 'h' ) );
	expect( $route->methods )->toBe( [ 'GET' ] )->and( $warnings )->toBe( array_fill( 0, 6, 'Invalid HTTP method skipped for route: /x' ) );
} );

test( 'match captures named params', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{post_id}/comments/{id}', 'h' );
	$matched = $router->match( 'GET', '/posts/12/comments/abc' );
	expect( [ $matched->route?->handler, $matched->params ] )->toBe( [ 'h', [ 'post_id' => '12', 'id' => 'abc' ] ] );
} );

test( 'static routes have empty params and keep registration order with param routes', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id}', 'param' );
	$router->add( [ 'GET' ], '/posts/new', 'static' );
	$router->add( [ 'GET' ], '/about', 'about' );
	expect( $router->match( 'GET', '/posts/new' )->route?->handler )->toBe( 'param' )
		->and( $router->match( 'GET', '/about' )->params )->toBe( [] );
} );

test( 'params do not match empty, missing, or extra segments', function( string $path ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id}', 'h' );
	expect( $router->match( 'GET', $path )->route )->toBeNull();
} )->with( [ '/posts/', '/posts', '/posts/1/extra', '/posts//1' ] );

test( 'each segment is percent-decoded on its own', function( string $path, array $params ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/files/{name}/{rest}', 'h' );
	expect( $router->match( 'GET', $path )->params )->toBe( $params );
} )->with( [
	'encoded slash stays in the param' => [ '/files/a%2Fb/c', [ 'name' => 'a/b', 'rest' => 'c' ] ],
	'plus is not a space' => [ '/files/a+b/%20', [ 'name' => 'a+b', 'rest' => ' ' ] ],
	'utf-8' => [ '/files/caf%C3%A9/x', [ 'name' => 'café', 'rest' => 'x' ] ],
] );

test( 'literal segments match their percent-encoded form, but an encoded slash never splits a segment', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/new', 'h' );
	$router->add( [ 'GET' ], '/a/b', 'ab' );
	expect( $router->match( 'GET', '/p%6Fsts/new' )->route?->handler )->toBe( 'h' )
		->and( $router->match( 'GET', '/a%2Fb' )->route )->toBeNull();
} );

test( 'paths with control bytes or invalid UTF-8 never match, not even for allowed', function( string $path ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/files/{name}', 'h' );
	$matched = $router->match( 'GET', $path );
	expect( [ $matched->route, $matched->allowed ] )->toBe( [ null, [] ] );
} )->with( [
	'encoded NUL' => '/files/a%00b',
	'raw NUL' => "/files/a\0b",
	'encoded CRLF' => '/files/a%0d%0ab',
	'raw newline' => "/files/a\nb",
	'DEL' => '/files/a%7F',
	'C1 NEL' => '/files/a%C2%85',
	'invalid UTF-8' => '/files/%FF',
	'raw invalid UTF-8' => "/files/\xFF",
	'overlong slash' => '/files/%C0%AF',
] );

test( 'decoding happens once and leaves malformed escapes alone', function( string $path, string $name ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/files/{name}', 'h' );
	expect( $router->match( 'GET', $path )->params )->toBe( [ 'name' => $name ] );
} )->with( [
	'double-encoded slash' => [ '/files/a%252Fb', 'a%2Fb' ],
	'double-encoded NUL' => [ '/files/a%2500', 'a%00' ],
	'bad escape' => [ '/files/%zz%', '%zz%' ],
	'dot-dot' => [ '/files/%2e%2e', '..' ],
] );

test( 'root and single-param routes', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/{id}', 'param' );
	$router->add( [ 'GET' ], '/', 'root' );
	expect( $router->match( 'GET', '/' )->route?->handler )->toBe( 'root' )
		->and( $router->match( 'GET', '/7' )->params )->toBe( [ 'id' => '7' ] );
} );

test( 'a later static HEAD route wins over an earlier param GET route', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id}', 'get' );
	$router->add( [ 'HEAD' ], '/posts/new', 'head' );
	expect( $router->match( 'HEAD', '/posts/new' )->route?->handler )->toBe( 'head' )
		->and( $router->match( 'HEAD', '/posts/1' )->route?->handler )->toBe( 'get' );
} );

test( 'allowed combines static and param routes and works on encoded paths', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id}', 'a' );
	$router->add( [ 'DELETE' ], '/posts/new', 'b' );
	$router->add( [ 'PUT' ], '/posts/{id}/edit', 'c' );
	expect( $router->match( 'POST', '/posts/n%65w' )->allowed )->toBe( [ 'GET', 'DELETE', 'HEAD', 'OPTIONS' ] );
} );

test( 'add skips routes with no valid methods, with a warning', function() {
	$router = new Router();
	[ , $warnings ] = capture_warnings( fn () => $router->add( [], '/x', 'h' ) );
	expect( $warnings )->toBe( [ 'Route with no valid HTTP methods skipped: /x' ] )
		->and( $router->match( 'GET', '/x' )->allowed )->toBe( [] );
} );

test( 'add skips routes with an invalid path, with a warning', function( string $path ) {
	$router = new Router();
	[ $route, $warnings ] = capture_warnings( fn () => $router->add( [ 'GET' ], $path, 'h' ) );
	expect( $route->path )->toBe( $path )->and( $warnings )->toBe( [ 'Invalid route path skipped: ' . $path ] )
		->and( $router->match( 'GET', $path )->route )->toBeNull();
} )->with( [
	'no leading slash' => 'posts', 'unclosed' => '/posts/{id', 'prefixed' => '/posts/x{id}', 'digit name' => '/posts/{1id}',
	'repeated name' => '/a/{id}/{id}', 'empty name' => '/posts/{}',
	'trailing newline' => "/x/{a}\n", 'NUL' => "/raw\0", 'percent' => '/caf%C3%A9', 'invalid UTF-8' => "/\xFF",
	'empty constraint' => '/posts/{id:}', 'bad regex' => '/posts/{id:[}', 'hash in regex' => '/posts/{id:a#b}',
	'regex with control byte' => "/posts/{id:a\tb}", 'unknown type is a literal regex, bad' => '/posts/{id:int(}',
	'trailing backslash' => '/posts/{id:x\\}', 'unterminated \\Q' => '/posts/{id:\\Qab}',
] );

test( 'int constraints match digits only and keep the value a string', function( string $path, ?array $params ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id:int}', 'h' );
	expect( $router->match( 'GET', $path )->route === null ? null : $router->match( 'GET', $path )->params )->toBe( $params );
} )->with( [
	'digits' => [ '/posts/42', [ 'id' => '42' ] ],
	'leading zeros' => [ '/posts/007', [ 'id' => '007' ] ],
	'encoded digit' => [ '/posts/%34', [ 'id' => '4' ] ],
	'letters' => [ '/posts/abc', null ],
	'negative' => [ '/posts/-1', null ],
	'decimal' => [ '/posts/1.5', null ],
	'trailing newline' => [ "/posts/1\n", null ],
	'encoded slash' => [ '/posts/1%2F2', null ],
	'unicode digit' => [ '/posts/%D9%A3', null ],
] );

test( 'custom regex constraints match the whole decoded segment', function( string $route, string $path, bool $hit ) {
	$router = new Router();
	$router->add( [ 'GET' ], $route, 'h' );
	expect( $router->match( 'GET', $path )->route !== null )->toBe( $hit );
} )->with( [
	'slug' => [ '/tags/{slug:[a-z-]+}', '/tags/php-8', false ],
	'slug ok' => [ '/tags/{slug:[a-z-]+}', '/tags/php-x', true ],
	'braces in regex' => [ '/y/{year:[0-9]{4}}', '/y/2026', true ],
	'braces too long' => [ '/y/{year:[0-9]{4}}', '/y/20261', false ],
	'alternation is grouped' => [ '/f/{fmt:json|xml}', '/f/jsonx', false ],
	'alternation ok' => [ '/f/{fmt:json|xml}', '/f/xml', true ],
	'unanchoring regex, prefix' => [ '/x/{v:a)|(b}', '/x/axyz', false ],
	'unanchoring regex, suffix' => [ '/x/{v:a)|(b}', '/x/xyzb', false ],
	'percent allowed in regex' => [ '/p/{n:[0-9]+%}', '/p/50%25', true ],
	'utf-8 decoded before the check' => [ '/t/{t:caf.}', '/t/caf%C3%A9', true ],
	'\\K cannot shorten the match' => [ '/k/{k:abc\\Kd}', '/k/abcd', false ],
	'(*ACCEPT) cannot end early' => [ '/k/{k:a(*ACCEPT)}', '/k/abc', false ],
] );

test( 'a failed constraint falls through to later routes, then to 404', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id:int}', 'by-id' );
	$router->add( [ 'GET' ], '/posts/{slug}', 'by-slug' );
	$router->add( [ 'DELETE' ], '/items/{id:int}', 'delete' );
	expect( $router->match( 'GET', '/posts/5' )->route?->handler )->toBe( 'by-id' )
		->and( $router->match( 'GET', '/posts/hello' )->route?->handler )->toBe( 'by-slug' )
		->and( $router->match( 'GET', '/items/abc' )->allowed )->toBe( [] )
		->and( $router->match( 'GET', '/items/5' )->allowed )->toBe( [ 'DELETE', 'OPTIONS' ] );
} );

test( 'a PCRE error in a constraint fails closed with a warning instead of falling through', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/r/{a:(\\w|\\w)+}', 'constrained' );
	$router->add( [ 'GET' ], '/r/{a}', 'next' );
	$jit = ini_set( 'pcre.jit', '0' );
	$limit = ini_set( 'pcre.backtrack_limit', '100' );
	[ $matched, $warnings ] = capture_warnings( fn () => $router->match( 'GET', '/r/aaaaaaaaaaaa-' ) );
	ini_set( 'pcre.jit', (string) $jit );
	ini_set( 'pcre.backtrack_limit', (string) $limit );
	expect( [ $matched->route, $matched->allowed ] )->toBe( [ null, [] ] )
		->and( $warnings )->toBe( [ 'Route constraint failed, Backtrack limit exhausted: /r/{a:(\\w|\\w)+}' ] );
} );

test( 'HEAD falls back to a constrained GET route only when the constraint passes', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts/{id:int}', 'h' );
	expect( $router->match( 'HEAD', '/posts/5' )->route?->handler )->toBe( 'h' )
		->and( $router->match( 'HEAD', '/posts/x' )->route )->toBeNull();
} );

test( 'a bad constraint regex warns once, without the PCRE compile warning', function() {
	[ , $warnings ] = capture_warnings( fn () => ( new Router() )->add( [ 'GET' ], '/x/{a:[}', 'h' ) );
	expect( $warnings )->toBe( [ 'Invalid route path skipped: /x/{a:[}' ] );
} );
