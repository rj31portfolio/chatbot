<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WidgetController;
Route::prefix('widget')->middleware(['throttle:widget','widget'])->group(function() {
    Route::get('/config',[WidgetController::class,'config']); Route::post('/init',[WidgetController::class,'config']);
    Route::post('/session',[WidgetController::class,'start'])->middleware('throttle:widget-session');
    Route::post('/message',[WidgetController::class,'message'])->middleware('throttle:widget-message');
    Route::post('/lead',[WidgetController::class,'lead']); Route::post('/appointment',[WidgetController::class,'appointment']);
    Route::post('/end',[WidgetController::class,'end']); Route::post('/history',[WidgetController::class,'history']);
});
