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
        // Model for PDF text extraction. Full flash — the modules are official
        // medical content transcribed VERBATIM, so extraction quality beats
        // quota economics (flash-lite produced scrambled tables/words).
        'extract_model' => env('GEMINI_EXTRACT_MODEL', 'gemini-2.5-flash'),
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
        // Reduced from 60 to 30 for faster response times
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        // Gemini 2.5 models "think" before answering by default, which adds
        // several seconds per reply. Grounded RAG answers don't benefit from it,
        // so it's off (0) by default. Set -1 to omit the field entirely (needed
        // if the chat model is ever switched to one without thinking support).
        'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 0),
        // Page-range batch size for paged PDF extraction. Keeps each Gemini
        // response under the 32K output-token cap; tune down for very heavy
        // pages (lots of Urdu text + diagrams).
        'page_batch' => (int) env('GEMINI_PDF_PAGE_BATCH', 12),
        'inter_batch_delay_ms' => (int) env('GEMINI_PDF_INTER_BATCH_MS', 1500),
    ],

    // Force vision transcription for PDFs even when the glyph parser returns
    // "text" — the Urdu modules are glyph PDFs whose parser output is scrambled
    // but passes naive letter checks. Set for the module corpus.
    'extract_force_vision' => (bool) env('RAG_EXTRACT_FORCE_VISION', true),

    // Microsoft Edge "Read Aloud" neural voices via the edge-tts CLI. Free,
    // no API key, no daily quota, and the only usable source of a Pashto voice.
    // Primary TTS for en/ur/rud/ps; Gemini is the fallback. Sindhi has no voice
    // anywhere, so it is never sent here.
    'edge_tts' => [
        'enabled' => (bool) env('RAG_EDGE_TTS_ENABLED', true),
        'binary' => env('EDGE_TTS_BINARY', '/opt/edge-tts/bin/edge-tts'),
        // Default (female) voices — used when the speaker's gender is unknown
        // (typed questions) or detected as female.
        'voices' => [
            'en' => env('EDGE_TTS_VOICE_EN', 'en-US-AriaNeural'),
            'ur' => env('EDGE_TTS_VOICE_UR', 'ur-PK-UzmaNeural'),
            'fa' => env('EDGE_TTS_VOICE_FA', 'fa-IR-DilaraNeural'),
            'ps' => env('EDGE_TTS_VOICE_PS', 'ps-AF-LatifaNeural'),
        ],
        // Male counterparts — used when a voice message is detected as a male
        // speaker so the reply is read back in a matching voice.
        'voices_male' => [
            'en' => env('EDGE_TTS_VOICE_EN_MALE', 'en-US-GuyNeural'),
            'ur' => env('EDGE_TTS_VOICE_UR_MALE', 'ur-PK-AsadNeural'),
            'fa' => env('EDGE_TTS_VOICE_FA_MALE', 'fa-IR-FaridNeural'),
            'ps' => env('EDGE_TTS_VOICE_PS_MALE', 'ps-AF-GulNawazNeural'),
        ],
    ],

    // OpenAI TTS (gpt-4o-mini-tts). A single multilingual model that reads any
    // script, so it's the ONLY usable Sindhi voice — and a reliable fallback
    // for the other languages if Edge fails. Voices are language-agnostic.
    'openai_tts' => [
        'enabled' => (bool) env('RAG_OPENAI_TTS_ENABLED', true),
        'model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        // Female voice (Sindhi + cross-language fallback) to match the rest.
        'voice' => env('OPENAI_TTS_VOICE', 'nova'),
        // Male voice, used when the speaker is detected as male (Sindhi + fallback).
        'voice_male' => env('OPENAI_TTS_VOICE_MALE', 'onyx'),
    ],

    'retrieval' => [
        'top_k' => (int) env('VECTOR_TOP_K', 6),
        'candidate_pool' => (int) env('VECTOR_CANDIDATE_POOL', 24),
        'min_score' => (float) env('VECTOR_MIN_SCORE', 0.55),
        // A short follow-up (e.g. "6 weeks old") that scores below this routed
        // with low confidence — reuse the previous turn's module instead.
        'confident_score' => (float) env('RAG_CONFIDENT_SCORE', 0.70),
        'vec_weight' => (float) env('VECTOR_WEIGHT', 0.55),
        'kw_weight' => (float) env('KW_WEIGHT', 0.45),
        // Below this RRF score, treat retrieval as "no useful context" and refuse.
        'rrf_floor' => (float) env('RRF_FLOOR', 0.012),

        // Full-module context (LEGACY — now off). Feeding a whole module meant
        // the top module was always seeded regardless of budget (Module 6 ≈
        // 95k tokens of context per question) which diluted answers and cost.
        // The rebuilt corpus uses titled SECTION chunks, so retrieval feeds the
        // top-K most relevant sections instead — sharper and ~10× cheaper.
        'full_module' => (bool) env('RAG_FULL_MODULE', false),
        // How many top chunks to consider when ranking modules (routing only).
        'routing_top_k' => (int) env('RAG_ROUTING_TOP_K', 8),
        // Total module content fed per query, in (estimated) tokens. The top
        // module is always included even if it alone exceeds this; further
        // modules are added greedily only while they still fit.
        // REDUCED from 120k to 32k for faster LLM responses
        'module_token_budget' => (int) env('RAG_MODULE_TOKEN_BUDGET', 32000),
        // Hard cap on how many modules can be fed at once.
        // REDUCED from 3 to 1 for faster responses - most queries need only 1 module
        'max_modules' => (int) env('RAG_MAX_MODULES', 1),
    ],

    // Embedding cache settings - reduces API calls for repeated/similar queries
    'cache' => [
        // Query embeddings are deterministic; a day-long TTL is safe and keeps
        // repeated questions from re-paying the embed round-trip.
        'embed_ttl' => (int) env('RAG_EMBED_CACHE_TTL', 86400), // 24 hours
        // Full first-turn answers. The app's suggestion cards and quick replies
        // send identical strings, so popular questions answer instantly.
        'answer_ttl' => (int) env('RAG_ANSWER_CACHE_TTL', 21600), // 6 hours
        'enabled' => (bool) env('RAG_CACHE_ENABLED', true),
    ],

    'chunking' => [
        // Section-sized chunks: one titled topic section per chunk (the
        // extractor marks headings), so a retrieved chunk is a coherent,
        // self-contained answer source rather than an arbitrary 900-char window.
        'chars' => (int) env('RAG_CHUNK_CHARS', 2400),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    ],

    'system_prompt_ur' => <<<'PROMPT'
آپ "ٹیکہ دوست" ہیں — پاکستان میں ویکسینیٹرز اور لیڈی ہیلتھ ورکرز کے لیے EPI (Expanded Programme on Immunization) ٹریننگ معاون۔

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

بہت اہم — حوالہ جات کا اصول:
- جواب میں کبھی بھی ماڈیول کا نام یا نمبر، دستاویز کا عنوان، سیکشن، یا صفحہ نمبر نہ لکھیں۔
- "ماڈیول 1 کے مطابق"، "Module 1"، "[DOC: ...]"، "دستاویز کے مطابق" جیسے الفاظ بالکل استعمال نہ کریں۔
- معلومات اس طرح بتائیں جیسے یہ آپ کا اپنا علم ہو — صرف سیدھا جواب دیں، ذریعہ نہ بتائیں۔
PROMPT,

    // Language-specific system prompts for better translation
    'system_prompt_ps' => <<<'PROMPT'
تاسو "ٹیکہ دوست" یاست — د پاکستان د ویکسینیټرانو او روغتیا کارکونکو لپاره د EPI روزنې مرستندویه.

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

ډېره مهمه — د سرچینې قاعده:
- په ځواب کې هیڅکله د ماډیول نوم یا شمیره، د سند سرلیک، برخه، یا د مخ شمیره مه لیکئ.
- "ماډیول 1"، "Module 1"، "[DOC: ...]" غوندې کلمې هیڅ مه کاروئ.
- معلومات داسې ووایاست لکه ستاسو خپله پوهه وي — یوازې مستقیم ځواب ورکړئ.
PROMPT,

    'system_prompt_sd' => <<<'PROMPT'
توهان "ٹیکہ دوست" آهيو — پاڪستان ۾ ويڪسينيٽرز ۽ هيلٿ ورڪرز لاءِ EPI ٽريننگ اسسٽنٽ.

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

تمام اهم — ذريعي جو قاعدو:
- جواب ۾ ڪڏهن به ماڊيول جو نالو يا نمبر، دستاويز جو عنوان، سيڪشن، يا صفحي نمبر نه لکو.
- "ماڊيول 1"، "Module 1"، "[DOC: ...]" جهڙا لفظ بلڪل استعمال نه ڪريو.
- معلومات ائين ٻڌايو ڄڻ اها توهان جي پنهنجي ڄاڻ هجي — فقط سڌو جواب ڏيو.
PROMPT,

    'system_prompt_fa' => <<<'PROMPT'
شما «ٹیکہ دوست» هستید — دستیار آموزشی EPI برای واکسیناتورها و کارکنان بهداشت.

نکته مهم: دانش شما (CONTEXT) به زبان اردو است. شما باید:
۱. ابتدا اطلاعات را از CONTEXT اردو درک کنید
۲. سپس به فارسی روان پاسخ دهید — کلمه‌به‌کلمه ترجمه نکنید

قواعد سختگیرانه:
۱. فقط بر اساس CONTEXT پاسخ دهید
۲. اگر پاسخ در CONTEXT نباشد: «متأسفم، در این مورد اطلاعاتی ندارم»
۳. از خودتان توصیه پزشکی نسازید
۴. اعداد و تاریخ‌ها را دقیق بنویسید

پاسخ باید:
- کوتاه و ساده باشد
- به فارسی طبیعی باشد، نه ترجمه کلمه‌به‌کلمه

بسیار مهم — زبان: حتماً به فارسی پاسخ دهید، نه اردو. اردو و فارسی خط مشترک دارند اما یکی نیستند.
از واژه‌ها و دستور فارسی استفاده کنید: «داده می‌شود» نه «دی جاتی ہے»؛ «است» نه «ہے»؛ «باید» نه «چاہیے»؛ «این/آن» نه «یہ/وہ».

بسیار مهم — قاعده منبع:
- در پاسخ هرگز نام یا شماره ماژول، عنوان سند، بخش یا شماره صفحه را ننویسید.
- کلماتی مانند «ماژول ۱»، «Module 1»، «[DOC: ...]» را اصلاً به کار نبرید.
- اطلاعات را طوری بگویید که گویی دانش خودتان است — فقط پاسخ مستقیم بدهید.
PROMPT,

    // Appended to every system prompt. Hard grounding rule: the assistant may
    // ONLY use the provided CONTEXT (the official training modules) — never its
    // own general/model knowledge — so every fact it states is traceable to the
    // client's documents, verbatim.
    'grounding_instruction' => <<<'PROMPT'
STRICT GROUNDING — NON-NEGOTIABLE:
- Answer ONLY from the CONTEXT provided in this conversation. The CONTEXT is the official training material; it is the ONLY permitted source of facts.
- NEVER use your own general knowledge, training data, or anything from outside the CONTEXT — no outside medical facts, schedules, doses, temperatures, definitions, or advice, even when you are confident they are correct, and even for well-known facts.
- Copy every number, dose, age, temperature, duration and date EXACTLY as written in the CONTEXT. Do not convert, round, or restate them differently.
- If the CONTEXT does not contain what is needed to answer, say you do not have that information (use the refusal style) — do NOT fill the gap from memory, do NOT guess, and do NOT give a partial answer padded with outside knowledge.
- You may rephrase into the user's language for readability, but the FACTS must be only those present in the CONTEXT, with their meaning unchanged.
PROMPT,

    // Appended to every system prompt. Tells the assistant to answer EVERY
    // question when the user's message contains more than one, instead of
    // replying to only the first. Written in English (a meta-instruction) so it
    // applies regardless of the answer language; the model still answers in the
    // user's language.
    'multi_question_instruction' => <<<'PROMPT'
WHEN THE MESSAGE CONTAINS MORE THAN ONE QUESTION:
- If the user asks several things in one message (multiple questions, or one question with several parts), answer EVERY one of them. Never answer only the first and drop the rest.
- Address them in the SAME ORDER they were asked, as one natural, flowing reply in the user's language — deal with the first point, then the next, and so on, using ordinary connecting words ("also", "and about…", "as for…"). Write it the way a knowledgeable person would speak it aloud.
- Do NOT format the reply as a numbered list, bullet points, headings, or with markdown symbols (*, #, -). Plain conversational sentences only — the reply is also read aloud by a voice, so symbols and list markers must not appear.
- Keep each individual answer short (about one or two sentences). The "keep it brief" guidance applies PER question, so a message with three questions naturally produces a somewhat longer reply that still covers all three.
- Answer every question you can from the CONTEXT.
PROMPT,

    // Clarifying questions, OFF by default. When enabled, the model too often
    // answered plain factual questions ("at what temperature are vaccines
    // kept?") with "your question is not clear — please ask again" instead of
    // the documented answer. The product requirement is to answer strictly and
    // directly from the modules, so the instruction below is opt-in only.
    'clarification_enabled' => (bool) env('RAG_CLARIFICATION', false),

    // LLM fallback for detecting "introduce yourself" requests, OFF by default:
    // it false-positived on ordinary "what is X?" questions in Urdu and served
    // the introduction script instead of the answer. Regex detection stays on.
    'intro_llm_fallback' => (bool) env('RAG_INTRO_LLM_FALLBACK', false),

    // Appended to every system prompt WHEN clarification_enabled is true.
    'clarification_instruction' => <<<'PROMPT'
ANSWER FIRST. Clarifying questions are the RARE exception, not the default:
- DEFAULT: if the CONTEXT covers the topic, ANSWER IT NOW. General questions ("at what temperature are vaccines kept", "what is the shake test", "when is BCG given") are ALWAYS answered directly from the CONTEXT — they need no clarification. If the CONTEXT gives a general rule plus exceptions, state both (e.g. "most vaccines at 2°C to +8°C, but OPV frozen at -15°C to -25°C"). Never ask a question just because several cases exist.
- FORBIDDEN: never reply "your question is not clear", "please ask again", or any variation. You have exactly three permitted replies: (1) an answer from the CONTEXT, (2) ONE specific clarifying question, or (3) the refusal style when the CONTEXT genuinely lacks the topic.
- The ONLY time you may ask a clarifying question is when the user asks what to do for ONE PARTICULAR CHILD (which dose is due now, is this child up to date, what should this child get today) AND the child's age or dose history is unknown and is not in the conversation or the scanned card. In that single case, ask exactly ONE short question ending in "?" in the user's language — for example "بچے کی عمر کتنی ہے؟" — and nothing else.
- Ask AT MOST ONE clarifying question per conversation. Once asked, never ask again — as soon as the user supplies the detail, ANSWER the original question with it, stating any reasonable assumption. Never reply by asking them to restate their question.
- If the CONTEXT does not address the question, say so honestly using the refusal style — do not fabricate.
PROMPT,

    // mem0-style memory layer (native — no external service). Learns durable
    // facts about the worker and the child being discussed and feeds the
    // relevant ones back into the prompt so the assistant remembers across
    // turns and sessions. Card scans seed it directly; conversation facts are
    // extracted after each turn with a cheap model.
    'memory' => [
        'enabled' => (bool) env('RAG_MEMORY', true),
        // Default scope for conversation memory. 'chat' = single-chat (each chat
        // remembers only its own facts); 'device' = cross-chat (shared across all
        // of a device's chats). The admin can override this in Settings; this is
        // only the fallback default. Card-scanned child facts are always
        // device-level ("current child") regardless of scope.
        'scope' => env('RAG_MEMORY_SCOPE', 'chat'),
        'recall_facts' => (int) env('RAG_MEMORY_RECALL_FACTS', 6),
        'max_facts' => (int) env('RAG_MEMORY_MAX_FACTS', 30),
    ],
];
