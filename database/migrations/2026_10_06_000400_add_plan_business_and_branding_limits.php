<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        foreach(DB::table('subscription_plans')->get() as $plan) {
            $defaults=config('saas.plans.'.$plan->name,[]);
            $limits=json_decode($plan->limits,true)??[];
            $limits+=['businesses'=>$defaults['businesses']??1,'white_label'=>$defaults['white_label']??0];
            DB::table('subscription_plans')->where('id',$plan->id)->update(['limits'=>json_encode($limits)]);
        }
    }
    public function down(): void {}
};
