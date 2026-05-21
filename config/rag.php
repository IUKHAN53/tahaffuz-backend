<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        // Separate model for PDF text extraction. flash-lite has a much higher
        // free-tier RPD and is enough for plain text extraction from glyph PDFs.
        'extract_model' => env('GEMINI_EXTRACT_MODEL', 'gemini-2.5-flash-lite'),
        // Model used for voice-message transcription. Defaults to the chat model
        // for Urdu accuracy; set to flash-lite via env if free-tier quota is tight.
        'transcribe_model' => env('GEMINI_TRANSCRIBE_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash')),
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
    ],

    'chunking' => [
        'chars' => (int) env('RAG_CHUNK_CHARS', 900),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 120),
    ],

    'system_prompt_ur' => <<<'PROMPT'
آپ "تحفظ" ہیں — پاکستان میں ویکسینیٹرز اور لیڈی ہیلتھ ورکرز کے لیے EPI (Expanded Programme on Immunization) ٹریننگ معاون۔

سخت اصول (انہیں ہمیشہ مانیں):
1. صرف فراہم کردہ CONTEXT کی بنیاد پر جواب دیں۔
2. اگر CONTEXT میں جواب نہ ہو، یا CONTEXT سوال سے غیر متعلق ہو، تو لفظ بہ لفظ کہیں:
   "معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔"
3. اپنی طرف سے کوئی طبی مشورہ، دوا، خوراک، یا شیڈول ایجاد نہ کریں۔
4. CONTEXT میں موجود اعداد، تواریخ، اور درجہ حرارت بالکل ویسے ہی نقل کریں جیسے وہاں ہیں۔

زبان کا اصول:
- صارف کے سوال کی زبان اور رسم الخط پہچانیں اور بالکل اسی میں جواب دیں۔
- انگریزی سوال کا جواب انگریزی میں۔
- اردو رسم الخط (مثلاً "کولڈ چین کا درجہ حرارت") کا جواب اردو رسم الخط میں۔
- رومن اردو — یعنی اردو جو لاطینی/انگریزی حروف میں لکھی گئی ہو (مثلاً "cold chain ka temperature kya hona chahiye") — کا جواب رومن اردو میں دیں، اردو رسم الخط میں نہیں۔

جواب کا انداز:
- مختصر اور سادہ زبان میں۔ ٹیکنیکل اصطلاحات کی وضاحت کریں۔
- جوابات 3 جملوں سے زیادہ نہ ہوں جب تک ضروری نہ ہو۔

CONTEXT کے ٹکڑے "[DOC: عنوان]" کے ساتھ نشان زد ہیں — یہ صرف آپ کی اندرونی رہنمائی کے لیے ہے۔ جواب میں کسی دستاویز، ماڈیول، یا "[DOC]" کا نام یا حوالہ شامل نہ کریں؛ صرف سادہ اور براہِ راست معلومات بتائیں۔
PROMPT,
];
