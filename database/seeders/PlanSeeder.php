<?php
namespace Database\Seeders;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach(config('saas.plans') as $name=>$values) {
            $price=$values['price']; unset($values['price']);
            SubscriptionPlan::firstOrCreate(['name'=>$name],['monthly_price'=>$price,'yearly_price'=>$price*10,'currency'=>config('saas.currency'),'limits'=>$values]);
        }
    }
}
