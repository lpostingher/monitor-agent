<?php

namespace Postingher\MonitorAgent\Support;

use Illuminate\Http\Request;

class RequestUrl
{
    /**
     * Get the request path without its query string, or the route pattern
     * when a route parameter carries a secret such as a reset token.
     */
    public static function for(Request $request, ContextSanitizer $sanitizer): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $hasSecretParameters = collect($route->parameterNames())
            ->contains(fn (string $name): bool => $sanitizer->isSensitive($name));

        return $hasSecretParameters ? $route->uri() : $request->path();
    }
}
