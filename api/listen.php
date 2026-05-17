<?php
/**
 * Listen-and-write ("Hörverständnis") challenge module.
 *
 * Dispatched from api/challenge.php. Exposes:
 *   - generateListenChallenge()  → builds 3 questions (2 vocab + 1 GPT sentence)
 *   - submitListenAnswer()       → grades one answer and updates per-user stats
 *
 * Audio is delivered via the existing api/tts.php endpoint (which caches per
 * lang+text hash). Every spoken phrase is wrapped with
 *   "Schreibe das folgende: X, ich wiederhole: X"
 * (German) or the Spanish equivalent, to nudge the TTS into clear pronunciation.
 *
 * The "target" text is never sent to the client until skip/reveal. It is
 * persisted in the listen_questions table (created in api/config.php) keyed by
 * the per-question id sent to the frontend.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/questions.php';

/**
 * Wrap a target phrase with a language-appropriate "write this, I repeat: X"
 * instruction so OpenAI's TTS pronounces the target word clearly twice.
 */
function listen_wrapPrompt(string $lang, string $target, bool $mc = false): string {
    $t = trim($target);
    if ($mc) {
        if ($lang === 'es') {
            return "Elige la siguiente frase de las opciones: $t";
        }
        return "Wähle folgenden Satz aus den Optionen: $t";
    }
    if ($lang === 'es') {
        return "Escribe lo siguiente: $t, repito: $t";
    }
    // default: German
    return "Schreibe das folgende: $t, ich wiederhole: $t";
}

/**
 * Canonical form for "case-only mistake" detection.
 * Lowercases, strips punctuation, collapses whitespace.
 * Diacritics/umlauts are intentionally PRESERVED — getting them wrong counts
 * as a real spelling error.
 */
function listen_normalizeCasePunct(string $s): string {
    $s = mb_strtolower($s);
    $s = listen_stripPunct($s);
    return $s;
}

/**
 * Strip punctuation and collapse whitespace, KEEPING case.
 * Used so punctuation differences alone never count as a mistake.
 */
function listen_stripPunct(string $s): string {
    $s = preg_replace('/[.,;:!?¿¡"\'…\-\(\)\[\]]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Ask OpenAI to invent a very short (3–6 word) A1-level sentence that
 * incorporates $word in the given language. Returns the bare sentence string,
 * or null on failure.
 */
function listen_generateSentence(string $lang, string $word, string $api_key, string $model = 'gpt-4.1-mini'): ?string {
    if ($api_key === '' || $word === '') return null;

    $langName = ($lang === 'es') ? 'Spanish' : 'German';
    $system = 'You are a language learning assistant. '
        . "Create ONE very short, simple A1-level sentence in $langName. "
        . 'The sentence MUST contain the given word verbatim (correct case and form, no inflection changes). '
        . 'Length: exactly 3 to 6 words. '
        . 'No quotation marks, no markdown, no leading dash. '
        . 'Respond ONLY with valid JSON: {"sentence": "..."}';
    $user = "Word to include: $word";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'                 => $model,
            'messages'              => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature'           => 0.7,
            'max_completion_tokens' => 80,
        ]),
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!$response || $http_code !== 200) return null;

    $result  = json_decode($response, true);
    $content = trim($result['choices'][0]['message']['content'] ?? '');
    // Strip markdown fences just in case
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/',           '', $content);
    $parsed = json_decode($content, true);
    if (!isset($parsed['sentence'])) return null;

    $sentence = trim($parsed['sentence']);
    if ($sentence === '') return null;

    // Validate word count (3..6) and that the target word appears
    $wc = str_word_count($sentence, 0, '0123456789áéíóúüöäñÁÉÍÓÚÜÖÄÑß');
    if ($wc < 3 || $wc > 6) return null;
    if (mb_stripos($sentence, $word) === false) return null;

    return $sentence;
}

/**
 * Ask OpenAI to invent 3 distractor sentences that sound phonetically close to
 * $sentence in the given language. Returns an array of 3 strings, or null on
 * failure. Distractors are intended to be hard-to-tell-apart by ear:
 * same word count, same rhythm, 1–2 sound-alike word swaps.
 */
/**
 * Module-level holder for the most recent MC-generation debug reason.
 * Set during generateListenChallenge(); read via listen_getLastMcDebug().
 */
function listen_mcDebug(?string $set = null): ?string {
    static $val = null;
    if (func_num_args() > 0) $val = $set;
    return $val;
}
function listen_getLastMcDebug(): ?string { return listen_mcDebug(); }

function listen_generateDistractors(string $lang, string $sentence, string $api_key, string $model = 'gpt-4.1-mini', ?string &$reason = null): ?array {
    if ($api_key === '') { $reason = 'no_api_key'; return null; }
    if ($sentence === '') { $reason = 'empty_sentence'; return null; }

    $langName = ($lang === 'es') ? 'Spanish' : 'German';
    $system = 'You are a language learning assistant generating listening-comprehension distractors. '
        . "Given an A1-level $langName sentence, produce exactly 6 alternative sentences that a learner could plausibly confuse with the original when listening. "
        . 'Mix TWO styles, roughly half and half: '
        . '(A) SOUND-ALIKE swaps — keep the same word count and rhythm, change only 1 or 2 words to phonetically-similar (sound-alike) words; '
        . '(B) STRUCTURE variants — same topic and A1 register, but rephrased with different word order or different vocabulary (no sound-alike tricks); these need not match the original word count. '
        . 'Every alternative MUST be grammatically correct, MUST differ in meaning from the original, and MUST differ from each other (no near-duplicates, no spelling-only changes). '
        . 'No quotation marks, no markdown, no leading dash. '
        . 'Respond ONLY with valid JSON: {"distractors": ["...", "...", "...", "...", "...", "..."]}';
    $user = "Original sentence: $sentence";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'                 => $model,
            'messages'              => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature'           => 0.8,
            'max_completion_tokens' => 400,
        ]),
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    if (!$response || $http_code !== 200) {
        $reason = 'openai_http_' . $http_code . ($curl_err ? ' curl=' . $curl_err : '');
        return null;
    }
    $result  = json_decode($response, true);
    $content = trim($result['choices'][0]['message']['content'] ?? '');
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/',           '', $content);
    $parsed = json_decode($content, true);
    if (!isset($parsed['distractors']) || !is_array($parsed['distractors'])) {
        $reason = 'bad_json content=' . substr($content, 0, 200);
        return null;
    }

    $out = [];
    foreach ($parsed['distractors'] as $d) {
        if (!is_string($d)) continue;
        $d = trim($d);
        if ($d !== '') $out[] = $d;
    }
    if (count($out) < 3) {
        $reason = 'too_few_distractors got=' . count($out) . ' raw=' . json_encode($parsed['distractors'], JSON_UNESCAPED_UNICODE);
        return null;
    }
    return $out;
}

/**
 * Build the 4-option MC list from a target sentence + 3 distractors,
 * de-duplicating via case-folded + punctuation-stripped key. Returns the
 * original-cased option strings, or null if fewer than 4 distinct entries.
 */
function listen_buildDistinctOptions(string $sentence, array $distractors, ?string &$reason = null): ?array {
    $options = [];
    $seen = [];
    $skipped = [];
    $push = function (string $s) use (&$options, &$seen, &$skipped): void {
        $key = mb_strtolower(listen_stripPunct($s));
        if ($key === '' || isset($seen[$key])) { $skipped[] = $s; return; }
        $seen[$key] = true;
        $options[] = $s;
    };
    $push(trim($sentence));
    foreach ($distractors as $d) {
        if (is_string($d)) $push(trim($d));
    }
    if (count($options) < 4) {
        $reason = 'not_enough_unique got=' . count($options)
            . ' kept=' . json_encode($options, JSON_UNESCAPED_UNICODE)
            . ' skipped=' . json_encode($skipped, JSON_UNESCAPED_UNICODE);
        return null;
    }
    return array_slice($options, 0, 4);
}

/**
 * Persist a listen question's target text in the listen_questions table so we
 * can validate the answer later without exposing the target to the client.
 * For MC questions, the option list is stored as JSON so the server can verify
 * the user actually picked a presented option.
 */
function listen_storeQuestion(PDO $pdo, string $id, string $target, string $lang, ?array $options = null): void {
    $options_json = ($options !== null) ? json_encode(array_values($options)) : null;
    $stmt = $pdo->prepare(
        "INSERT INTO listen_questions (id, target, lang, options_json) VALUES (?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET target = excluded.target, lang = excluded.lang, options_json = excluded.options_json"
    );
    $stmt->execute([$id, $target, $lang, $options_json]);
}

/**
 * Build a single listen-question dict for the frontend.
 * The frontend only ever sees id, type, lang and audio_url — never the target.
 * If $options is provided, the question is rendered in multiple-choice mode
 * and the (shuffled) options are returned to the client.
 */
function listen_buildQuestion(PDO $pdo, string $target, string $lang, ?array $options = null): array {
    $target = trim($target);
    $id = 'listen_' . sha1($lang . ':' . $target);
    listen_storeQuestion($pdo, $id, $target, $lang, $options);

    $wrapped = listen_wrapPrompt($lang, $target, $options !== null);
    $audio_url = 'api/tts.php?lang=' . urlencode($lang) . '&text=' . urlencode($wrapped);

    $q = [
        'id'        => $id,
        'type'      => 'listen',
        'lang'      => $lang,
        'audio_url' => $audio_url,
    ];
    if ($options !== null) {
        $shuffled = $options;
        shuffle($shuffled);
        $q['mode']    = 'mc';
        $q['options'] = array_values($shuffled);
    }
    return $q;
}

/**
 * Build a full 3-question listen-and-write challenge for the current user.
 *
 * Composition: 2 random vocab words from the user's question YAML + 1 short
 * GPT-generated sentence built around a word the user already "knows well".
 * Falls back gracefully if OpenAI is not configured or fails.
 */
function generateListenChallenge(PDO $pdo, int $user_id, array $game_config): array {
    $stmt = $pdo->prepare("SELECT question_set FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $question_set = $stmt->fetchColumn() ?: null;
    $lang = getLangFromQuestionSet($question_set);

    $all = getQuestions($question_set);
    if (empty($all)) return [];

    // Index by id for quick lookup of "known well" hashes back to the word.
    $by_id = [];
    foreach ($all as $q) { $by_id[$q['id']] = $q; }

    // --- 1) Two random vocab words (single short answer preferred) ---
    $vocab_pool = $all;
    shuffle($vocab_pool);
    $picked = [];
    $picked_ids = [];
    foreach ($vocab_pool as $q) {
        if (count($picked) >= 2) break;
        $ans = trim($q['answers'][0] ?? '');
        if ($ans === '') continue;
        $picked[] = $ans;
        $picked_ids[] = $q['id'];
    }
    if (count($picked) < 2) return [];

    // --- 2) One GPT-built sentence using a "known well" word ---
    $sentence_text = null;

    $api_key = $game_config['openai_api_key'] ?? '';
    $model   = $game_config['openai_model']   ?? 'gpt-4.1-mini';

    if ($api_key !== '') {
        // Prefer a word the user knows well, that wasn't already picked.
        $kw_stmt = $pdo->prepare(
            "SELECT question_hash FROM user_knowledge
             WHERE user_id = ? AND knows_well = 1
             ORDER BY RANDOM()"
        );
        $kw_stmt->execute([$user_id]);
        $known_word = null;
        foreach ($kw_stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
            if (in_array($hash, $picked_ids, true)) continue;
            if (!isset($by_id[$hash])) continue;
            $w = trim($by_id[$hash]['answers'][0] ?? '');
            if ($w === '') continue;
            $known_word = $w;
            break;
        }
        // Fallback: random vocab word (not already picked)
        if ($known_word === null) {
            foreach ($vocab_pool as $q) {
                if (in_array($q['id'], $picked_ids, true)) continue;
                $w = trim($q['answers'][0] ?? '');
                if ($w !== '') { $known_word = $w; break; }
            }
        }
        if ($known_word !== null) {
            $sentence_text = listen_generateSentence($lang, $known_word, $api_key, $model);
        }
    }

    // Try to upgrade the sentence to a 4-option MC question. If distractor
    // generation fails or doesn't yield 4 distinct options, drop the sentence
    // and fall back to a third vocab word. The failure reason is exposed via
    // listen_getLastMcDebug() so the API caller can surface it for debugging.
    $sentence_options = null;
    $mc_reason = null;
    if ($sentence_text === null) {
        $mc_reason = ($api_key === '') ? 'sentence_skipped_no_api_key' : 'sentence_generation_failed';
    } elseif ($api_key === '') {
        $mc_reason = 'no_api_key';
    } else {
        $distractors = listen_generateDistractors($lang, $sentence_text, $api_key, $model, $mc_reason);
        if ($distractors !== null) {
            $sentence_options = listen_buildDistinctOptions($sentence_text, $distractors, $mc_reason);
            if ($sentence_options !== null) $mc_reason = null;
        }
    }
    listen_mcDebug($mc_reason);

    $questions = [];
    foreach ($picked as $t) {
        $questions[] = listen_buildQuestion($pdo, $t, $lang);
    }

    if ($sentence_text !== null && $sentence_options !== null) {
        $questions[] = listen_buildQuestion($pdo, $sentence_text, $lang, $sentence_options);
    } else {
        // No usable MC sentence — substitute a third vocab word.
        foreach ($vocab_pool as $q) {
            if (in_array($q['id'], $picked_ids, true)) continue;
            $a = trim($q['answers'][0] ?? '');
            if ($a === '') continue;
            $questions[] = listen_buildQuestion($pdo, $a, $lang);
            break;
        }
    }
    if (count($questions) < 3) return [];

    shuffle($questions);
    return $questions;
}

/**
 * Grade a single listen-and-write answer. PURE — no DB side effects.
 *
 * Returns: [
 *   'tier'    => 'exact' | 'case_punct' | 'wrong' | 'skip',
 *   'points'  => int,
 *   'target'  => string (only on 'exact' / 'case_punct' / 'skip' — revealed),
 *   'attempts'=> int (echoed back, clamped to >=1),
 * ]
 *
 * Scoring (from game_config, with defaults):
 *   attempt 1: exact=20, case/punct=15, wrong→retry
 *   attempt 2: exact=10, case/punct=5,  wrong→retry
 *   attempt 3+: exact/case_punct=1,     wrong→retry
 *   skip after 3 wrongs: 1 + reveal
 */
function gradeListenAnswer(PDO $pdo, array $game_config, string $id, string $user_answer, int $attempts, bool $skip): array {
    $tgt_stmt = $pdo->prepare("SELECT target, lang, options_json FROM listen_questions WHERE id = ?");
    $tgt_stmt->execute([$id]);
    $row = $tgt_stmt->fetch();
    if (!$row) {
        return ['tier' => 'wrong', 'points' => 0, 'attempts' => max(1, $attempts)];
    }
    $target = (string)$row['target'];
    $options = null;
    if (!empty($row['options_json'])) {
        $decoded = json_decode((string)$row['options_json'], true);
        if (is_array($decoded) && !empty($decoded)) $options = $decoded;
    }
    $is_mc = ($options !== null);

    $p_first_exact   = (int)($game_config['points_listen_first_exact']   ?? 20);
    $p_first_typo    = (int)($game_config['points_listen_first_typo']    ?? 15);
    $p_second_exact  = (int)($game_config['points_listen_second_exact']  ?? 10);
    $p_second_typo   = (int)($game_config['points_listen_second_typo']   ?? 5);
    $p_third_or_skip = (int)($game_config['points_listen_third_or_skip'] ?? 1);

    $attempts = max(1, $attempts);

    if ($skip) {
        return [
            'tier'     => 'skip',
            'points'   => $p_third_or_skip,
            'target'   => $target,
            'attempts' => max(3, $attempts),
        ];
    }

    $u = trim($user_answer);

    if ($is_mc) {
        // MC: tamper-check the picked option, then compare strictly. No typo tier.
        $in_options = false;
        foreach ($options as $opt) {
            if (trim((string)$opt) === $u) { $in_options = true; break; }
        }
        if (!$in_options) {
            return ['tier' => 'wrong', 'points' => 0, 'attempts' => $attempts];
        }
        if ($u !== trim($target)) {
            return ['tier' => 'wrong', 'points' => 0, 'attempts' => $attempts];
        }
        if ($attempts === 1)      $points = $p_first_exact;
        elseif ($attempts === 2)  $points = $p_second_exact;
        else                      $points = $p_third_or_skip;
        return [
            'tier'     => 'exact',
            'points'   => $points,
            'target'   => $target,
            'attempts' => $attempts,
        ];
    }

    // Typed-input grading (single-word vocab listen questions).
    // Punctuation is ignored entirely (no penalty). Case still matters:
    // matching case-and-spelling (modulo punctuation) → exact;
    // matching only case-insensitively → case_punct (typo tier).
    $u_strip = listen_stripPunct($u);
    $t_strip = listen_stripPunct($target);
    if ($u !== '' && $u_strip === $t_strip) {
        $tier = 'exact';
    } elseif ($u !== '' && mb_strtolower($u_strip) === mb_strtolower($t_strip)) {
        $tier = 'case_punct';
    } else {
        $tier = 'wrong';
    }

    if ($tier === 'wrong') {
        return ['tier' => 'wrong', 'points' => 0, 'attempts' => $attempts];
    }

    if ($attempts === 1) {
        $points = ($tier === 'exact') ? $p_first_exact : $p_first_typo;
    } elseif ($attempts === 2) {
        $points = ($tier === 'exact') ? $p_second_exact : $p_second_typo;
    } else {
        $points = $p_third_or_skip;
    }

    return [
        'tier'     => $tier,
        'points'   => $points,
        'target'   => $target,
        'attempts' => $attempts,
    ];
}

/**
 * Increment per-user listen statistics for one resolved result.
 * Call this exactly once per question at challenge-submit time.
 */
function applyListenStats(PDO $pdo, int $user_id, string $tier, int $attempts): void {
    $first_exact = ($tier === 'exact'      && $attempts === 1) ? 1 : 0;
    $first_typo  = ($tier === 'case_punct' && $attempts === 1) ? 1 : 0;
    $skipped     = ($tier === 'skip') ? 1 : 0;
    $upd = $pdo->prepare(
        "UPDATE users
            SET listen_total              = COALESCE(listen_total,0) + 1,
                listen_correct_first_try  = COALESCE(listen_correct_first_try,0)  + ?,
                listen_correct_with_typo  = COALESCE(listen_correct_with_typo,0)  + ?,
                listen_skipped            = COALESCE(listen_skipped,0)            + ?
          WHERE id = ?"
    );
    $upd->execute([$first_exact, $first_typo, $skipped, $user_id]);
}

