<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeGhostwriter
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(User::current()?->can('access ghostwriter'), 403);

        return $next($request);
    }
}
