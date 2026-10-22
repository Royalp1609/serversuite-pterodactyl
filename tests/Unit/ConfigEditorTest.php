<?php

namespace ServerSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ServerSuite\Support\Config\ConfigEditException;
use ServerSuite\Support\Config\ConfigEditor;

/**
 * #8 typed config editor: every format round-trips with only the edited
 * values changed (comments, order and layout kept), and anything the form
 * can't do safely is refused, never written half-right.
 */
class ConfigEditorTest extends TestCase
{
    /** @return array<string, array<string, mixed>> fields by label */
    private function fields(string $raw, string $file): array
    {
        $o = ConfigEditor::open($raw, $file);
        $this->assertNull($o['reason'], (string) $o['reason']);

        return array_column($o['fields'], null, 'label');
    }

    /** @param array<string, mixed> $byLabel */
    private function save(string $raw, string $file, array $byLabel): string
    {
        $f = $this->fields($raw, $file);
        $values = [];
        foreach ($byLabel as $label => $v) {
            $this->assertArrayHasKey($label, $f, "no field {$label}");
            $values[$f[$label]['id']] = $v;
        }

        return ConfigEditor::save($raw, $file, $values)['content'];
    }

    public function test_properties_round_trip(): void
    {
        $raw = "#Minecraft server properties\r\n#Mon Oct 20 12:00:00 BST 2026\r\nmotd=\\u00A7aA Minecraft Server\r\npvp=true\r\nmax-players=20\r\ndifficulty=easy\r\nlevel-seed=\r\nserver-port=25565\r\n";
        $f = $this->fields($raw, 'server.properties');

        $this->assertSame('toggle', $f['pvp']['control']);
        $this->assertSame('number', $f['max-players']['control']);
        $this->assertSame(['peaceful', 'easy', 'normal', 'hard'], $f['difficulty']['choices']);
        $this->assertSame([0, 65535], [$f['server-port']['min'], $f['server-port']['max']]);
        $this->assertSame("\u{00A7}aA Minecraft Server", $f['motd']['value']);

        $out = $this->save($raw, 'server.properties', [
            'pvp' => false, 'max-players' => '50', 'difficulty' => 'hard', 'motd' => 'Hé there', 'level-seed' => '12345',
        ]);

        $this->assertSame("#Minecraft server properties\r\n#Mon Oct 20 12:00:00 BST 2026\r\nmotd=H\\u00e9 there\r\npvp=false\r\nmax-players=50\r\ndifficulty=hard\r\nlevel-seed=12345\r\nserver-port=25565\r\n", $out);
    }

    public function test_unchanged_values_write_nothing(): void
    {
        $raw = "a=1\nb=true\n";
        $f = $this->fields($raw, 'x.properties');
        $r = ConfigEditor::save($raw, 'x.properties', [$f['a']['id'] => '1', $f['b']['id'] => true]);

        $this->assertSame($raw, $r['content']);
        $this->assertSame([], $r['changed']);
    }

    public function test_yaml_keeps_comments_and_layout(): void
    {
        $raw = <<<'YAML'
# EssentialsX-style config
settings:
  # Shown on join
  motd: 'Welcome, friend'   # trailing comment
  max-homes: 3
  teleport:
    cooldown: 2.5
    safe: true
  worlds:
    - world
    - world_nether
  ranks: [admin, mod]
  rules: |
    Be nice.
    No griefing.
  empty:
other: "x"

YAML;
        $f = $this->fields($raw, 'config.yml');
        $this->assertSame('Shown on join', $f['settings.motd']['help']);
        $this->assertSame('list', $f['settings.worlds']['control']);
        $this->assertArrayNotHasKey('settings.rules', $f, 'block scalars stay raw');
        $this->assertArrayNotHasKey('settings.empty', $f, 'nulls stay raw');

        $out = $this->save($raw, 'config.yml', [
            'settings.motd' => "It's a #1 server",
            'settings.max-homes' => '5',
            'settings.teleport.cooldown' => '3',
            'settings.teleport.safe' => false,
            'settings.worlds' => "world\nworld_the_end\nyes",
            'settings.ranks' => "admin\nmod\nhelper",
            'other' => 'y "quoted"',
        ]);

        $this->assertSame(<<<'YAML'
# EssentialsX-style config
settings:
  # Shown on join
  motd: 'It''s a #1 server'   # trailing comment
  max-homes: 5
  teleport:
    cooldown: 3.0
    safe: false
  worlds:
    - world
    - world_the_end
    - yes
  ranks: [admin, mod, helper]
  rules: |
    Be nice.
    No griefing.
  empty:
other: "y \"quoted\""

YAML, $out);
    }

    public function test_yaml_plain_strings_that_would_change_meaning_get_quoted(): void
    {
        $raw = "name: Steve\n";
        foreach (['true', '123', 'a: b', 'x #y', '', ' padded', 'null'] as $v) {
            $out = $this->save($raw, 'c.yml', ['name' => $v]);
            $this->assertSame($v, \Symfony\Component\Yaml\Yaml::parse($out)['name'], $out);
        }
    }

    public function test_yaml_the_form_cannot_read_safely_opens_as_text(): void
    {
        foreach ([
            'anchors'   => "base: &b\n  x: 1\nother: *b\n",
            'two docs'  => "a: 1\n---\nb: 2\n",
            'tabs'      => "a:\n\tb: 1\n",
            'list root' => "- a\n- b\n",
            'invalid'   => "a: [1, 2\n",
        ] as $why => $raw) {
            $o = ConfigEditor::open($raw, 'x.yml');
            $this->assertSame([], $o['fields'], $why);
            $this->assertNotNull($o['reason'], $why);
        }
    }

    public function test_yaml_multi_line_values_and_colour_codes_dont_block_the_form(): void
    {
        $raw = "kick:\n  denied: <red>Sorry, no permission.\n    Ask an admin.\n  quoted: 'over\n    two lines'\n  flying: <lang:kick.flying>\nprefix: '&e[&aTimber&e] '\nlast: true\n";
        $f = $this->fields($raw, 'paper-global.yml');
        $this->assertArrayNotHasKey('kick.denied', $f);
        $this->assertArrayNotHasKey('kick.quoted', $f);
        $this->assertSame('&e[&aTimber&e] ', $f['prefix']['value']);

        $out = $this->save($raw, 'paper-global.yml', ['kick.flying' => '<lang:kick.nope>', 'last' => false]);
        $this->assertSame(str_replace(['<lang:kick.flying>', 'last: true'], ['<lang:kick.nope>', 'last: false'], $raw), $out);
    }

    public function test_toml_with_neoforge_hints(): void
    {
        $raw = <<<'TOML'
# Server settings
[general]
	#Maximum players per claim
	#Range: 1 ~ 64
	maxPlayers = 8
	#Allowed Values: EASY, NORMAL, HARD
	mode = "NORMAL"
	name = 'literal \ path'
	ratio = 0.5   # inline comment
	tags = [ "a", "b" ]
	big = 1_000

[[rules]]
	id = 1

[lists]
	multi = [
		"x",
	]
	when = 1979-05-27
TOML;
        $f = $this->fields($raw, 'claims-server.toml');
        $this->assertSame([1, 64], [$f['general.maxPlayers']['min'], $f['general.maxPlayers']['max']]);
        $this->assertSame(['EASY', 'NORMAL', 'HARD'], $f['general.mode']['choices']);
        foreach (['general.big', 'rules.id', 'lists.multi', 'lists.when'] as $raw_only) {
            $this->assertArrayNotHasKey($raw_only, $f, $raw_only);
        }

        $out = $this->save($raw, 'claims-server.toml', [
            'general.maxPlayers' => '16', 'general.mode' => 'HARD', 'general.name' => "it's", 'general.ratio' => '0.75', 'general.tags' => "a\nb\nc",
        ]);

        $this->assertStringContainsString("\t#Range: 1 ~ 64\n\tmaxPlayers = 16\n", $out);
        $this->assertStringContainsString("\tmode = \"HARD\"\n", $out);
        $this->assertStringContainsString("\tname = \"it's\"\n", $out, 'a literal string that now holds a quote becomes a basic string');
        $this->assertStringContainsString("\tratio = 0.75   # inline comment\n", $out);
        $this->assertStringContainsString("\ttags = [ \"a\", \"b\", \"c\" ]\n", $out);
        $this->assertStringContainsString("[[rules]]\n\tid = 1\n", $out);
        $this->assertStringContainsString("\tmulti = [\n\t\t\"x\",\n\t]\n", $out);

        $this->assertRefused($raw, 'claims-server.toml', ['general.maxPlayers' => '65'], 'highest allowed value is 64');
        $this->assertRefused($raw, 'claims-server.toml', ['general.mode' => 'PEACEFUL'], 'pick one of');
    }

    public function test_json_keeps_layout_and_edits_inside_arrays_of_objects(): void
    {
        $raw = <<<'JSON'
{
    "enabled": true,
    "radius": 12,
    "message": "Hello \"there\"",
    "servers": [
        { "name": "lobby", "port": 25565 }
    ],
    "blocked": [
        "minecraft:tnt",
        "minecraft:lava_bucket"
    ],
    "nothing": null,
    "none": []
}
JSON;
        $f = $this->fields($raw, 'mod.json');
        $this->assertArrayHasKey('servers.0.port', $f);
        $this->assertArrayNotHasKey('nothing', $f);
        $this->assertArrayNotHasKey('none', $f);

        $out = $this->save($raw, 'mod.json', [
            'enabled' => false, 'radius' => '20', 'message' => 'Hi/there ✓', 'servers.0.port' => '25570',
            'blocked' => "minecraft:tnt\nminecraft:lava_bucket\nminecraft:end_crystal",
        ]);

        $this->assertSame(<<<'JSON'
{
    "enabled": false,
    "radius": 20,
    "message": "Hi/there ✓",
    "servers": [
        { "name": "lobby", "port": 25570 }
    ],
    "blocked": [
        "minecraft:tnt",
        "minecraft:lava_bucket",
        "minecraft:end_crystal"
    ],
    "nothing": null,
    "none": []
}
JSON, $out);
        $this->assertIsArray(json_decode($out, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_json5_keeps_comments(): void
    {
        $raw = "{\n  // how far\n  radius: 12,\n  'name': 'it',\n  list: [1, 2,],\n  hex: 0x1F,\n}\n";
        $f = $this->fields($raw, 'mod.json5');
        $this->assertArrayNotHasKey('hex', $f);

        $out = $this->save($raw, 'mod.json5', ['radius' => '13', 'name' => "it's", 'list' => "1\n2\n3"]);

        $this->assertSame("{\n  // how far\n  radius: 13,\n  'name': 'it\\'s',\n  list: [1, 2, 3],\n  hex: 0x1F,\n}\n", $out);
    }

    public function test_forge_cfg_and_hocon_conf(): void
    {
        $cfg = "# Configuration file\n\ngeneral {\n    # Turn it on\n    B:enabled=true\n    I:count=5\n    D:scale=1.0\n    S:items <\n        a\n        b\n     >\n}\n";
        $f = $this->fields($cfg, 'mod.cfg');
        $this->assertSame('Turn it on', $f['general.enabled']['help']);
        $this->assertArrayNotHasKey('general.items', $f);
        $out = $this->save($cfg, 'mod.cfg', ['general.enabled' => false, 'general.count' => '6', 'general.scale' => '2']);
        $this->assertSame("# Configuration file\n\ngeneral {\n    # Turn it on\n    B:enabled=false\n    I:count=6\n    D:scale=2.0\n    S:items <\n        a\n        b\n     >\n}\n", $out);

        $conf = "# Sponge-style\nserver {\n    name = \"My server\" # shown\n    port: 25565\n    motd = plain text\n    list = [a, b]\n}\n";
        $out = $this->save($conf, 'main.conf', ['server.name' => 'Ours', 'server.port' => '25570', 'server.motd' => 'has # hash']);
        $this->assertSame("# Sponge-style\nserver {\n    name = \"Ours\" # shown\n    port: 25570\n    motd = \"has # hash\"\n    list = [a, b]\n}\n", $out);
    }

    public function test_bad_input_is_refused_with_a_reason(): void
    {
        $raw = "a: 1\nb: text\nc:\n  - x\n";
        $this->assertRefused($raw, 'c.yml', ['a' => 'ten'], "isn't a whole number");
        $this->assertRefused($raw, 'c.yml', ['b' => "two\nlines"], 'line breaks');
        $this->assertRefused($raw, 'c.yml', ['c' => ''], "can't be emptied");

        $this->expectException(ConfigEditException::class);
        $this->expectExceptionMessage('changed since the form was opened');
        ConfigEditor::save($raw, 'c.yml', [99 => 'x']);
    }

    public function test_unknown_types_have_no_form(): void
    {
        $this->assertNotNull(ConfigEditor::open('hello', 'notes.txt')['reason']);
        $this->assertNotNull(ConfigEditor::open('{a:1b}', 'level.snbt')['reason']);
    }

    /** @param array<string, mixed> $byLabel */
    private function assertRefused(string $raw, string $file, array $byLabel, string $message): void
    {
        try {
            $this->save($raw, $file, $byLabel);
            $this->fail("expected a refusal: {$message}");
        } catch (ConfigEditException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
