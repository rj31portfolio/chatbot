<?php

use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\Api\BusinessApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('business')->middleware(['auth:sanctum','api-tenant','throttle:60,1'])->group(function() {
    Route::get('/leads',[BusinessApiController::class,'leads']);
    Route::get('/leads/{id}',[BusinessApiController::class,'lead']);
    Route::put('/leads/{id}',[BusinessApiController::class,'updateLead']);
    Route::get('/knowledge',[BusinessApiController::class,'knowledge']);
    Route::post('/knowledge',[BusinessApiController::class,'saveKnowledge']);
    Route::put('/knowledge/{id}',[BusinessApiController::class,'saveKnowledge']);
    Route::delete('/knowledge/{id}',[BusinessApiController::class,'deleteKnowledge']);
    Route::get('/conversations',[BusinessApiController::class,'conversations']);
    Route::get('/conversations/{id}',[BusinessApiController::class,'conversation']);
    Route::get('/analytics',[BusinessApiController::class,'analytics']);
});

Route::prefix('widget')->middleware(['throttle:widget', 'widget'])->group(function () {
    Route::get('/config', [WidgetController::class, 'config']);
    Route::post('/init', [WidgetController::class, 'config']);
    Route::post('/session', [WidgetController::class, 'start'])->middleware('throttle:widget-session');
    Route::post('/message', [WidgetController::class, 'message'])->middleware('throttle:widget-message');
    Route::post('/lead', [WidgetController::class, 'lead']);
    Route::post('/appointment', [WidgetController::class, 'appointment']);
    Route::post('/end', [WidgetController::class, 'end']);
    Route::post('/history', [WidgetController::class, 'history']);
});
