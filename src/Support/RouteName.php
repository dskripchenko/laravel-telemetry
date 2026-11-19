<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The name a request is counted under: the matched route's pattern, with the
 * parameters that name an operation filled in (api/{version}/{controller}/{action}
 * → api/v1/tasks/list). Other parameters stay placeholders, so the list of
 * routes stays short and identifiers stay out of the log.
 */
final class RouteName
{
    /**
     * @param  list<string>  $operationParameters
     */
    public static function of(Request $request, array $operationParameters): string
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return 'unmatched';
        }

        $uri = $route->uri();
        foreach ($operationParameters as $name) {
            $value = $route->parameter($name);
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $value) === 1) {
                $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
            }
        }

        return $uri === '' ? '/' : $uri;
    }
}
