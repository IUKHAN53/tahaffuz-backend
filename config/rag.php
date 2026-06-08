<?php

return [
    // Provider selection: 'gemini' or 'pinecone' for vectors, 'gemini' or 'whisper' for STT, 'gemini' or 'elevenlabs' for TTS
    'providers' => [
        'vector_store' => env('RAG_VECTOR_PROVIDER', 'gemini'),  // 'gemini' (hybrid) or 'pinecone'
        'stt' => env('RAG_STT_PROVIDER', 'gemini'),              // 'gemini' or 'whisper'
        'tts' => env('RAG_TTS_PROVIDER', 'gemini'),              // 'gemini' or 'elevenlabs'
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        // Separate model for PDF text extraction. flash-lite has a much higher
        // free-tier RPD and is enough for plain text extraction from glyph PDFs.
        'extract_model' => env('GEMINI_EXTRACT_MODEL', 'gemini-2.5-flash-lite'),
        // Model used for voice-message transcription. Defaults to the chat model
        // for Urdu accuracy; set to flash-lite via env if free-tier quota is tight.
        'transcribe_model' => env('GEMINI_TRANSCRIBE_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash')),
        // Text-to-speech model + voice. The 2.5 preview TTS model returns raw
        // PCM (24kHz, 16-bit, mono) which we wrap as WAV. The voice is
        // language-agnostic; Gemini speaks whatever script the text is in, so
        // Roman Urdu is transliterated to Urdu script before synthesis.
        'tts_model' => env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'),
        'tts_voice' => env('GEMINI_TTS_VOICE', 'Kore'),
        'tts_sample_rate' => (int) env('GEMINI_TTS_SAMPLE_RATE', 24000),
        'embed_model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
        'embed_dim' => (int) env('GEMINI_EMBED_DIM', 768),
        // Chunks per batchEmbedContents call. Hard API ceiling is 100; 50 keeps
        // each call well under the free-tier tokens-per-minute quota.
        'embed_batch_size' => (int) env('GEMINI_EMBED_BATCH_SIZE', 50),
        // Pause between embed batches. 429s are still retried (honoring Gemini's
        // retryDelay), this just spaces calls out so we hit the cap less often.
        'embed_inter_batch_ms' => (int) env('GEMINI_EMBED_INTER_BATCH_MS', 1500),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => 60,
        // Page-range batch size for paged PDF extraction. Keeps each Gemini
        // response under the 32K output-token cap; tune down for very heavy
        // pages (lots of Urdu text + diagrams).
        'page_batch' => (int) env('GEMINI_PDF_PAGE_BATCH', 20),
        'inter_batch_delay_ms' => (int) env('GEMINI_PDF_INTER_BATCH_MS', 1500),
    ],

    'retrieval' => [
        'top_k' => (int) env('VECTOR_TOP_K', 6),
        'candidate_pool' => (int) env('VECTOR_CANDIDATE_POOL', 24),
        'min_score' => (float) env('VECTOR_MIN_SCORE', 0.55),
        'vec_weight' => (float) env('VECTOR_WEIGHT', 0.55),
        'kw_weight' => (float) env('KW_WEIGHT', 0.45),
        // Below this RRF score, treat retrieval as "no useful context" and refuse.
        'rrf_floor' => (float) env('RRF_FLOOR', 0.012),

        // Full-module context. Instead of feeding 6 scattered chunks, route the
        // query to the most relevant module(s) and feed each one's FULL text.
        // The chunks are used only to *rank* which modules matter; the parent
        // modules' complete content is then sent, up to the token budget.
        'full_module' => (bool) env('RAG_FULL_MODULE', true),
        // How many top chunks to consider when ranking modules (routing only).
        'routing_top_k' => (int) env('RAG_ROUTING_TOP_K', 12),
        // Total module content fed per query, in (estimated) tokens. The top
        // module is always included even if it alone exceeds this; further
        // modules are added greedily only while they still fit.
        'module_token_budget' => (int) env('RAG_MODULE_TOKEN_BUDGET', 120000),
        // Hard cap on how many modules can be fed at once.
        'max_modules' => (int) env('RAG_MAX_MODULES', 3),
    ],

    'chunking' => [
        'chars' => (int) env('RAG_CHUNK_CHARS', 900),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 120),
    ],

    'system_prompt_ur' => <<<'PROMPT'
آپ "تحفظ" ہیں — پاکستان میں ویکسینیٹرز اور لیڈی ہیلتھ ورکرز کے لیے EPI (Expanded Programme on Immunization) ٹریننگ معاون۔

اہم: آپ کا علم (CONTEXT) اردو میں ہے۔ جب صارف کسی اور زبان میں سوال کرے، تو آپ کو:
1. پہلے اردو CONTEXT سے معلومات سمجھیں
2. پھر اس زبان میں فطری اور درست جواب تیار کریں — لفظ بہ لفظ ترجمہ نہ کریں

سخت اصول (انہیں ہمیشہ مانیں):
1. صرف فراہم کردہ CONTEXT کی بنیاد پر جواب دیں۔
2. اگر CONTEXT میں جواب نہ ہو، یا CONTEXT سوال سے غیر متعلق ہو، تو معذرت کریں۔
3. اپنی طرف سے کوئی طبی مشورہ، دوا، خوراک، یا شیڈول ایجاد نہ کریں۔
4. CONTEXT میں موجود اعداد، تواریخ، اور درجہ حرارت بالکل ویسے ہی نقل کریں جیسے وہاں ہیں۔

زبان کا اصول (لازمی):
- صارف کی زبان پہچانیں اور اسی میں جواب دیں
- اردو سوال → اردو جواب (نستعلیق رسم الخط)
- انگریزی سوال → انگریزی جواب
- رومن اردو سوال → رومن اردو جواب
- پشتو سوال → پشتو جواب (فطری پشتو، لفظی ترجمہ نہیں)
- سندھی سوال → سندھی جواب (فطری سندھی، لفظی ترجمہ نہیں)

جواب کا انداز:
- مختصر اور سادہ زبان میں۔ ٹیکنیکل اصطلاحات کی وضاحت کریں۔
- جوابات 3 جملوں سے زیادہ نہ ہوں جب تک ضروری نہ ہو۔
- ایک ہی جواب میں زبانیں نہ ملائیں۔

CONTEXT کے ٹکڑے "[DOC: عنوان]" کے ساتھ نشان زد ہیں — جواب میں کسی دستاویز کا حوالہ نہ دیں۔
PROMPT,

    // Language-specific system prompts for better translation
    'system_prompt_ps' => <<<'PROMPT'
تاسو "تحفظ" یاست — د پاکستان د ویکسینیټرانو او روغتیا کارکونکو لپاره د EPI روزنې مرستندویه.

مهمه خبره: ستاسو پوهه (CONTEXT) په اردو کې ده. تاسو باید:
1. لومړی د اردو CONTEXT څخه معلومات پوه شئ
2. بیا په طبیعي پښتو کې ځواب ورکړئ — لغت په لغت ژباړه مه کوئ

سخت قواعد:
1. یوازې د CONTEXT پر بنسټ ځواب ورکړئ
2. که CONTEXT کې ځواب نه وي: "بخښنه غواړم، زه د دې په اړه معلومات نلرم"
3. د ځان لخوا طبي مشوره مه جوړوئ
4. شمیرې او نیټې دقیق ولیکئ

ځواب باید:
- لنډ او ساده وي
- په طبیعي پښتو کې وي، نه لغت په لغت ژباړه
PROMPT,

    'system_prompt_sd' => <<<'PROMPT'
توهان "تحفظ" آهيو — پاڪستان ۾ ويڪسينيٽرز ۽ هيلٿ ورڪرز لاءِ EPI ٽريننگ اسسٽنٽ.

اهم ڳالهه: توهان جو علم (CONTEXT) اردو ۾ آهي. توهان کي گهرجي:
1. پهريان اردو CONTEXT مان معلومات سمجهو
2. پوءِ قدرتي سنڌيءَ ۾ جواب ڏيو — لفظ بہ لفظ ترجمو نه ڪريو

سخت قاعدا:
1. صرف CONTEXT جي بنياد تي جواب ڏيو
2. جيڪڏهن CONTEXT ۾ جواب نه هجي: "معاف ڪجو، مون وٽ ان بابت معلومات ناهي"
3. پنهنجي طرفان طبي صلاح نه ٺاهيو
4. انگ ۽ تاريخون صحيح لکو

جواب هجڻ گهرجي:
- مختصر ۽ سادو
- قدرتي سنڌيءَ ۾، نه لفظ بہ لفظ ترجمو
PROMPT,
];
