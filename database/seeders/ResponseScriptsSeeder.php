<?php

namespace Database\Seeders;

use App\Models\ResponseScript;
use Illuminate\Database\Seeder;

class ResponseScriptsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $scripts = [
            [
                'key' => 'introduction',
                'name' => 'Introduction / Greeting',
                'description' => 'Shown when user asks "who are you" or greets the assistant',
                'content_ur' => 'السلام علیکم! میں ٹیکہ دوست ہوں — پاکستان کے ویکسینیٹرز اور لیڈی ہیلتھ ورکرز کے لیے EPI ٹریننگ معاون۔ آپ مجھ سے ویکسین، کولڈ چین، اور حفاظتی ٹیکوں سے متعلق کوئی بھی سوال پوچھ سکتے ہیں۔',
                'content_en' => "Hello! I'm Tika Dost — an EPI training assistant for vaccinators and Lady Health Workers in Pakistan. You can ask me any questions about vaccines, cold chain, and immunization sessions.",
                'content_fa' => 'سلام! من تیکا دوست هستم — دستیار آموزشی EPI برای واکسیناتورها و کارکنان بهداشت زن در پاکستان. می‌توانید هر سوالی درباره واکسن‌ها، زنجیره سرد و جلسات واکسیناسیون از من بپرسید.',
                'content_ps' => 'السلام علیکم! زه ٹیکہ دوست یم — د پاکستان د ویکسینیټرانو او روغتیا کارکونکو لپاره د EPI روزنې مرستندویه. تاسو کولی شئ له ما څخه د واکسینونو، کولډ چین، او واکسینیشن سیشن په اړه هر ډول پوښتنې وکړئ.',
                'content_sd' => 'السلام علیکم! مان ٹیکہ دوست آهيان — پاڪستان جي ويڪسينيٽرز ۽ هيلٿ ورڪرز لاءِ EPI ٽريننگ اسسٽنٽ. توهان مون کان ويڪسين، ڪولڊ چين، ۽ حفاظتي ٽيڪن جي سيشن بابت ڪو به سوال پڇي سگهو ٿا.',
                'is_active' => true,
            ],
            [
                'key' => 'no_answer',
                'name' => 'No Answer Available',
                'description' => 'Shown when the knowledge base has no relevant information for the query',
                'content_ur' => 'معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔',
                'content_en' => "Sorry, I don't have information about that. Please contact your supervisor.",
                'content_fa' => 'متأسفم، در این مورد اطلاعاتی ندارم. لطفاً با سرپرست خود تماس بگیرید.',
                'content_ps' => 'بخښنه غواړم، زه د دې په اړه معلومات نلرم. مهرباني وکړئ خپل سوپروایزر سره اړیکه ونیسئ.',
                'content_sd' => 'معاف ڪجو، مون وٽ ان بابت معلومات ناهي. مهرباني ڪري پنهنجي سپروائيزر سان رابطو ڪريو.',
                'is_active' => true,
            ],
            [
                'key' => 'inaudible',
                'name' => 'Inaudible Voice Message',
                'description' => 'Shown when the voice message could not be understood',
                'content_ur' => 'معذرت، آواز واضح سنائی نہیں دی۔ براہ کرم دوبارہ ریکارڈ کریں۔',
                'content_en' => "Sorry, I couldn't hear that clearly. Please try recording again.",
                'content_fa' => 'متأسفم، صدا واضح شنیده نشد. لطفاً دوباره ضبط کنید.',
                'content_ps' => 'بخښنه، زه دا روښانه واورېدلی نه شم. مهرباني وکړئ بیا ریکارډ کړئ.',
                'content_sd' => 'معاف ڪجو، مان اهو صاف ٻڌي نه سگهيس. مهرباني ڪري ٻيهر رڪارڊ ڪريو.',
                'is_active' => true,
            ],
        ];

        foreach ($scripts as $data) {
            ResponseScript::updateOrCreate(
                ['key' => $data['key']],
                $data
            );
        }
    }
}
