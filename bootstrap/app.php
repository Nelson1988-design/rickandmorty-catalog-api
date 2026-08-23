<?php

use App\Http\ErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Everything the API exposes lives under /api/v1. The version is there
        // from the first day because the asymmetry is unforgiving: a /v2 can be
        // added whenever it is needed, a /v1 cannot be added afterwards without
        // breaking every client already using the API.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Every error answer carries `message` and `code`.
         *
         * Laravel already produces the message, and `errors` when validation
         * fails. What it does not produce is something stable a client can
         * branch on without parsing prose — that is what `code` is for.
         *
         * This decorates the response the framework built rather than rendering
         * one of our own, which matters: every status Laravel knows how to
         * produce keeps working, and none of them can drift out of the shape
         * because none of them is being reimplemented here. Keys the framework
         * added are preserved, so a stack trace still shows up while debugging.
         */
        $exceptions->respond(function (Response $response): Response {
            if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $payload = $response->getData(true);

            if (! is_array($payload)) {
                return $response;
            }

            return $response->setData([
                'message' => $payload['message'] ?? 'Something went wrong.',
                'code' => ErrorCode::forStatus($response->getStatusCode()),
            ] + $payload);
        });
    })->create();
