<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['tenant'=>\App\Http\Middleware\TenantMiddleware::class,'permission'=>\App\Http\Middleware\BusinessPermission::class,'superadmin'=>\App\Http\Middleware\SuperAdmin::class,'widget'=>\App\Http\Middleware\WidgetAuthentication::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (\Throwable $e, Request $r) {
            if (!$r->is('api/*') && !$r->expectsJson()) return null;
            if($e instanceof \Illuminate\Validation\ValidationException) return response()->json(['success'=>false,'message'=>'Please check the submitted information.','errors'=>$e->errors()],422);
            if($e instanceof \Illuminate\Auth\AuthenticationException) return response()->json(['success'=>false,'message'=>'Please sign in.','errors'=>[]],401);
            if($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status=$e->getStatusCode();
                $message=match($status){404=>'Record not found.',429=>'Too many requests. Please try again shortly.',403=>'Access denied.',401=>'Your chat session expired. Please refresh and try again.',default=>'The request could not be completed.'};
                return response()->json(['success'=>false,'message'=>$message,'errors'=>[]],$status,$e->getHeaders());
            }
            return response()->json(['success'=>false,'message'=>'Sorry, I am having trouble responding right now. Please try again or contact the team.','errors'=>[]],503);
        });
    })->create();
