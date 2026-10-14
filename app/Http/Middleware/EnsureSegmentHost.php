<?php

namespace App\Http\Middleware;

use App\Support\Segment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSegmentHost
{
    public function handle(Request $request, Closure $next, string $segment): Response
    {
        $host = strtolower($request->getHost());

        foreach (Segment::hosts($segment) as $candidate) {
            if ($host === $candidate) {
                return $next($request);
            }
        }

        abort(404);
    }
}
