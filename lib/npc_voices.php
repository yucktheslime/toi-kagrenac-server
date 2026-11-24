<?php
// A Trade of Immersion: per-NPC voice profiles (ex_npc_voices) and uploaded voice samples
// (ex_voice_samples, files in config/voices/). Used by speak.php through SpeechService, by the
// dashboard's NPC voices page and by npc_voice.php (the game's /npc voice command).
// This file only defines functions and constants, so requesting it over HTTP prints nothing.

require_once __DIR__ . '/app.php';

const NPC_VOICE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/D';
const NPC_VOICE_SAMPLE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D';
const NPC_VOICE_LANGUAGES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'tr', 'ru', 'nl', 'cs', 'ar', 'zh-cn', 'ja', 'hu', 'ko', 'hi'];
const NPC_VOICE_AUDIO_EXTENSIONS = ['wav', 'mp3', 'ogg', 'flac'];
const NPC_VOICE_MAX_SAMPLE_BYTES = 15000000;   // one sample
const NPC_VOICE_MAX_ZIP_BYTES = 100000000;     // one uploaded zip
const NPC_VOICE_MAX_UNPACKED_BYTES = 200000000; // everything in that zip together
const NPC_VOICE_MAX_ZIP_FILES = 200;

// 'companion' (c-...), 'villager' (v-...) or 'npc'.
function npc_voice_kind(string $npcId): string
{
    return str_starts_with($npcId, 'c-') ? 'companion' : (str_starts_with($npcId, 'v-') ? 'villager' : 'npc');
}

function npc_voice_row(array $row): array
{
    $row['pitch'] = $row['pitch'] !== null ? (float)$row['pitch'] : null;
    return $row;
}

// The NPC's row, or null when it has none, or false when the lookup failed.
function npc_voice_get(\PgSql\Connection $db, string $npcId): array|null|false
{
    $result = @pg_query_params($db, 'SELECT npc_id, npc_name, kind, gender, voice, language, pitch, source, last_seen, updated_at
        FROM ex_npc_voices WHERE npc_id = $1', [$npcId]);
    if ($result === false) {
        app_log('npc voice lookup failed: ' . pg_last_error($db));
        return false;
    }
    $row = pg_fetch_assoc($result);
    return $row === false ? null : npc_voice_row($row);
}

// Notes that the NPC spoke (name, gender, last seen) so it shows on the dashboard, and returns
// its row. Never changes a voice. Null on any problem.
function npc_voice_touch(\PgSql\Connection $db, string $npcId, string $name = '', string $gender = ''): ?array
{
    $name = mb_substr(clean_line($name), 0, 64);
    $gender = in_array($gender, ['female', 'male', 'neutral'], true) ? $gender : '';
    $result = @pg_query_params($db,
        "INSERT INTO ex_npc_voices (npc_id, npc_name, kind, gender, last_seen) VALUES ($1, $2, $3, $4, now())
         ON CONFLICT (npc_id) DO UPDATE SET last_seen = now(),
             npc_name = CASE WHEN $2 <> '' THEN $2 ELSE ex_npc_voices.npc_name END,
             gender = CASE WHEN $4 <> '' THEN $4 ELSE ex_npc_voices.gender END
         RETURNING npc_id, npc_name, kind, gender, voice, language, pitch, source, last_seen, updated_at",
        [$npcId, $name, npc_voice_kind($npcId), $gender]);
    if ($result === false) {
        app_log('npc voice touch failed: ' . pg_last_error($db));
        return null;
    }
    $row = pg_fetch_assoc($result);
    return $row === false ? null : npc_voice_row($row);
}

// The tts settings with the NPC's voice (unless $withVoice is false), language and pitch.
function npc_voice_tts(array $tts, ?array $row, bool $withVoice = true): array
{
    if (!is_array($row)) {
        return $tts;
    }
    if ($withVoice && is_string($row['voice'] ?? null) && $row['voice'] !== '') {
        $tts['voice'] = $row['voice'];
    }
    if (is_string($row['language'] ?? null) && $row['language'] !== '') {
        $tts['language'] = $row['language'];
    }
    return $tts;
}

// Picks the speaker for an NPC from $pool: one no other NPC uses yet when there is one, chosen
// by a hash of the NPC id so the same world gives the same answer; else a hashed pick among all.
function npc_voice_pick(string $npcId, array $pool, array $used): ?string
{
    $pool = array_values(array_unique(array_filter($pool, fn($v) => is_string($v) && preg_match(NPC_VOICE_PATTERN, $v))));
    if ($pool === []) {
        return null;
    }
    sort($pool, SORT_STRING | SORT_FLAG_CASE);
    $usedLower = array_map('strtolower', $used);
    $free = array_values(array_filter($pool, fn($v) => !in_array(strtolower($v), $usedLower, true)));
    $from = $free !== [] ? $free : $pool;
    return $from[crc32($npcId) % count($from)];
}

// Stores an automatic voice for an NPC that has none and returns it. Never replaces a voice that
// is already set (a concurrent first line keeps whichever pick landed first).
function npc_voice_auto_assign(\PgSql\Connection $db, string $npcId, array $pool): ?string
{
    $used = @pg_query_params($db, 'SELECT DISTINCT voice FROM ex_npc_voices WHERE voice IS NOT NULL AND npc_id <> $1', [$npcId]);
    $voice = npc_voice_pick($npcId, $pool, $used === false ? [] : array_column(pg_fetch_all($used), 'voice'));
    if ($voice === null) {
        return null;
    }
    $result = @pg_query_params($db,
        "INSERT INTO ex_npc_voices (npc_id, kind, voice, source, last_seen) VALUES ($1, $2, $3, 'auto', now())
         ON CONFLICT (npc_id) DO UPDATE SET voice = COALESCE(ex_npc_voices.voice, $3), updated_at = now()
         RETURNING voice", [$npcId, npc_voice_kind($npcId), $voice]);
    if ($result === false) {
        app_log('npc voice auto pick failed: ' . pg_last_error($db));
        return $voice;
    }
    $stored = pg_fetch_result($result, 0, 0);
    return is_string($stored) && $stored !== '' ? $stored : $voice;
}

// Validates one change. Returns [voice|null, language|null, pitch|null] or an error message.
// An empty voice clears it (the NPC gets an automatic one again).
function npc_voice_validate(mixed $voice, mixed $language = '', mixed $pitch = ''): array|string
{
    $voice = is_string($voice) ? trim($voice) : '';
    if (str_ends_with(strtolower($voice), '.wav')) {
        $voice = substr($voice, 0, -4);
    }
    if ($voice !== '' && !preg_match(NPC_VOICE_PATTERN, $voice)) {
        return 'A voice name is 1-64 letters, numbers, dots, dashes or underscores.';
    }
    $language = is_string($language) ? strtolower(trim($language)) : '';
    if ($language !== '' && !in_array($language, NPC_VOICE_LANGUAGES, true)) {
        return 'Choose a language from the list, or leave it blank.';
    }
    $pitchValue = null;
    if (is_string($pitch) && trim($pitch) !== '' || is_int($pitch) || is_float($pitch)) {
        if (!is_numeric($pitch) || (float)$pitch < 0.5 || (float)$pitch > 2.0) {
            return 'Pitch must be a number from 0.5 to 2.0 (1.0 is normal).';
        }
        $pitchValue = round((float)$pitch, 2);
    }
    return [$voice === '' ? null : $voice, $language === '' ? null : $language, $pitchValue];
}

// Sets an NPC's voice. $source is 'operator' or 'game'. A null voice means automatic: an automatic
// voice already picked is kept; a chosen one is cleared so the next line picks an automatic one.
function npc_voice_set(\PgSql\Connection $db, string $npcId, ?string $voice, ?string $language, ?float $pitch,
    string $source, string $name = ''): bool
{
    $name = mb_substr(clean_line($name), 0, 64);
    $result = @pg_query_params($db,
        "INSERT INTO ex_npc_voices (npc_id, npc_name, kind, voice, language, pitch, source) VALUES ($1, $2, $3, $4, $5, $6, $7)
         ON CONFLICT (npc_id) DO UPDATE SET language = $5, pitch = $6, source = $7, updated_at = now(),
             voice = CASE WHEN $4::text IS NULL AND ex_npc_voices.source = 'auto' THEN ex_npc_voices.voice ELSE $4::text END,
             npc_name = CASE WHEN $2 <> '' THEN $2 ELSE ex_npc_voices.npc_name END",
        [$npcId, $name, npc_voice_kind($npcId), $voice, $language, $pitch, $voice === null ? 'auto' : $source]);
    if ($result === false) {
        app_log('npc voice save failed: ' . pg_last_error($db));
    }
    return $result !== false;
}

// NPCs by exact id, or by name (case-insensitive). Name lookups may match several.
function npc_voice_find(\PgSql\Connection $db, string $idOrName): array
{
    $result = @pg_query_params($db,
        "SELECT npc_id, npc_name FROM (
             SELECT COALESCE(v.npc_id, b.npc_id) AS npc_id, COALESCE(NULLIF(v.npc_name, ''), b.name, '') AS npc_name, v.last_seen
             FROM ex_npc_voices v FULL OUTER JOIN ex_npc_bios b ON b.npc_id = v.npc_id) n
         WHERE npc_id = $1 OR npc_id IN ('c-' || $1, 'v-' || $1) OR lower(npc_name) = lower($1)
         ORDER BY last_seen DESC NULLS LAST LIMIT 10", [$idOrName]);
    return $result === false ? [] : (pg_fetch_all($result) ?: []);
}

// Every NPC the server knows (voice rows and bios), newest first.
function npc_voice_list(\PgSql\Connection $db, int $limit = 500): array
{
    $result = @pg_query_params($db,
        "SELECT COALESCE(v.npc_id, b.npc_id) AS npc_id,
                COALESCE(NULLIF(v.npc_name, ''), b.name, '') AS npc_name,
                COALESCE(v.kind, CASE WHEN b.npc_id LIKE 'c-%' THEN 'companion' WHEN b.npc_id LIKE 'v-%' THEN 'villager' ELSE 'npc' END) AS kind,
                COALESCE(v.gender, '') AS gender, v.voice, v.language, v.pitch, COALESCE(v.source, 'auto') AS source, v.last_seen
         FROM ex_npc_voices v FULL OUTER JOIN ex_npc_bios b ON b.npc_id = v.npc_id
         ORDER BY v.last_seen DESC NULLS LAST, 2 LIMIT $1", [$limit]);
    return $result === false ? [] : array_map('npc_voice_row', pg_fetch_all($result) ?: []);
}

// --- voice samples ------------------------------------------------------------------------------

function npc_voice_samples_dir(): string
{
    $config = getenv('EXAMPLE_AI_CONFIG') ?: (__DIR__ . '/../config/config.php');
    return dirname($config) . '/voices';
}

function npc_voice_ensure_samples_dir(): string|false
{
    $dir = npc_voice_samples_dir();
    if (is_link($dir)) {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0770) && !is_dir($dir)) {
        return false;
    }
    return $dir;
}

// A voice name from a file name: 'Steve Smith.wav' -> 'Steve_Smith'. Null when nothing usable is left.
function npc_voice_name_from_file(string $filename): ?string
{
    $base = basename(str_replace('\\', '/', $filename));
    $dot = strrpos($base, '.');
    $stem = $dot === false ? $base : substr($base, 0, $dot);
    $stem = (string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $stem);
    $stem = trim($stem, '._-');
    $stem = substr($stem, 0, 64);
    return $stem !== '' && preg_match(NPC_VOICE_SAMPLE_PATTERN, $stem) ? $stem : null;
}

function npc_voice_extension(string $filename): string
{
    $dot = strrpos($filename, '.');
    return $dot === false ? '' : strtolower(substr($filename, $dot + 1));
}

// The audio format from the first bytes: 'wav', 'mp3', 'ogg', 'flac' or ''.
function npc_voice_sniff(string $bytes): string
{
    if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
        return 'wav';
    }
    if (str_starts_with($bytes, 'OggS')) {
        return 'ogg';
    }
    if (str_starts_with($bytes, 'fLaC')) {
        return 'flac';
    }
    if (str_starts_with($bytes, 'ID3') || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0)) {
        return 'mp3';
    }
    return '';
}

// PocketTTS clones a voice from a WAV. Other formats are converted with ffmpeg when it is
// installed. Returns [wav bytes, null] or [null, reason].
function npc_voice_to_wav(string $bytes, string $format): array
{
    if ($format === 'wav') {
        return [$bytes, null];
    }
    $ffmpeg = null;
    foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $ffmpeg = $candidate;
            break;
        }
    }
    if ($ffmpeg === null || !function_exists('proc_open')) {
        return [null, "PocketTTS needs WAV; this is $format and ffmpeg is not available (sudo apt install ffmpeg), so convert it to WAV first"];
    }
    $in = tempnam(sys_get_temp_dir(), 'toi-voice-in');
    $out = $in . '.wav';
    try {
        if ($in === false || file_put_contents($in, $bytes) === false) {
            return [null, 'could not write a temporary file for conversion'];
        }
        $cmd = [$ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-t', '60', '-i', $in,
            '-ac', '1', '-ar', '24000', '-c:a', 'pcm_s16le', $out];
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $code = is_resource($proc) ? proc_close($proc) : 1;
        $wav = $code === 0 && is_file($out) ? (string)file_get_contents($out) : '';
        if ($wav === '' || npc_voice_sniff($wav) !== 'wav') {
            return [null, "ffmpeg could not convert this $format file"];
        }
        return [$wav, null];
    } finally {
        if (is_string($in)) {
            @unlink($in);
        }
        @unlink($out);
    }
}

// POST <base>/upload_sample (multipart, field wavFile plus speaker_name), like CHIM's voice upload.
// Returns [ok, detail].
function npc_voice_forward(string $base, string $name, string $wavPath): array
{
    $curl = curl_init(rtrim($base, '/') . '/upload_sample');
    $received = '';
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['wavFile' => new CURLFile($wavPath, 'audio/wav', $name . '.wav'), 'speaker_name' => $name],
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received): int {
            $received .= $chunk;
            return strlen($received) > 100000 ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($ok === false || $status === 0) {
        return [false, 'PocketTTS did not answer on ' . $base];
    }
    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($received, true);
        $why = is_array($decoded) ? (string)($decoded['error'] ?? $decoded['detail'] ?? '') : '';
        return [false, "PocketTTS upload_sample answered HTTP $status" . ($why !== '' ? ': ' . mb_substr(clean_line(is_string($why) ? $why : json_encode($why)), 0, 120) : '')];
    }
    return [true, 'added to PocketTTS'];
}

// Stores one sample and forwards it to PocketTTS. $tts is the config's tts section; $base the
// PocketTTS URL (null: not found, the sample is only stored). Returns a result row:
// ['file', 'name', 'ok', 'forwarded', 'detail'].
function npc_voice_add_sample(?\PgSql\Connection $db, ?string $base, string $filename, string $bytes, ?string $name = null): array
{
    $result = ['file' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200), 'name' => '', 'ok' => false, 'forwarded' => false, 'detail' => ''];
    $ext = npc_voice_extension($result['file']);
    if (!in_array($ext, NPC_VOICE_AUDIO_EXTENSIONS, true)) {
        $result['detail'] = 'skipped: only .wav, .mp3, .ogg and .flac files are voice samples';
        return $result;
    }
    $name ??= npc_voice_name_from_file($result['file']);
    if ($name === null || !preg_match(NPC_VOICE_SAMPLE_PATTERN, $name)) {
        $result['detail'] = 'the file name has no usable voice name (use letters, numbers, dots, dashes or underscores)';
        return $result;
    }
    $result['name'] = $name;
    if (strlen($bytes) === 0 || strlen($bytes) > NPC_VOICE_MAX_SAMPLE_BYTES) {
        $result['detail'] = 'empty or larger than ' . (NPC_VOICE_MAX_SAMPLE_BYTES / 1000000) . ' MB';
        return $result;
    }
    $format = npc_voice_sniff($bytes);
    if ($format === '') {
        $result['detail'] = 'not a WAV, MP3, OGG or FLAC file';
        return $result;
    }
    [$wav, $why] = npc_voice_to_wav($bytes, $format);
    if ($wav === null) {
        $result['detail'] = $why;
        return $result;
    }
    $dir = npc_voice_ensure_samples_dir();
    if ($dir === false) {
        $result['detail'] = 'the voice sample folder (config/voices) could not be created';
        return $result;
    }
    $path = $dir . '/' . $name . '.wav';
    $temp = $dir . '/.upload-' . bin2hex(random_bytes(6));
    if (@file_put_contents($temp, $wav) === false || !@rename($temp, $path)) {
        @unlink($temp);
        $result['detail'] = 'the sample could not be saved';
        return $result;
    }
    @chmod($path, 0660);
    $result['ok'] = true;
    if ($base === null) {
        $result['detail'] = 'saved; no PocketTTS found to add it to (start PocketTTS, then press Send again)';
    } else {
        [$result['forwarded'], $result['detail']] = npc_voice_forward($base, $name, $path);
        if (!$result['forwarded']) {
            $result['detail'] = 'saved; ' . $result['detail'];
        }
    }
    if ($format !== 'wav') {
        $result['detail'] .= " (converted from $format)";
    }
    if ($db !== null) {
        @pg_query_params($db,
            "INSERT INTO ex_voice_samples (name, filename, bytes, sha256, forwarded, detail) VALUES ($1, $2, $3, $4, $5, $6)
             ON CONFLICT (name) DO UPDATE SET filename = $2, bytes = $3, sha256 = $4, forwarded = $5, detail = $6, uploaded_at = now()",
            [$name, $result['file'], strlen($wav), hash('sha256', $wav), $result['forwarded'] ? 't' : 'f', mb_substr($result['detail'], 0, 300)]);
    }
    return $result;
}

// Sends a stored sample to PocketTTS again (for example after PocketTTS was reinstalled).
function npc_voice_resend(?\PgSql\Connection $db, ?string $base, string $name): array
{
    $path = npc_voice_samples_dir() . '/' . $name . '.wav';
    if (!preg_match(NPC_VOICE_SAMPLE_PATTERN, $name) || !is_file($path) || is_link($path)) {
        return [false, 'that sample is not stored'];
    }
    if ($base === null) {
        return [false, 'no PocketTTS found'];
    }
    $sent = npc_voice_forward($base, $name, $path);
    if ($db !== null) {
        @pg_query_params($db, 'UPDATE ex_voice_samples SET forwarded = $2, detail = $3 WHERE name = $1',
            [$name, $sent[0] ? 't' : 'f', mb_substr($sent[1], 0, 300)]);
    }
    return $sent;
}

function npc_voice_samples(\PgSql\Connection $db): array
{
    $result = @pg_query($db, 'SELECT name, filename, bytes, forwarded, detail, uploaded_at FROM ex_voice_samples ORDER BY lower(name)');
    return $result === false ? [] : (pg_fetch_all($result) ?: []);
}

// Imports every audio file in a zip. Unsafe paths (absolute, '..', drive letters), links, special
// files, encrypted or oversized entries reject the whole zip (lib/plugins.php's reader); other
// files are listed as skipped. With $assign, a sample whose name matches an NPC's name or id
// (Steve.wav -> the NPC named Steve) becomes that NPC's voice.
// Returns ['error' => message] or ['results' => [rows from npc_voice_add_sample + 'assigned']].
function npc_voice_import_zip(?\PgSql\Connection $db, ?string $base, string $zipPath, bool $assign): array
{
    require_once __DIR__ . '/plugins.php';
    $members = plugins_zip_list($zipPath, NPC_VOICE_MAX_ZIP_BYTES, NPC_VOICE_MAX_UNPACKED_BYTES, NPC_VOICE_MAX_ZIP_FILES);
    if (is_string($members)) {
        return ['error' => $members];
    }
    $results = [];
    $seen = [];
    foreach ($members as $member) {
        $file = $member['name'];
        $leaf = basename($file);
        if (str_contains($file, '__MACOSX/') || str_starts_with($leaf, '.')) {
            continue;
        }
        if (!in_array(npc_voice_extension($leaf), NPC_VOICE_AUDIO_EXTENSIONS, true)) {
            $results[] = ['file' => $file, 'name' => '', 'ok' => false, 'forwarded' => false, 'assigned' => '',
                'detail' => 'skipped: only .wav, .mp3, .ogg and .flac files are voice samples'];
            continue;
        }
        if ($member['raw'] > NPC_VOICE_MAX_SAMPLE_BYTES) {
            $results[] = ['file' => $file, 'name' => '', 'ok' => false, 'forwarded' => false, 'assigned' => '',
                'detail' => 'larger than ' . (NPC_VOICE_MAX_SAMPLE_BYTES / 1000000) . ' MB'];
            continue;
        }
        $name = npc_voice_name_from_file($leaf);
        if ($name !== null && isset($seen[strtolower($name)])) {
            $results[] = ['file' => $file, 'name' => $name, 'ok' => false, 'forwarded' => false, 'assigned' => '',
                'detail' => 'skipped: another file in this zip has the same voice name'];
            continue;
        }
        $data = plugins_zip_extract_member($zipPath, $member);
        if ($data === false) {
            $results[] = ['file' => $file, 'name' => $name ?? '', 'ok' => false, 'forwarded' => false, 'assigned' => '',
                'detail' => 'could not be extracted (damaged entry)'];
            continue;
        }
        $row = npc_voice_add_sample($db, $base, $leaf, $data, $name);
        $row['file'] = $file;
        $row['assigned'] = '';
        if ($row['ok']) {
            $seen[strtolower($row['name'])] = true;
            if ($assign && $db !== null) {
                $row['assigned'] = npc_voice_assign_by_name($db, $row['name']);
            }
        }
        $results[] = $row;
    }
    if ($results === []) {
        return ['error' => 'The zip has no voice samples.'];
    }
    return ['results' => $results];
}

// Gives the sample $name to the NPC(s) whose name or id matches it (underscores count as spaces).
// Returns the names it was given to, comma separated, or ''.
function npc_voice_assign_by_name(\PgSql\Connection $db, string $name): string
{
    $lookup = [$name, str_replace('_', ' ', $name)];
    $given = [];
    foreach (array_unique($lookup) as $key) {
        foreach (npc_voice_find($db, $key) as $npc) {
            if (isset($given[$npc['npc_id']])) {
                continue;
            }
            $current = npc_voice_get($db, $npc['npc_id']);
            if (npc_voice_set($db, $npc['npc_id'], $name, is_array($current) ? $current['language'] : null,
                is_array($current) ? $current['pitch'] : null, 'operator')) {
                $given[$npc['npc_id']] = $npc['npc_name'] !== '' ? $npc['npc_name'] : $npc['npc_id'];
            }
        }
    }
    return implode(', ', $given);
}
