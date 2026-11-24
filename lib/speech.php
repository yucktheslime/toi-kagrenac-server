<?php
// Text to speech for speak.php: the provider request, the NPC's profile voice and the WAV check.
// This file only declares a class, so requesting it over HTTP prints nothing.
//
// tts.provider:
//   'auto' (default)  Finds the DwemerDistro PocketTTS on tts.url (default http://127.0.0.1:8024)
//                     and, when nothing answers there, on the same host's ports 8024, 8020 and 8086.
//   'xtts'            The XTTS-API-server style interface that DwemerDistro PocketTTS (port 8024)
//                     and XTTS (8020) use for CHIM: POST <url>/tts_to_audio with
//                     {"text", "speaker_wav", "language"} and a WAV back; speakers from
//                     GET <url>/speakers_list. 'dwemer-pockettts' is the same.
//   'pockettts'       Older configs: DwemerDistro PocketTTS by URL, the way CHIM's pockettts
//                     connector does it (port 8086 or /v1/audio/speech is audio.cpp, anything else
//                     is the XTTS-style API). When that URL refuses or answers 404/405 the server
//                     looks for PocketTTS like 'auto' and tries the one it finds once.
//   'openai'          Any OpenAI-compatible /v1/audio/speech endpoint that returns WAV.
// On the XTTS-style API the voice must be one of the service's speakers: a missing one falls back
// to tts.voice and then to the first speaker, and voice hints pick among the real speakers.
// The endpoint keeps request validation, the token check, the disabled answer and the connector
// audit row; this class never sees the request, never replies and never logs the text, the audio
// or a key.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/npc_voices.php';

final class SpeechService
{
    public const MAX_WAV_BYTES = 20000000;
    public const DEFAULT_URL = 'http://127.0.0.1:8024';
    public const AUDIO_CPP_URL = 'http://127.0.0.1:8086/v1/audio/speech';
    public const PROVIDERS = ['auto', 'xtts', 'dwemer-pockettts', 'pockettts', 'openai'];
    // The language codes CHIM sends to XTTS-style services; PocketTTS loads the matching model.
    public const LANGUAGES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'tr', 'ru', 'nl', 'cs', 'ar', 'zh-cn', 'ja', 'hu', 'ko', 'hi'];
    // Ports DwemerDistro uses for XTTS-style and audio.cpp voice services, in search order.
    private const KNOWN_PORTS = [8024, 8020, 8086];
    private const CACHE_SECONDS = 60;
    private const VOICE_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';

    // A Trade of Immersion: the NPC this line is for, so a first line can store an automatic voice.
    private ?\PgSql\Connection $npcDb = null;
    private string $npcId = '';
    private string $npcGender = '';
    private ?float $pitch = null;

    private function __construct(private array $tts, private string $voiceSource, private array $hint = [])
    {
    }

    private function withNpc(?\PgSql\Connection $db, ?string $npcId, string $gender, ?array $row): self
    {
        $this->npcDb = $db;
        $this->npcId = (string)$npcId;
        $this->npcGender = $gender;
        $this->pitch = is_array($row) && $row['pitch'] !== null ? (float)$row['pitch'] : null;
        return $this;
    }

    // The NPC's pitch (0.5-2.0) from its voice profile, or null. The game applies it when playing.
    public function pitch(): ?float
    {
        return $this->pitch;
    }

    // The configured provider. Configs from before tts.provider existed are PocketTTS.
    // Takes the raw config section, which may not be an array, so speak.php can name the
    // provider in its audit row before checking tts.enabled.
    public static function provider(mixed $tts): mixed
    {
        $provider = is_array($tts) ? ($tts['provider'] ?? 'pockettts') : 'pockettts';
        return $provider === 'dwemer-pockettts' ? 'xtts' : $provider;
    }

    // The tts settings for one request. The NPC's profile may choose its voice; any problem
    // keeps the configured voice. $npcId only picks the voice, never who speaks.
    // A Trade of Immersion: without a profile voice, an optional $voiceHint (for example 'male' or
    // 'female') picks a stable voice for this NPC, so each NPC keeps sounding the same. On the
    // XTTS-style API the pick is made among the service's real speakers (see hintCandidates).
    public static function forNpc(array $config, array $tts, ?string $npcId, ?string $voiceHint = null, string $npcName = ''): self
    {
        // The configured voice, kept for the speaker-list fallback when a profile or hint voice is missing.
        $tts['_config_voice'] = (string)($tts['voice'] ?? '');
        $gender = in_array($voiceHint, ['female', 'male', 'neutral'], true) ? $voiceHint : '';
        $db = null;
        $row = null;
        if ($npcId !== null) {
            $db = db_connect_quiet($config);
            if ($db !== null && schema_problem($db) !== null) {
                $db = null;
            }
            // A Trade of Immersion: the NPC's own voice profile (ex_npc_voices). A dashboard or
            // /npc voice choice wins over a character profile; an automatic pick comes after it.
            $row = $db !== null ? npc_voice_touch($db, $npcId, $npcName, $gender) : null;
            if (is_array($row) && $row['voice'] !== null && $row['source'] !== 'auto') {
                return (new self(npc_voice_tts($tts, $row), 'npc'))->withNpc($db, $npcId, $gender, $row);
            }
            $profile = $db !== null ? profile_for_npc($db, $npcId) : false;
            if ($profile === false) {
                app_log('profile voice lookup skipped; using the configured voice');
            } elseif (($profile['tts_voice'] ?? null) !== null) {
                return (new self(npc_voice_tts(profile_tts($tts, $profile), $row, false), 'profile'))->withNpc(null, $npcId, $gender, $row);
            }
            if (is_array($row) && $row['voice'] !== null) {
                return (new self(npc_voice_tts($tts, $row), 'auto'))->withNpc($db, $npcId, $gender, $row);
            }
            $tts = npc_voice_tts($tts, $row, false);
        }
        if ($voiceHint !== null) {
            $listed = self::hintList($config, $voiceHint);
            // Known hints are kept even with an empty list, so the speaker list can still answer.
            if ($listed !== [] || in_array($voiceHint, ['female', 'male', 'neutral'], true)) {
                $hint = ['name' => $voiceHint, 'listed' => $listed, 'npc' => $npcId ?? ''];
                if ($listed !== []) {
                    $tts['voice'] = $listed[crc32($npcId ?? '') % count($listed)];
                    return (new self($tts, 'hint', $hint))->withNpc($db, $npcId, $gender, $row);
                }
                return (new self($tts, 'config', $hint))->withNpc($db, $npcId, $gender, $row);
            }
        }
        return (new self($tts, 'config'))->withNpc($db, $npcId, $gender, $row);
    }

    // 'profile', 'hint' or 'config': where the voice came from.
    public function voiceSource(): string
    {
        return $this->voiceSource;
    }

    // One synthesis. Returns [WAV bytes, audit detail] or [null, audit detail].
    public function synthesize(string $text): array
    {
        $tts = $this->tts;
        $provider = self::provider($tts);
        $timeout = (int)($tts['timeout_seconds'] ?? 30);
        if ($provider === 'openai') {
            if ((string)($tts['model'] ?? '') === '') {
                app_log('tts.model must be set for the openai provider');
                return [null, 'model missing'];
            }
            return $this->finish(self::postOpenAi((string)($tts['url'] ?? ''), $tts, $text, true, $timeout), 'openai');
        }
        if (!in_array($provider, ['auto', 'xtts', 'pockettts'], true)) {
            app_log('unknown tts.provider');
            return [null, 'unknown provider'];
        }

        $url = (string)($tts['url'] ?? '') !== '' ? (string)$tts['url'] : self::DEFAULT_URL;
        if ($provider === 'xtts') {
            $service = ['mode' => 'xtts', 'base' => self::baseUrl($url)];
        } elseif ($provider === 'pockettts') {
            $service = self::isAudioCppUrl($url) ? ['mode' => 'audio_cpp', 'base' => self::baseUrl($url)]
                : ['mode' => 'xtts', 'base' => self::baseUrl($url)];
        } else {
            $service = self::findService($url);
            if ($service === null) {
                app_log('no PocketTTS or XTTS-style voice service answered');
                return [null, "no voice service found, voice from {$this->voiceSource}"];
            }
        }

        $result = $this->call($service, $text, $timeout);
        // CHIM's pockettts connector does the same: a stale known port gets one try elsewhere.
        if ($provider === 'pockettts' && in_array($result[0], [0, 404, 405], true)) {
            $found = self::findService($url, $service['base']);
            if ($found !== null) {
                app_log('PocketTTS did not answer on the configured URL; trying the one found at ' . self::hostPort($found['base']));
                $service = $found;
                $result = $this->call($service, $text, $timeout);
            }
        }
        if ($result[0] !== 200 && $provider !== 'xtts') {
            self::forget($url, $service['base']);
        }
        return $this->finish($result, $service['mode'] === 'xtts' ? 'xtts port ' . (parse_url($service['base'], PHP_URL_PORT) ?: 'default') : 'audio.cpp');
    }

    // For the dashboard: which service answers, its speakers and what each voice hint picks from.
    // Never caches a failure and never sends text.
    public static function describe(array $config, array $tts): array
    {
        $provider = self::provider($tts);
        $url = (string)($tts['url'] ?? '') !== '' ? (string)$tts['url'] : self::DEFAULT_URL;
        if ($provider === 'openai') {
            return ['ok' => true, 'mode' => 'openai', 'endpoint' => $url, 'speakers' => [], 'hints' => (object)[]];
        }
        self::forget($url);
        $service = $provider === 'xtts' ? ['mode' => 'xtts', 'base' => self::baseUrl($url)] : self::findService($url);
        if ($service === null) {
            return ['ok' => false, 'mode' => '', 'endpoint' => '', 'speakers' => [], 'hints' => (object)[],
                'message' => 'No PocketTTS answered at ' . self::hostPort($url) . ' or on ports 8024, 8020 and 8086 of that host. Start PocketTTS in the DwemerDistro launcher.'];
        }
        $speakers = $service['mode'] === 'xtts' ? self::speakers($service['base'], true) : null;
        if ($service['mode'] === 'xtts' && $speakers === null) {
            return ['ok' => false, 'mode' => 'xtts', 'endpoint' => $service['base'], 'speakers' => [], 'hints' => (object)[],
                'message' => 'The service at ' . self::hostPort($service['base']) . ' did not list its speakers (GET /speakers_list).'];
        }
        $hints = [];
        foreach (array_unique(array_merge(['female', 'male', 'neutral'], array_keys(is_array($config['voice_hints'] ?? null) ? $config['voice_hints'] : []))) as $name) {
            if (is_string($name) && preg_match('/^[a-z_]{1,16}$/D', $name)) {
                $hints[$name] = $speakers !== null ? self::hintCandidates(self::hintList($config, $name), $name, $speakers, $tts) : self::hintList($config, $name);
            }
        }
        return ['ok' => true, 'mode' => $service['mode'], 'endpoint' => $service['mode'] === 'xtts' ? $service['base'] : self::audioCppUrl($service['base']),
            'speakers' => $speakers ?? [], 'voice' => $speakers !== null ? self::mapVoice((string)($tts['voice'] ?? ''), $tts, $speakers) : (string)($tts['voice'] ?? ''),
            'hints' => (object)$hints];
    }

    // --- one request ---------------------------------------------------------------------------

    // Returns [HTTP status, body, voice used].
    private function call(array $service, string $text, int $timeout): array
    {
        $tts = $this->tts;
        if ($service['mode'] === 'audio_cpp') {
            return self::postOpenAi(self::audioCppUrl($service['base']), $tts, $text, false, $timeout);
        }
        $voice = $this->xttsVoice($service['base']);
        $request = ['text' => $text, 'speaker_wav' => $voice];
        $language = self::language($tts);
        if ($language !== null) {
            $request['language'] = $language;
        }
        return self::post($service['base'] . '/tts_to_audio', $request, [], $timeout) + [2 => $voice];
    }

    // The voice to send on the XTTS-style API, chosen among the service's speakers.
    private function xttsVoice(string $base): string
    {
        $tts = $this->tts;
        $speakers = self::speakers($base) ?? [];
        // A Trade of Immersion: an NPC without a voice gets its own the first time it speaks: a
        // speaker no other NPC uses yet (by gender when the names say so), stored as source 'auto'.
        if ($this->npcDb !== null && $this->npcId !== '' && $speakers !== [] && in_array($this->voiceSource, ['hint', 'config'], true)) {
            $pool = $this->hint !== [] ? self::hintCandidates($this->hint['listed'], $this->hint['name'], $speakers, $tts) : [];
            $picked = npc_voice_auto_assign($this->npcDb, $this->npcId, $pool !== [] ? $pool : $speakers);
            if ($picked !== null) {
                $this->voiceSource = 'auto';
                return $picked;
            }
        }
        if ($this->hint !== [] && !in_array($this->voiceSource, ['profile', 'npc', 'auto'], true)) {
            $candidates = self::hintCandidates($this->hint['listed'], $this->hint['name'], $speakers, $tts);
            if ($candidates !== []) {
                $this->voiceSource = 'hint';
                return $candidates[crc32($this->hint['npc']) % count($candidates)];
            }
        }
        if ($this->voiceSource === 'hint') {
            $this->voiceSource = 'config';
            return self::mapVoice((string)($tts['_config_voice'] ?? ''), $tts, $speakers);
        }
        return self::mapVoice((string)($tts['voice'] ?? ''), $tts, $speakers);
    }

    private static function postOpenAi(string $url, array $tts, string $text, bool $openai, int $timeout): array
    {
        $voice = (string)($tts['voice'] ?? '') !== '' ? (string)$tts['voice'] : 'alba';
        $request = ['model' => (string)($tts['model'] ?? '') !== '' ? $tts['model'] : 'pocket-tts', 'input' => $text, 'voice' => $voice];
        $headers = [];
        if ($openai) {
            $request['response_format'] = 'wav';
            $apiKey = (string)($tts['api_key'] ?? '');
            if ($apiKey !== '') {
                $headers[] = "Authorization: Bearer $apiKey";
            }
        } elseif (($language = self::language($tts)) !== null) {
            $request['language'] = $language;
        }
        return self::post($url, $request, $headers, $timeout) + [2 => $voice];
    }

    private static function post(string $url, array $request, array $headers, int $timeout): array
    {
        try {
            return http_post($url, array_merge(['Content-Type: application/json', 'Accept: audio/wav'], $headers),
                json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $timeout, self::MAX_WAV_BYTES);
        } catch (Throwable $error) {
            app_log($error->getMessage());
            return [0, ''];
        }
    }

    private function finish(array $result, string $route): array
    {
        [$status, $wav] = $result;
        $voice = isset($result[2]) && preg_match(self::VOICE_PATTERN, (string)$result[2]) ? ' ' . $result[2] : '';
        if ($status !== 200 || !is_wav($wav)) {
            app_log("TTS failed with HTTP $status ($route)");
            return [null, "provider HTTP $status ($route), voice$voice from {$this->voiceSource}"];
        }
        return [$wav, 'WAV ' . strlen($wav) . " bytes ($route), voice$voice from {$this->voiceSource}"];
    }

    // --- voices --------------------------------------------------------------------------------

    // tts.language: a CHIM language code, default 'en' like CHIM. '' sends none, so PocketTTS keeps
    // the model it has loaded. Anything else falls back to 'en'.
    public static function language(array $tts): ?string
    {
        if (!array_key_exists('language', $tts)) {
            return 'en';
        }
        $language = strtolower(trim((string)$tts['language']));
        if ($language === '') {
            return null;
        }
        return in_array($language, self::LANGUAGES, true) ? $language : 'en';
    }

    private static function hintList(array $config, string $hint): array
    {
        $voices = $config['voice_hints'][$hint] ?? null;
        return is_array($voices) ? array_values(array_filter($voices, fn($v) => is_string($v) && preg_match(self::VOICE_PATTERN, $v))) : [];
    }

    // The speakers one voice hint picks from: the listed voices the service has; else, for female
    // and male, the speakers whose names say so (CHIM voice names such as femalenord or malebrute);
    // else, for neutral, every speaker. Empty means "use tts.voice".
    public static function hintCandidates(array $listed, string $hint, array $speakers, array $tts): array
    {
        if ($speakers === []) {
            return $listed;
        }
        $have = [];
        foreach ($speakers as $speaker) {
            $have[strtolower($speaker)] = $speaker;
        }
        $picked = [];
        foreach ($listed as $voice) {
            if (isset($have[strtolower(self::voiceName($voice))])) {
                $picked[] = $have[strtolower(self::voiceName($voice))];
            }
        }
        if ($picked !== []) {
            return array_values(array_unique($picked));
        }
        if ($hint === 'female' || $hint === 'male') {
            $female = fn(string $s) => preg_match('/(female|woman|girl|^f_)/i', $s) === 1;
            $male = fn(string $s) => !$female($s) && preg_match('/(male|man\b|^m_)/i', $s) === 1;
            return array_values(array_filter($speakers, $hint === 'female' ? $female : $male));
        }
        return $hint === 'neutral' ? array_values($speakers) : [];
    }

    // A voice the service has: the voice itself, else tts.voice, else the first speaker.
    public static function mapVoice(string $voice, array $tts, array $speakers): string
    {
        if ($speakers === []) {
            return $voice !== '' ? self::voiceName($voice) : 'alba';
        }
        foreach ([$voice, (string)($tts['_config_voice'] ?? $tts['voice'] ?? '')] as $want) {
            $want = strtolower(self::voiceName($want));
            foreach ($speakers as $speaker) {
                if ($want !== '' && strtolower($speaker) === $want) {
                    return $speaker;
                }
            }
        }
        return $speakers[0];
    }

    private static function voiceName(string $voice): string
    {
        $voice = basename(str_replace('\\', '/', trim($voice)));
        return str_ends_with(strtolower($voice), '.wav') ? substr($voice, 0, -4) : $voice;
    }

    // GET /speakers_list: a flat list of names, or CHIM's nested {lang: {speakers: [...]}} form.
    // Null when the service does not answer with a list.
    private static function speakers(string $base, bool $fresh = false): ?array
    {
        $key = 'speakers ' . $base;
        $cached = $fresh ? null : self::cacheGet($key);
        if (is_array($cached)) {
            return $cached;
        }
        $decoded = self::getJson($base . '/speakers_list', 3000);
        if (!is_array($decoded)) {
            return null;
        }
        $flat = [];
        $walk = function ($value) use (&$walk, &$flat) {
            if (is_string($value)) {
                $name = self::voiceName($value);
                if (preg_match(self::VOICE_PATTERN, $name)) {
                    $flat[$name] = true;
                }
            } elseif (is_array($value)) {
                foreach (isset($value['speakers']) && is_array($value['speakers']) ? $value['speakers'] : (array_is_list($value) ? $value : array_values($value)) as $item) {
                    $walk(is_array($item) && isset($item['voice_id']) ? $item['voice_id'] : $item);
                }
            }
        };
        $walk($decoded);
        $list = array_slice(array_keys($flat), 0, 500);
        self::cachePut($key, $list);
        return $list;
    }

    // --- finding the service -------------------------------------------------------------------

    // The configured URL first, then the same host's known ports. Returns ['mode', 'base'] or null.
    private static function findService(string $url, ?string $skip = null): ?array
    {
        $cached = $skip === null ? self::cacheGet('service ' . $url) : null;
        if (is_array($cached) && isset($cached['mode'], $cached['base'])) {
            return $cached;
        }
        foreach (self::candidates($url) as $base) {
            if ($base === $skip) {
                continue;
            }
            $mode = self::detect($base);
            if ($mode !== '') {
                $found = ['mode' => $mode, 'base' => $base];
                self::cachePut('service ' . $url, $found);
                return $found;
            }
        }
        return null;
    }

    private static function candidates(string $url): array
    {
        $base = self::baseUrl($url);
        $list = [$base];
        $parts = parse_url($base);
        $port = (int)($parts['port'] ?? 0);
        if (is_array($parts) && !empty($parts['host']) && in_array($port, self::KNOWN_PORTS, true)) {
            $host = str_contains($parts['host'], ':') && $parts['host'][0] !== '[' ? '[' . $parts['host'] . ']' : $parts['host'];
            $path = rtrim((string)($parts['path'] ?? ''), '/');
            foreach (self::KNOWN_PORTS as $other) {
                $list[] = strtolower((string)$parts['scheme']) . '://' . $host . ':' . $other . $path;
            }
        }
        return array_values(array_unique($list));
    }

    // 'audio_cpp', 'xtts' or '' (not a voice service we know), in CHIM's order.
    private static function detect(string $base): string
    {
        $health = self::getJson($base . '/health');
        if (is_array($health)) {
            $models = self::getJson($base . '/v1/models');
            foreach ((is_array($models) && is_array($models['data'] ?? null)) ? $models['data'] : [] as $model) {
                if (is_array($model) && (strtolower((string)($model['id'] ?? '')) === 'pocket-tts' || strtolower((string)($model['family'] ?? '')) === 'pocket_tts')) {
                    return 'audio_cpp';
                }
            }
            if (($health['api_family'] ?? '') === 'xtts-compatible') {
                return 'xtts';
            }
        }
        $info = self::getJson($base . '/provider_info');
        if (is_array($info) && (in_array(strtolower((string)($info['provider'] ?? '')), ['pockettts', 'pocket_tts', 'pocket-tts'], true)
            || ($info['api_family'] ?? '') === 'xtts-compatible')) {
            return 'xtts';
        }
        $openapi = self::getJson($base . '/openapi.json');
        $paths = is_array($openapi) && is_array($openapi['paths'] ?? null) ? array_keys($openapi['paths']) : [];
        if (in_array('/tts_to_audio', $paths, true) || in_array('/tts_to_audio/', $paths, true) || in_array('/tts_to_audio_form', $paths, true)) {
            return 'xtts';
        }
        if (in_array('/v1/audio/speech', $paths, true)) {
            return 'audio_cpp';
        }
        return '';
    }

    // A Trade of Immersion: the XTTS-style service (PocketTTS) base URL for the configured tts, or
    // null when none answers or the provider is not XTTS-style. Used to upload voice samples.
    public static function xttsBase(array $tts): ?string
    {
        $provider = self::provider($tts);
        $url = (string)($tts['url'] ?? '') !== '' ? (string)$tts['url'] : self::DEFAULT_URL;
        if ($provider === 'openai') {
            return null;
        }
        if ($provider === 'xtts' || ($provider === 'pockettts' && !self::isAudioCppUrl($url))) {
            return self::baseUrl($url);
        }
        $service = self::findService($url);
        return $service !== null && $service['mode'] === 'xtts' ? $service['base'] : null;
    }

    // The speakers PocketTTS lists (fresh after an upload), or null when it does not answer.
    public static function speakerList(array $tts, bool $fresh = false): ?array
    {
        $base = self::xttsBase($tts);
        return $base === null ? null : self::speakers($base, $fresh);
    }

    public static function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return (string)preg_replace('#/(?:v1/audio/speech|tts_to_audio|tts_to_audio_form)$#', '', $url);
    }

    private static function audioCppUrl(string $base): string
    {
        return self::baseUrl($base) . '/v1/audio/speech';
    }

    private static function isAudioCppUrl(string $url): bool
    {
        return (int)(parse_url($url, PHP_URL_PORT) ?? 0) === 8086 || str_ends_with(rtrim($url, '/'), '/v1/audio/speech');
    }

    private static function hostPort(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) ? ($parts['host'] ?? '?') . (isset($parts['port']) ? ':' . $parts['port'] : '') : 'an invalid URL';
    }

    // A small JSON GET with short timeouts; null on any problem. Logs nothing.
    private static function getJson(string $url, int $timeoutMs = 1500): mixed
    {
        $received = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received): int {
                $received .= $chunk;
                return strlen($received) > 1000000 ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode($received, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    // --- a small cache, so each speech line does not probe the service again ----------------------

    private static function cacheFile(string $key): string
    {
        return sys_get_temp_dir() . '/toi-tts-' . substr(hash('sha256', __DIR__ . "\n" . $key), 0, 32) . '.json';
    }

    private static function cacheGet(string $key): mixed
    {
        $file = self::cacheFile($key);
        $raw = is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS ? @file_get_contents($file) : false;
        return is_string($raw) ? json_decode($raw, true) : null;
    }

    private static function cachePut(string $key, mixed $value): void
    {
        $file = self::cacheFile($key);
        $temp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, json_encode($value)) !== false) {
            @chmod($temp, 0600);
            @rename($temp, $file);
        }
    }

    private static function forget(string $url, ?string $base = null): void
    {
        @unlink(self::cacheFile('service ' . $url));
        foreach (array_unique([self::baseUrl($url), $base ?? self::baseUrl($url)]) as $one) {
            @unlink(self::cacheFile('speakers ' . $one));
        }
    }
}
