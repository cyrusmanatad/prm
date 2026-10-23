<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()->hasVerifiedEmail() ? '/workspaces' : '/verify-email');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === 'P0004') {
                return false;
            }
        });
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (($exception->errorInfo[0] ?? null) !== 'P0004') {
                return null;
            }
            $message = 'Pilot entitlement is inactive or its resource limit has been reached. Existing records remain available. Contact the pilot administrator.';

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message, 'errors' => ['quota' => [$message]]], 422)
                : back()->withErrors(['quota' => $message]);
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $messages = [
                403 => 'This page is not available to your account.',
                404 => 'We could not find this page.',
                409 => 'Choose an available workspace to continue.',
                419 => 'Your session expired. Sign in again to continue.',
                429 => 'Too many attempts. Wait a minute and try again.',
                500 => 'Something went wrong. Please try again.',
                503 => 'WeavDash is temporarily unavailable. Please try again later.',
            ];
            if ($request->is('onboarding/*')) {
                $messages[409] = 'This form changed or is no longer editable. Reload it to see its latest status.';
            }
            if ($request->is('partner-invitations*')) {
                $messages[409] = 'Invitation unavailable. Check your role and outstanding invitations.';
            }
            if ($request->is('lead')) {
                $messages[409] = 'This submission changed. Reload the form before trying again.';
            }
            $status = $response->getStatusCode();
            if ((! $request->expectsJson() || $request->header('X-Inertia')) && isset($messages[$status])) {
                return Inertia::render('Error', ['statusCode' => $status, 'message' => $messages[$status]])
                    ->toResponse($request)->setStatusCode($status)
                    ->withHeaders(array_filter([
                        'Cache-Control' => 'no-store, private',
                        'Retry-After' => $response->headers->get('Retry-After'),
                    ]));
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
