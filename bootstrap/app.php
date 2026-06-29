<?php

use App\Console\Commands\PollPrintersCommand;
use App\Console\Commands\RecordDailyMeterSnapshotCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(PollPrintersCommand::class)->everyTenMinutes()->withoutOverlapping();
        $schedule->command(RecordDailyMeterSnapshotCommand::class)
            ->dailyAt(config('printers.daily_snapshot_at', '00:00'))
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Полностью отключаем CSRF на всех путях.
        $middleware->removeFromGroup('web', ValidateCsrfToken::class);
        $middleware->validateCsrfTokens(except: ['*']);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Логируем реальные TypeError и TokenMismatch, чтобы в laravel.log был виден стек.
        $exceptions->render(function (TokenMismatchException $e, $request) {
            if (function_exists('logger')) {
                logger()->error('TokenMismatch (419): ' . $e->getMessage(), [
                    'exception' => $e,
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'payload' => $request->all(),
                ]);
            }
            return response()->view('errors.419', ['exception' => $e], 419);
        });
        $exceptions->render(function (\TypeError $e, $request) {
            if (function_exists('logger')) {
                logger()->error('TypeError caught as 419: ' . $e->getMessage(), [
                    'exception' => $e,
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'payload' => $request->all(),
                ]);
            }
            return response()->view('errors.419', ['exception' => $e], 419);
        });
    })->create();