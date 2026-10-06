<?php
namespace Database\Seeders;
use App\Models\{User,ChatWidget,ChatWidgetDomain,BusinessService,BusinessFaq};
use App\Services\{BusinessService as BusinessCreator,KnowledgeService};
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if(app()->environment('production')) throw new \RuntimeException('Demo seeding is disabled in production.');
        $this->call(PlanSeeder::class); $email='demo@example.test';
        if(User::where('email',$email)->exists()) return;
        $password=Str::random(22).'A1';
        $user=User::create(['name'=>'Demo Owner','email'=>$email,'password'=>$password]);
        $business=app(BusinessCreator::class)->create($user,['name'=>'Demo Digital Agency','industry'=>'Digital marketing','description'=>'We help businesses grow through websites and digital marketing.','website_url'=>'https://example.com','email'=>'hello@example.test','phone'=>'+91 9000000000','city'=>'New Delhi','timezone'=>'Asia/Kolkata','profile'=>['hours'=>'Monday to Friday, 9 AM to 6 PM']]);
        app(TenantContext::class)->run($business,function() {
            $widget=ChatWidget::firstOrFail(); $widget->update(['is_demo'=>true]);
            foreach(array_unique([parse_url(config('app.url'),PHP_URL_HOST),'localhost','127.0.0.1']) as $domain) if($domain) ChatWidgetDomain::firstOrCreate(['chat_widget_id'=>$widget->id,'domain'=>$domain]);
            foreach(['Website Development'=>'Business websites with responsive design and lead capture. Pricing starts at INR 25,000.','SEO'=>'Search engine optimization services. Pricing starts at INR 12,000 per month.','Google Ads'=>'Google Ads campaign planning and management. A custom quotation is required.','Meta Ads'=>'Facebook and Instagram ad management. A custom quotation is required.','Social Media Marketing'=>'Content planning and social media account management. A custom quotation is required.'] as $title=>$content) {
                $doc=app(KnowledgeService::class)->save(['source_type'=>'service','title'=>$title,'content'=>$title.': '.$content]);
                BusinessService::create(['title'=>$title,'content'=>$content,'document_id'=>$doc->id,'pricing'=>$title==='Website Development'?'From INR 25,000':null]);
            }
            foreach(['What are your working hours?'=>'Our working hours are Monday to Friday, 9 AM to 6 PM.','Where are you located?'=>'We are located in New Delhi and work remotely with clients.','How can I contact you?'=>'Email hello@example.test or call +91 9000000000.','What is your website pricing?'=>'Website development starts at INR 25,000. The final quotation depends on requirements.'] as $title=>$content) {
                $doc=app(KnowledgeService::class)->save(['source_type'=>'faq','title'=>$title,'content'=>$title.' '.$content]);
                BusinessFaq::create(['title'=>$title,'content'=>$content,'document_id'=>$doc->id]);
            }
            app(KnowledgeService::class)->save(['source_type'=>'policy','title'=>'Demo agency policy','content'=>'Project scope, timeline, and payment terms must be agreed in writing. No discounts are authorized by this chatbot.']);
        });
        file_put_contents(storage_path('app/demo-credentials.txt'),"Local demo login\nEmail: {$email}\nPassword: {$password}\n");
        $this->command?->info('Demo business created. Local credentials: storage/app/demo-credentials.txt');
    }
}
