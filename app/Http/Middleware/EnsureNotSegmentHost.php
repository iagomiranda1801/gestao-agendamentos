<?php

namespace App\Http\Middleware;

use App\Support\Segment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotSegmentHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Segment::current() !== null) {
            abort(404);
        }

        return $next($request);
    }
}
