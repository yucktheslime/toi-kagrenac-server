<?php
// The actions this server knows: when the model may ask for each one, who it acts on, and
// whether it takes one short argument. This file only declares a class, so requesting it over
// HTTP prints nothing. The prompt hints (actions_prompt()), the parser (extract_actions()) and
// 'project.ps1 configure' (scripts/configure_server.php) all read this one list.
//
// A Trade of Immersion (Minecraft) extension, see ports/minecraft/README.md and PROTOCOL.md
// "Action arguments": a known action may carry one argument written by the model as
// [ACTION:name: argument]. The argument is plain text (1-80 characters of letters, digits,
// spaces and . , : ' # ~ - _), returned as args.value. It is only a hint such as an item name;
// the game resolves it against what it can actually see and refuses anything else. It is never
// a command, path, URL or SQL.
//
// Knowing an action never allows it: only config allowed_actions does, narrowed by the game's
// offered_actions. An allowed name that is not listed here (a custom action the operator added
// on purpose) is still offered as a bare tag and returned without args, exactly as before.

final class ActionCatalog
{
    // Actions whose actor is the NPC the request named: they get it as args.target_id.
    public const TARGET_REQUEST_NPC = 'request_npc';

    public const ARG_NONE = 'none';
    public const ARG_OPTIONAL = 'optional';
    public const ARG_REQUIRED = 'required';

    // Characters allowed in an argument. Anything else makes the tag rejected.
    public const ARG_PATTERN = "/^[\\p{L}\\p{N} _.,:'#~-]{1,80}$/uD";

    // The server allowlist when config sets none.
    public const DEFAULT_ALLOWED = ['follow_player'];

    // name => ['when' => prompt hint, 'target' => target rule or null, 'arg' => none|optional|required,
    //          'arg_hint' => what the argument is]. The order is the order of the hints in the prompt.
    private const KNOWN = [
        'follow_player' => ['when' => 'only when you agree to follow the player',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_NONE],
        'hold_position' => ['when' => 'only when you agree to stay where you are',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_NONE],
        'attack_hostile' => ['when' => 'only when you agree to attack; the game alone picks the nearest hostile it knows, you cannot name a target',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_NONE],
        'attack_target' => ['when' => 'only when you agree to attack one specific creature you can see in the game context',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the creature type or name, e.g. zombie'],
        'go_to' => ['when' => 'only when you agree to walk somewhere',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED,
            'arg_hint' => 'home, owner, a name from the game context, or coordinates x y z'],
        'mount' => ['when' => 'only when you agree to get into a boat or onto a horse or other mount nearby',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_OPTIONAL, 'arg_hint' => 'boat, horse or a name'],
        'dismount' => ['when' => 'only when you agree to get out of a boat or off a mount',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_NONE],
        'equip' => ['when' => 'only when you agree to hold or wear an item from your own inventory',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the item name'],
        'use_item' => ['when' => 'only when you agree to use or eat an item from your inventory',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the item name, e.g. bread'],
        'give_item' => ['when' => 'only when you agree to hand one of your items to the player',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the item name'],
        'pick_up' => ['when' => 'only when you agree to collect items lying on the ground nearby',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_OPTIONAL, 'arg_hint' => 'the item name'],
        'inspect_item' => ['when' => 'only when you want to look closely at an item to learn what it does',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the item name'],
        'talk_to_villager' => ['when' => 'only when you want to walk up to a villager from the game context and say your reply to them',
            'target' => self::TARGET_REQUEST_NPC, 'arg' => self::ARG_REQUIRED, 'arg_hint' => 'the villager name'],
    ];

    // Actions registered by enabled plugins for this request. They never replace a built-in name.
    private static array $extra = [];

    public static function builtInNames(): array
    {
        return array_keys(self::KNOWN);
    }

    // $spec: when, arg (none|optional|required), optional arg_hint. Returns false if the name is
    // built-in, invalid, or the spec is unusable.
    public static function register(string $name, array $spec): bool
    {
        if (isset(self::KNOWN[$name]) || isset(self::$extra[$name]) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $name)) {
            return false;
        }
        $arg = $spec['arg'] ?? self::ARG_NONE;
        if (!in_array($arg, [self::ARG_NONE, self::ARG_OPTIONAL, self::ARG_REQUIRED], true)) {
            return false;
        }
        $when = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($spec['when'] ?? '')) ?? '');
        if ($when === '' || mb_strlen($when) > 200) {
            return false;
        }
        $hint = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($spec['arg_hint'] ?? 'a short name')) ?? '');
        if ($hint === '') {
            $hint = 'a short name';
        }
        self::$extra[$name] = [
            'when' => $when,
            'target' => self::TARGET_REQUEST_NPC,
            'arg' => $arg,
            'arg_hint' => mb_substr($hint, 0, 80),
        ];
        return true;
    }

    public static function resetExtras(): void
    {
        self::$extra = [];
    }

    private static function known(): array
    {
        return self::KNOWN + self::$extra;
    }

    // Every known name, in catalog order (built-ins first, then registered plugin actions).
    public static function names(): array
    {
        return array_keys(self::known());
    }

    // The known names whose target is the request's npc.id, in catalog order.
    public static function requestNpcTargetNames(): array
    {
        return array_keys(array_filter(self::known(), fn(array $action) => $action['target'] === self::TARGET_REQUEST_NPC));
    }

    // none, optional or required. Unknown (custom) names take no argument.
    public static function argRule(string $name): string
    {
        return self::known()[$name]['arg'] ?? self::ARG_NONE;
    }

    // The prompt sentences for the known actions among $tags ("[ACTION:name]" strings that the
    // prompt offers), each starting with a space, in catalog order. Unknown names get no hint.
    public static function promptHints(array $tags): string
    {
        $hints = '';
        foreach (self::known() as $name => $action) {
            if (in_array("[ACTION:$name]", $tags, true)) {
                $form = match ($action['arg']) {
                    self::ARG_REQUIRED => "[ACTION:$name: {$action['arg_hint']}]",
                    self::ARG_OPTIONAL => "[ACTION:$name] or [ACTION:$name: {$action['arg_hint']}]",
                    default => "[ACTION:$name]",
                };
                $hints .= " Use $form {$action['when']}.";
            }
        }
        return $hints;
    }

    // Cleans a model-written argument. Returns null when it is not acceptable.
    public static function cleanArg(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '' || !preg_match(self::ARG_PATTERN, $value)) {
            return null;
        }
        return $value;
    }

    // One allowed action as the protocol returns it. The target only ever comes from the
    // request ($targetId, its npc.id), never from model text. $value is the cleaned argument
    // (or null); extract_actions() has already checked it against argRule().
    public static function request(string $name, ?string $targetId, ?string $value = null): array
    {
        $action = ['name' => $name];
        $args = [];
        if ((self::known()[$name]['target'] ?? null) === self::TARGET_REQUEST_NPC && $targetId !== null) {
            $args['target_id'] = $targetId;
        }
        if ($value !== null && self::argRule($name) !== self::ARG_NONE) {
            $args['value'] = $value;
        }
        if ($args) {
            $action['args'] = $args;
        }
        return $action;
    }
}
