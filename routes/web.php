<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Business\{BusinessController,DashboardController,ResourceController,ChatbotController,CrmController,TeamController,SubscriptionController};
use App\Http\Controllers\Admin\AdminController;
Route::view('/','landing')->name('home');
Route::view('/privacy','legal',['type'=>'privacy']);
Route::view('/terms','legal',['type'=>'terms']);
Route::get('/demo',fn()=>view('demo',['widget'=>\App\Models\ChatWidget::withoutGlobalScopes()->where('is_demo',true)->first()]));
Route::middleware('guest')->group(function() {
    Route::get('/login',fn()=>app(AuthController::class)->form('login'))->name('login');
    Route::post('/login',[AuthController::class,'login'])->middleware('throttle:auth');
    Route::get('/register',fn()=>app(AuthController::class)->form('register'))->name('register');
    Route::post('/register',[AuthController::class,'register'])->middleware('throttle:auth');
    Route::get('/forgot-password',fn()=>app(AuthController::class)->form('forgot'))->name('password.request');
    Route::post('/forgot-password',[AuthController::class,'forgot'])->middleware('throttle:auth')->name('password.email');
    Route::get('/reset-password/{token}',[AuthController::class,'resetForm'])->name('password.reset');
    Route::post('/reset-password',[AuthController::class,'reset'])->middleware('throttle:auth')->name('password.update');
});
Route::middleware('auth')->group(function() {
    Route::post('/logout',[AuthController::class,'logout'])->name('logout');
    Route::get('/business/create',[BusinessController::class,'create'])->name('business.create');
    Route::post('/business',[BusinessController::class,'store']);
    Route::post('/business/switch',[BusinessController::class,'switch']);
    Route::middleware('tenant')->group(function() {
        Route::get('/notifications',[\App\Http\Controllers\Business\NotificationController::class,'index']);
        Route::post('/notifications/{id}/read',[\App\Http\Controllers\Business\NotificationController::class,'read']);
        Route::get('/dashboard',[DashboardController::class,'index'])->name('dashboard');
        Route::get('/training',[DashboardController::class,'training']); Route::get('/analytics',[DashboardController::class,'analytics']);
        Route::get('/settings',[BusinessController::class,'edit']); Route::put('/settings',[BusinessController::class,'update']);
        Route::get('/manage/{section}',[ResourceController::class,'index']); Route::post('/manage/{section}',[ResourceController::class,'save']); Route::put('/manage/{section}/{id}',[ResourceController::class,'save']); Route::delete('/manage/{section}/{id}',[ResourceController::class,'delete']);
        Route::post('/documents/upload',[ResourceController::class,'upload']);
        Route::get('/chatbot',[ChatbotController::class,'settings']); Route::put('/chatbot',[ChatbotController::class,'saveSettings']);
        Route::get('/widget',[ChatbotController::class,'widget']); Route::post('/widget',[ChatbotController::class,'newWidget']); Route::put('/widget/{publicId}',[ChatbotController::class,'saveWidget']);
        Route::get('/installation',[ChatbotController::class,'installation']); Route::post('/installation/{publicId}/check',[ChatbotController::class,'check']);
        Route::get('/tester',[ChatbotController::class,'tester']); Route::post('/tester/session',[ChatbotController::class,'testStart'])->middleware('throttle:30,1'); Route::post('/tester/message',[ChatbotController::class,'testMessage'])->middleware('throttle:20,1');
        Route::get('/leads',[CrmController::class,'leads']); Route::get('/leads/{id}',[CrmController::class,'detail']); Route::put('/leads/{id}',[CrmController::class,'update']); Route::delete('/leads/{id}',[CrmController::class,'delete']); Route::post('/leads/{id}/notes',[CrmController::class,'note']);
        Route::get('/conversations',[CrmController::class,'conversations']); Route::post('/conversations/{id}/close',[CrmController::class,'close']); Route::post('/conversations/{id}/reply',[CrmController::class,'humanReply']); Route::delete('/conversations/{id}',[CrmController::class,'deleteConversation']);
        Route::get('/appointments',[CrmController::class,'appointments']); Route::put('/appointments/{id}',[CrmController::class,'appointmentStatus']);
        Route::get('/export/{type}',[CrmController::class,'export']);
        Route::get('/team',[TeamController::class,'index']); Route::post('/team',[TeamController::class,'add']); Route::delete('/team/{id}',[TeamController::class,'remove']);
        Route::get('/subscription',[SubscriptionController::class,'index']); Route::post('/subscription/order',[SubscriptionController::class,'order']); Route::post('/subscription/verify',[SubscriptionController::class,'verify']); Route::post('/subscription/cancel',[SubscriptionController::class,'cancel']);
    });
    Route::prefix('admin')->middleware('superadmin')->group(function() {
        Route::get('/revenue',[\App\Http\Controllers\Admin\RevenueController::class,'index']);
        Route::get('/',[AdminController::class,'index']); Route::get('/plans',[AdminController::class,'plans']); Route::post('/plans',[AdminController::class,'savePlan']); Route::put('/plans/{id}',[AdminController::class,'savePlan']);
        Route::put('/businesses/{id}/status',[AdminController::class,'businessStatus']); Route::get('/users',[AdminController::class,'users']); Route::put('/users/{id}',[AdminController::class,'userStatus']);
        Route::get('/settings',[AdminController::class,'settings']); Route::put('/settings',[AdminController::class,'saveSettings']);
        Route::get('/subscriptions',[AdminController::class,'subscriptions']); Route::put('/subscriptions/{id}',[AdminController::class,'updateSubscription']);
    });
});
