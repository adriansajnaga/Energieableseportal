<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['de' => 'Deutsch', 'pl' => 'Polski'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale');

        if (array_key_exists((string) $locale, self::LOCALES)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
