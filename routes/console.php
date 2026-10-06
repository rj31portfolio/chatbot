<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:create-admin',function() {
    $email=$this->ask('Admin email');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email.'); return 1; }
    $password=$this->secret('Password (at least 12 characters, letters and numbers)');
    if(strlen($password)<12||!preg_match('/[a-z]/i',$password)||!preg_match('/[0-9]/',$password)) { $this->error('Password does not meet requirements.'); return 1; }
    if(\App\Models\User::where('email',$email)->exists()) { $this->error('An account with that email already exists.'); return 1; }
    $u=\App\Models\User::create(['name'=>$this->ask('Admin name','Platform Admin'),'email'=>$email,'password'=>$password]);
    $u->forceFill(['is_super_admin'=>true,'email_verified_at'=>now()])->save(); $this->info('Super admin created.');
})->purpose('Create a super admin interactively without default credentials');

Artisan::command('app:maintenance',function() {
    \App\Models\Business::where('status','active')->each(function($business) {
        app(\App\Support\TenantContext::class)->run($business,function() {
            $settings=\App\Models\AiSetting::first(); if(!$settings) return;
            \App\Models\ChatSession::where('created_at','<',now()->subDays($settings->retention_days))->delete();
            \App\Models\ChatSession::whereIn('status',['active','transferred'])->where('last_activity','<',now()->subMinutes(30))->each(function($s) {
                $s->update(['status'=>'abandoned']); \App\Jobs\SummarizeConversation::dispatch($s->business_id,$s->id);
            });
            \App\Models\Subscription::whereIn('status',['active','trial'])->where(function($q){ $q->where('ends_at','<',now())->orWhere(function($q){$q->where('status','trial')->where('trial_ends_at','<',now());}); })->update(['status'=>'expired']);
            \App\Models\AnalyticsDaily::updateOrCreate(['date'=>now()->toDateString()],['metrics'=>app(\App\Services\AnalyticsService::class)->metrics()]);
        });
    });
    $this->info('Retention, idle sessions, subscription expiry, and analytics processed.');
})->purpose('Process scheduled tenant maintenance');
Schedule::command('app:maintenance')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
