<?php
require_once __DIR__ . '/config.php';

/**
 * VerbTrainer
 * ----------
 * Generates German verb-conjugation challenges.
 *
 * For each call generateChallenge() picks N random (verb, tense, person)
 * combinations, asks OpenAI for a noun that fits as the direct object of
 * "<Pronoun> <conjugated form>", and returns ready-to-render question objects.
 *
 * Question shape:
 *   id          string   "verb_<random>"
 *   type        string   "verb"
 *   verb        string   "machen"
 *   tense       string   "Präteritum"
 *   person      string   "Wir"
 *   noun        string   "Lärm"
 *   question    string   pretty multiline label for the UI
 *   answers     array    accepted full-sentence answers (case/punctuation lenient)
 */
class VerbTrainer {

    /** Pronouns indexed 0..5 = ich/du/er/wir/ihr/sie */
    private const PRONOUNS = ['Ich', 'Du', 'Er', 'Wir', 'Ihr', 'Sie'];

    /** 30 most important German irregular verbs.
     *  Each entry has Präsens (6 forms), Präteritum (6 forms),
     *  auxiliary (haben|sein) and Partizip II.
     */
    private const VERBS = [
        'sein'      => ['praesens'=>['bin','bist','ist','sind','seid','sind'],                                  'praeteritum'=>['war','warst','war','waren','wart','waren'],                                'aux'=>'sein',  'partizip'=>'gewesen'],
        'haben'     => ['praesens'=>['habe','hast','hat','haben','habt','haben'],                                'praeteritum'=>['hatte','hattest','hatte','hatten','hattet','hatten'],                      'aux'=>'haben', 'partizip'=>'gehabt'],
        'werden'    => ['praesens'=>['werde','wirst','wird','werden','werdet','werden'],                         'praeteritum'=>['wurde','wurdest','wurde','wurden','wurdet','wurden'],                      'aux'=>'sein',  'partizip'=>'geworden'],
        'gehen'     => ['praesens'=>['gehe','gehst','geht','gehen','geht','gehen'],                              'praeteritum'=>['ging','gingst','ging','gingen','gingt','gingen'],                          'aux'=>'sein',  'partizip'=>'gegangen'],
        'kommen'    => ['praesens'=>['komme','kommst','kommt','kommen','kommt','kommen'],                        'praeteritum'=>['kam','kamst','kam','kamen','kamt','kamen'],                                'aux'=>'sein',  'partizip'=>'gekommen'],
        'sehen'     => ['praesens'=>['sehe','siehst','sieht','sehen','seht','sehen'],                            'praeteritum'=>['sah','sahst','sah','sahen','saht','sahen'],                                'aux'=>'haben', 'partizip'=>'gesehen'],
        'geben'     => ['praesens'=>['gebe','gibst','gibt','geben','gebt','geben'],                              'praeteritum'=>['gab','gabst','gab','gaben','gabt','gaben'],                                'aux'=>'haben', 'partizip'=>'gegeben'],
        'nehmen'    => ['praesens'=>['nehme','nimmst','nimmt','nehmen','nehmt','nehmen'],                        'praeteritum'=>['nahm','nahmst','nahm','nahmen','nahmt','nahmen'],                          'aux'=>'haben', 'partizip'=>'genommen'],
        'finden'    => ['praesens'=>['finde','findest','findet','finden','findet','finden'],                     'praeteritum'=>['fand','fandst','fand','fanden','fandet','fanden'],                         'aux'=>'haben', 'partizip'=>'gefunden'],
        'bleiben'   => ['praesens'=>['bleibe','bleibst','bleibt','bleiben','bleibt','bleiben'],                  'praeteritum'=>['blieb','bliebst','blieb','blieben','bliebt','blieben'],                    'aux'=>'sein',  'partizip'=>'geblieben'],
        'halten'    => ['praesens'=>['halte','hältst','hält','halten','haltet','halten'],                        'praeteritum'=>['hielt','hieltst','hielt','hielten','hieltet','hielten'],                   'aux'=>'haben', 'partizip'=>'gehalten'],
        'lassen'    => ['praesens'=>['lasse','lässt','lässt','lassen','lasst','lassen'],                         'praeteritum'=>['ließ','ließest','ließ','ließen','ließt','ließen'],                         'aux'=>'haben', 'partizip'=>'gelassen'],
        'stehen'    => ['praesens'=>['stehe','stehst','steht','stehen','steht','stehen'],                        'praeteritum'=>['stand','standst','stand','standen','standet','standen'],                   'aux'=>'haben', 'partizip'=>'gestanden'],
        'sprechen'  => ['praesens'=>['spreche','sprichst','spricht','sprechen','sprecht','sprechen'],            'praeteritum'=>['sprach','sprachst','sprach','sprachen','spracht','sprachen'],              'aux'=>'haben', 'partizip'=>'gesprochen'],
        'essen'     => ['praesens'=>['esse','isst','isst','essen','esst','essen'],                               'praeteritum'=>['aß','aßest','aß','aßen','aßt','aßen'],                                     'aux'=>'haben', 'partizip'=>'gegessen'],
        'trinken'   => ['praesens'=>['trinke','trinkst','trinkt','trinken','trinkt','trinken'],                  'praeteritum'=>['trank','trankst','trank','tranken','trankt','tranken'],                    'aux'=>'haben', 'partizip'=>'getrunken'],
        'schlafen'  => ['praesens'=>['schlafe','schläfst','schläft','schlafen','schlaft','schlafen'],            'praeteritum'=>['schlief','schliefst','schlief','schliefen','schlieft','schliefen'],        'aux'=>'haben', 'partizip'=>'geschlafen'],
        'fahren'    => ['praesens'=>['fahre','fährst','fährt','fahren','fahrt','fahren'],                        'praeteritum'=>['fuhr','fuhrst','fuhr','fuhren','fuhrt','fuhren'],                          'aux'=>'sein',  'partizip'=>'gefahren'],
        'laufen'    => ['praesens'=>['laufe','läufst','läuft','laufen','lauft','laufen'],                        'praeteritum'=>['lief','liefst','lief','liefen','lieft','liefen'],                          'aux'=>'sein',  'partizip'=>'gelaufen'],
        'lesen'     => ['praesens'=>['lese','liest','liest','lesen','lest','lesen'],                             'praeteritum'=>['las','lasest','las','lasen','last','lasen'],                               'aux'=>'haben', 'partizip'=>'gelesen'],
        'schreiben' => ['praesens'=>['schreibe','schreibst','schreibt','schreiben','schreibt','schreiben'],      'praeteritum'=>['schrieb','schriebst','schrieb','schrieben','schriebt','schrieben'],        'aux'=>'haben', 'partizip'=>'geschrieben'],
        'denken'    => ['praesens'=>['denke','denkst','denkt','denken','denkt','denken'],                        'praeteritum'=>['dachte','dachtest','dachte','dachten','dachtet','dachten'],                'aux'=>'haben', 'partizip'=>'gedacht'],
        'bringen'   => ['praesens'=>['bringe','bringst','bringt','bringen','bringt','bringen'],                  'praeteritum'=>['brachte','brachtest','brachte','brachten','brachtet','brachten'],          'aux'=>'haben', 'partizip'=>'gebracht'],
        'wissen'    => ['praesens'=>['weiß','weißt','weiß','wissen','wisst','wissen'],                           'praeteritum'=>['wusste','wusstest','wusste','wussten','wusstet','wussten'],                'aux'=>'haben', 'partizip'=>'gewusst'],
        'können'    => ['praesens'=>['kann','kannst','kann','können','könnt','können'],                          'praeteritum'=>['konnte','konntest','konnte','konnten','konntet','konnten'],                'aux'=>'haben', 'partizip'=>'gekonnt'],
        'müssen'    => ['praesens'=>['muss','musst','muss','müssen','müsst','müssen'],                           'praeteritum'=>['musste','musstest','musste','mussten','musstet','mussten'],                'aux'=>'haben', 'partizip'=>'gemusst'],
        'wollen'    => ['praesens'=>['will','willst','will','wollen','wollt','wollen'],                          'praeteritum'=>['wollte','wolltest','wollte','wollten','wolltet','wollten'],                'aux'=>'haben', 'partizip'=>'gewollt'],
        'sollen'    => ['praesens'=>['soll','sollst','soll','sollen','sollt','sollen'],                          'praeteritum'=>['sollte','solltest','sollte','sollten','solltet','sollten'],                'aux'=>'haben', 'partizip'=>'gesollt'],
        'dürfen'    => ['praesens'=>['darf','darfst','darf','dürfen','dürft','dürfen'],                          'praeteritum'=>['durfte','durftest','durfte','durften','durftet','durften'],                'aux'=>'haben', 'partizip'=>'gedurft'],
        'mögen'     => ['praesens'=>['mag','magst','mag','mögen','mögt','mögen'],                                'praeteritum'=>['mochte','mochtest','mochte','mochten','mochtet','mochten'],                'aux'=>'haben', 'partizip'=>'gemocht'],
    ];

    /** Returns the conjugated form for the given verb/tense/personIdx.
     *  For Futur I and Plusquamperfekt the result is the full auxiliary phrase
     *  (e.g. "werde gehen", "war gegangen", "hatte gemacht").
     */
    public static function conjugate(string $verb, string $tense, int $personIdx): string {
        $entry = self::VERBS[$verb] ?? null;
        if (!$entry) return '';
        if ($tense === 'Präteritum') {
            return $entry['praeteritum'][$personIdx] ?? '';
        }
        if ($tense === 'Futur' || $tense === 'Futur I') {
            // werden + Infinitiv  →  "werde gehen"
            $werden = self::VERBS['werden']['praesens'][$personIdx] ?? '';
            return trim("$werden $verb");
        }
        if ($tense === 'Plusquamperfekt') {
            // haben/sein im Präteritum + Partizip II  →  "hatte gemacht" / "war gegangen"
            $auxVerb = ($entry['aux'] ?? 'haben') === 'sein' ? 'sein' : 'haben';
            $auxForm = self::VERBS[$auxVerb]['praeteritum'][$personIdx] ?? '';
            $partizip = $entry['partizip'] ?? '';
            return trim("$auxForm $partizip");
        }
        // Default: Präsens
        return $entry['praesens'][$personIdx] ?? '';
    }

    /** Build the expected sentence with correct German word order.
     *  Präsens / Präteritum: "<Pron> <konj> <noun>"
     *  Futur I:              "<Pron> werde <noun> <Infinitiv>"   (Verbklammer)
     *  Plusquamperfekt:      "<Pron> hatte <noun> <Partizip II>" (Verbklammer)
     */
    public static function buildSentence(string $verb, string $tense, int $personIdx, string $noun): string {
        $pron = self::PRONOUNS[$personIdx];
        $entry = self::VERBS[$verb] ?? null;

        if ($entry && ($tense === 'Futur' || $tense === 'Futur I')) {
            $werden = self::VERBS['werden']['praesens'][$personIdx] ?? '';
            return trim("$pron $werden $noun $verb");
        }
        if ($entry && $tense === 'Plusquamperfekt') {
            $auxVerb  = ($entry['aux'] ?? 'haben') === 'sein' ? 'sein' : 'haben';
            $auxForm  = self::VERBS[$auxVerb]['praeteritum'][$personIdx] ?? '';
            $partizip = $entry['partizip'] ?? '';
            return trim("$pron $auxForm $noun $partizip");
        }
        // Präsens / Präteritum: simple SVO
        $conj = self::conjugate($verb, $tense, $personIdx);
        return trim("$pron $conj $noun");
    }

    /** Lower-case, strip trailing punctuation, collapse whitespace for comparison. */
    public static function normaliseAnswer(string $s): string {
        $s = trim($s);
        // Strip trailing sentence punctuation (.,!?;)
        $s = preg_replace('/[.!?;,]+$/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s));
    }

    /**
     * Ask OpenAI for one short common noun (with article if needed) that fits as
     * the direct object of "<Pronoun> <conjugated form>".
     * Returns a string like "Lärm" or "ein Buch", or '' on failure.
     */
    private static function fetchMatchingNoun(string $verb, string $tense, int $personIdx, string $api_key, string $model): string {
        $pron = self::PRONOUNS[$personIdx];
        // Build the *actual* sentence template the user will see, with the noun slot marked "___".
        $template = self::buildSentence($verb, $tense, $personIdx, '___');

        $system = 'You suggest a short German noun phrase (Nominalphrase) to fill the "___" slot in a given German sentence template. '
                . 'CRITICAL — IDIOMATIC GERMAN: The resulting sentence must sound like natural, idiomatic German that a native speaker would actually say. '
                . 'Mere grammatical correctness is NOT enough. Reject combinations where a German native would normally use a separable-prefix verb or a different verb. '
                . 'Examples of BAD (unidiomatic) combos to AVOID: "lassen" + "Licht" (a German says "anlassen/auslassen"); "machen" + "Licht" (says "anmachen"); "geben" + "Hand" without "die" ("die Hand geben"); "nehmen" + "Bus" (says "den Bus nehmen" — needs article); "halten" + "Versprechen" needs "ein"; verbs of motion + bare accusative noun. '
                . 'Examples of GOOD idiomatic combos: lesen + "ein Buch", trinken + "einen Kaffee", schreiben + "eine E-Mail", essen + "einen Apfel", sehen + "einen Film", bringen + "Geschenke", finden + "den Schlüssel". '
                . 'IMPORTANT: For countable nouns ALWAYS include a natural article in the correct case ("ein Buch", "den Apfel", "die Tür", "meinen Freund"). '
                . 'Only omit the article for mass/abstract nouns where bare form is natural ("Lärm", "Wasser", "Deutsch", "Hunger", "Zeit"). '
                . 'For movement / state verbs (gehen, kommen, sein, bleiben, fahren, laufen) use a destination/place phrase ("nach Hause", "ins Bett", "im Park", "zur Arbeit"). '
                . 'For modal verbs (können, müssen, wollen, sollen, dürfen, mögen) pick a noun that works as a direct object on its own ("Deutsch", "die Wahrheit", "einen Apfel"). '
                . 'Prefer concrete A1-A2 vocabulary. Keep the noun phrase 1-3 words. '
                . 'Mentally read the FULL sentence aloud — if it sounds even slightly off to a native ear, choose a different noun. '
                . 'Respond ONLY with strict JSON, no markdown: {"noun": "ein Buch"}';

        $user = "Verb (Infinitiv): $verb\nTense: $tense\nSentence template: $template\n\nFill the ___ with one idiomatic noun phrase so the whole sentence sounds completely natural in German.";

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
                'model'                  => $model,
                'messages'               => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature'            => 0.5,
                'max_completion_tokens'  => 60,
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!$resp || $code !== 200) return '';

        $result  = json_decode($resp, true);
        $content = trim($result['choices'][0]['message']['content'] ?? '');
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed  = json_decode($content, true);
        $noun = trim($parsed['noun'] ?? '');
        // Sanity: limit length and strip any sentence punctuation
        $noun = preg_replace('/[.!?;,]+$/u', '', $noun);
        if ($noun === '' || mb_strlen($noun) > 40) return '';
        return $noun;
    }

    /**
     * Build one verb question. $tenses limits which tenses are picked.
     */
    public static function generateOne(string $api_key, string $model, array $tenses = ['Präsens','Präteritum','Futur','Plusquamperfekt']): array {
        $verbs = array_keys(self::VERBS);
        $verb  = $verbs[array_rand($verbs)];
        $tense = $tenses[array_rand($tenses)];

        // Modal verbs sound unnatural in compound tenses (Perfekt/Plusquamperfekt need
        // Ersatzinfinitiv; Futur with modal is rare/awkward) → restrict modals to Präsens / Präteritum.
        $modals = ['können','müssen','wollen','sollen','dürfen','mögen'];
        if (in_array($verb, $modals, true) && in_array($tense, ['Plusquamperfekt','Perfekt','Futur','Futur I'], true)) {
            $allowed = array_values(array_diff($tenses, ['Plusquamperfekt','Perfekt','Futur','Futur I']));
            if (empty($allowed)) $allowed = ['Präsens','Präteritum'];
            $tense = $allowed[array_rand($allowed)];
        }

        $personIdx = random_int(0, 5);

        // Avoid asking AI when no key is configured: fall back to a generic noun
        $noun = '';
        if (!empty($api_key)) {
            $noun = self::fetchMatchingNoun($verb, $tense, $personIdx, $api_key, $model);
        }
        if ($noun === '') {
            $noun = 'etwas'; // safe fallback so the sentence still works
        }

        $sentence = self::buildSentence($verb, $tense, $personIdx, $noun);

        // Accept both with and without trailing period, case-insensitive
        $answers = [
            $sentence,
            $sentence . '.',
        ];

        $pron = self::PRONOUNS[$personIdx];

        // For the displayed hint strip leading articles / preposition-article contractions
        // so the learner has to figure out the correct case/article themselves.
        $displayNoun = preg_replace(
            '/^(?:der|die|das|den|dem|des|ein|eine|einen|einem|einer|eines|mein(?:e|en|em|er|es)?|dein(?:e|en|em|er|es)?|sein(?:e|en|em|er|es)?|ihr(?:e|en|em|er|es)?|unser(?:e|en|em|er|es)?|euer|eure(?:n|m|r|s)?|kein(?:e|en|em|er|es)?|im|am|ins|ans|aufs|zum|zur|beim|vom)\s+/iu',
            '',
            $noun
        );
        if ($displayNoun === '' || $displayNoun === null) {
            $displayNoun = $noun;
        }
        $label = "Verb: $verb\nZeit: $tense\nPerson: $pron\nEinzubauen: $displayNoun";

        return [
            'id'        => 'verb_' . bin2hex(random_bytes(4)),
            'type'      => 'verb',
            'verb'      => $verb,
            'tense'     => $tense,
            'person'    => $pron,
            'noun'      => $noun,
            'question'  => $label,
            'answers'   => $answers,
        ];
    }

    /**
     * Generate $count verb challenges and emit the full JSON response.
     * Used as the "type=verb" branch from challenge.php.
     */
    public static function generateChallengeJson(int $count = 3): string {
        $game_config = require __DIR__ . '/../data/game_config.php';
        $api_key     = $game_config['openai_api_key'] ?? '';
        $model       = $game_config['openai_model']   ?? 'gpt-4.1-mini';

        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $questions[] = self::generateOne($api_key, $model);
        }
        return json_encode([
            'success'   => true,
            'questions' => $questions,
            'type'      => 'verb',
        ]);
    }
}

// ---------------------------------------------------------------------------
// Standalone endpoint (also reachable directly as api/verb.php?action=generate)
// ---------------------------------------------------------------------------
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'verb.php') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $action = $_GET['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'generate') {
        echo VerbTrainer::generateChallengeJson(3);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
