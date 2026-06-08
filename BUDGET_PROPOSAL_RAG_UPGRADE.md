# Budget Proposal: Tahaffuz RAG System Upgrade

**Date:** June 8, 2026

---

## Executive Summary

The Tahaffuz RAG (Retrieval-Augmented Generation) system currently experiences response times of 10-15 seconds due to Gemini free-tier limitations. This proposal outlines the infrastructure upgrade required to achieve:

- **Response times under 3 seconds**
- **Pashto and Sindhi language support** (spoken and written)
- **Improved accuracy and reliability**

**Total Monthly Investment: $150 USD (PKR 42,000)**

---

## Current System Limitations

| Issue | Impact |
|-------|--------|
| Gemini Free Tier | Rate-limited, 10-15 second response times |
| No native Pashto/Sindhi | Users cannot interact in regional languages |
| SQLite FTS search | Keyword-based, misses semantic matches |
| No response caching | Repeated queries re-processed every time |

---

## Proposed Solution: Option B (Recommended)

### 1. Language Model (LLM)

| Item | Provider | Purpose |
|------|----------|---------|
| GPT-4o-mini | OpenAI | Fast, accurate text generation |

**Specifications:**
- Response time: 1-3 seconds
- Context window: 128K tokens
- Multilingual: Supports Urdu, Pashto, Sindhi, English
- Streaming: Yes (real-time responses)

| Metric | Monthly Estimate |
|--------|------------------|
| Estimated queries | 10,000 |
| Avg tokens per query | 2,000 |
| **Monthly Cost** | **$35 USD / PKR 9,800** |

---

### 2. Speech-to-Text (Voice Input)

| Item | Provider | Purpose |
|------|----------|---------|
| Whisper API | OpenAI | Convert voice to text |

**Language Support:**
- Urdu
- Pashto
- Sindhi
- English
- Auto-detection enabled

| Metric | Monthly Estimate |
|--------|------------------|
| Audio minutes | 500 minutes |
| Rate | $0.006/minute |
| **Monthly Cost** | **$20 USD / PKR 5,600** |

---

### 3. Text-to-Speech (Voice Output)

| Item | Provider | Purpose |
|------|----------|---------|
| Azure Neural TTS | Microsoft | Natural voice responses |

**Voice Options:**
- Urdu: Male & Female neural voices
- Pashto: Neural voice available
- Sindhi: Standard voice (custom training optional)
- English: Multiple accents

| Metric | Monthly Estimate |
|--------|------------------|
| Characters | 1,000,000 |
| Rate | $16/1M characters |
| **Monthly Cost** | **$25 USD / PKR 7,000** |

---

### 4. Vector Database

| Item | Provider | Purpose |
|------|----------|---------|
| Pinecone Starter | Pinecone | Semantic search |

**Benefits over SQLite FTS:**
- True vector similarity search
- Sub-100ms query times
- Scales to millions of documents
- Managed infrastructure

| Metric | Monthly Estimate |
|--------|------------------|
| Vectors stored | Up to 100,000 |
| Queries | Unlimited |
| **Monthly Cost** | **$0 USD (Free Tier)** |

---

### 5. Embeddings

| Item | Provider | Purpose |
|------|----------|---------|
| text-embedding-3-small | OpenAI | Convert text to vectors |

**Specifications:**
- Dimensions: 1536
- Multilingual support: Excellent
- Best-in-class for Urdu/Pashto/Sindhi

| Metric | Monthly Estimate |
|--------|------------------|
| Tokens embedded | 5,000,000 |
| Rate | $0.02/1M tokens |
| **Monthly Cost** | **$10 USD / PKR 2,800** |

---

### 6. Caching Layer

| Item | Provider | Purpose |
|------|----------|---------|
| Upstash Redis | Upstash | Cache frequent queries |

**Benefits:**
- 80% faster repeat queries
- Reduces API costs
- Serverless (no management)

| Metric | Monthly Estimate |
|--------|------------------|
| Commands | 100,000 |
| Storage | 256MB |
| **Monthly Cost** | **$10 USD / PKR 2,800** |

---

### 7. Contingency Buffer

Reserved for:
- Traffic spikes
- Additional testing
- Emergency scaling

| **Monthly Allocation** | **$50 USD / PKR 14,000** |
|------------------------|--------------------------|

---

## Cost Summary

| Component | Provider | USD/Month | PKR/Month |
|-----------|----------|-----------|-----------|
| LLM (GPT-4o-mini) | OpenAI | $35 | 9,800 |
| Speech-to-Text | OpenAI Whisper | $20 | 5,600 |
| Text-to-Speech | Azure Neural TTS | $25 | 7,000 |
| Vector Database | Pinecone | $0 | 0 |
| Embeddings | OpenAI | $10 | 2,800 |
| Caching | Upstash Redis | $10 | 2,800 |
| Contingency | - | $50 | 14,000 |
| **TOTAL** | | **$150** | **PKR 42,000** |

*Exchange rate used: 1 USD = 280 PKR*

---

## Annual Projection

| Period | USD | PKR |
|--------|-----|-----|
| Monthly | $150 | 42,000 |
| Quarterly | $450 | 126,000 |
| Annual | $1,800 | 504,000 |

---

## Expected Outcomes

| Metric | Current | After Upgrade |
|--------|---------|---------------|
| Response Time | 10-15 seconds | 1-3 seconds |
| Languages Supported | 3 (EN, UR, Dari) | 5 (+Pashto, +Sindhi) |
| Voice Input | Not available | Full support |
| Voice Output | Not available | Full support |
| Search Accuracy | ~70% (keyword) | ~95% (semantic) |
| Uptime | Variable | 99.9% SLA |

---

