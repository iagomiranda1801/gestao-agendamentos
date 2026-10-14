<?php

namespace App\Http\Middleware;

use App\Support\Segment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class UseSegmentRequestHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Segment::current() !== null) {
            URL::forceRootUrl($request->getSchemeAndHttpHost());
            URL::forceScheme($request->getScheme());
        }

        return $next($request);
    }
}
