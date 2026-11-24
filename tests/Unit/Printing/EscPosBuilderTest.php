<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosBuilder;
use PHPUnit\Framework\TestCase;

class EscPosBuilderTest extends TestCase
{
    private EscPosBuilder $esc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->esc = new EscPosBuilder;
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    public function test_initialize_emits_esc_at_sequence(): void
    {
        $bytes = $this->esc->initialize()->getBytes();
        $this->assertStringContainsString("\x1B\x40", $bytes);
    }

    // ── Alignment ─────────────────────────────────────────────────────────────

    public function test_align_left_emits_correct_bytes(): void
    {
        $bytes = $this->esc->alignLeft()->getBytes();
        $this->assertStringContainsString("\x1B\x61\x00", $bytes);
    }

    public function test_align_center_emits_correct_bytes(): void
    {
        $bytes = $this->esc->alignCenter()->getBytes();
        $this->assertStringContainsString("\x1B\x61\x01", $bytes);
    }

    public function test_align_right_emits_correct_bytes(): void
    {
        $bytes = $this->esc->alignRight()->getBytes();
        $this->assertStringContainsString("\x1B\x61\x02", $bytes);
    }

    // ── Bold ──────────────────────────────────────────────────────────────────

    public function test_bold_on_emits_correct_bytes(): void
    {
        $bytes = $this->esc->bold(true)->getBytes();
        $this->assertStringContainsString("\x1B\x45\x01", $bytes);
    }

    public function test_bold_off_emits_correct_bytes(): void
    {
        $bytes = $this->esc->bold(false)->getBytes();
        $this->assertStringContainsString("\x1B\x45\x00", $bytes);
    }

    public function test_bold_defaults_to_on(): void
    {
        $bytes = $this->esc->bold()->getBytes();
        $this->assertStringContainsString("\x1B\x45\x01", $bytes);
    }

    // ── Text ──────────────────────────────────────────────────────────────────

    public function test_text_appends_newline(): void
    {
        $bytes = $this->esc->text('Hello')->getBytes();
        $this->assertStringContainsString("Hello\n", $bytes);
    }

    public function test_text_sanitizes_control_characters(): void
    {
        $bytes = $this->esc->text("Hello\x01\x1FWorld")->getBytes();
        $this->assertStringContainsString('HelloWorld', $bytes);
        $this->assertStringNotContainsString("\x01", $bytes);
        $this->assertStringNotContainsString("\x1F", $bytes);
    }

    public function test_text_replaces_non_ascii_with_question_mark(): void
    {
        $bytes = $this->esc->text('Mènu')->getBytes();
        $this->assertStringContainsString('M?nu', $bytes);
    }

    public function test_text_transliterates_known_unicode(): void
    {
        $bytes = $this->esc->text("\u{2013}dash\u{2014}dash")->getBytes();
        $this->assertStringContainsString('-dash-dash', $bytes);
    }

    public function test_text_transliterates_quotes(): void
    {
        $bytes = $this->esc->text("\u{2018}quoted\u{2019}")->getBytes();
        $this->assertStringContainsString("'quoted'", $bytes);
    }

    // ── Rule ──────────────────────────────────────────────────────────────────

    public function test_rule_produces_correct_length(): void
    {
        $bytes = $this->esc->rule(48)->getBytes();
        $this->assertStringContainsString(str_repeat('-', 48)."\n", $bytes);
    }

    public function test_rule_respects_custom_char(): void
    {
        $bytes = $this->esc->rule(10, '=')->getBytes();
        $this->assertStringContainsString(str_repeat('=', 10)."\n", $bytes);
    }

    // ── Feed ──────────────────────────────────────────────────────────────────

    public function test_feed_emits_correct_bytes(): void
    {
        $bytes = $this->esc->feed(5)->getBytes();
        $this->assertStringContainsString("\x1B\x64\x05", $bytes);
    }

    public function test_feed_defaults_to_one_line(): void
    {
        $bytes = $this->esc->feed()->getBytes();
        $this->assertStringContainsString("\x1B\x64\x01", $bytes);
    }

    // ── TwoCol ────────────────────────────────────────────────────────────────

    public function test_two_col_produces_correct_width(): void
    {
        $bytes = $this->esc->twoCol('Label', 'Value', 48)->getBytes();
        // Extract the line (before \n)
        $line = explode("\n", $bytes)[0];
        $this->assertSame(48, mb_strlen($line));
        $this->assertStringStartsWith('Label', $line);
        $this->assertStringEndsWith('Value', $line);
    }

    public function test_two_col_has_minimum_one_space(): void
    {
        // Label + Value = 47 chars; cols=48 → 1 space
        $label = str_repeat('A', 24);
        $value = str_repeat('B', 23);
        $bytes = $this->esc->twoCol($label, $value, 48)->getBytes();
        $line = explode("\n", $bytes)[0];
        $this->assertSame(48, mb_strlen($line));
    }

    public function test_two_col_handles_overflow(): void
    {
        // When label+value >= cols, still produces at least 1 space
        $label = str_repeat('A', 40);
        $value = str_repeat('B', 20);
        $bytes = $this->esc->twoCol($label, $value, 48)->getBytes();
        $this->assertStringContainsString($label, $bytes);
        $this->assertStringContainsString($value, $bytes);
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    public function test_reset_clears_buffer(): void
    {
        $this->esc->text('Hello');
        $this->esc->reset();
        $this->assertSame('', $this->esc->getBytes());
    }

    // ── Method chaining ───────────────────────────────────────────────────────

    public function test_methods_are_chainable(): void
    {
        $bytes = (new EscPosBuilder)
            ->initialize()
            ->alignCenter()
            ->bold(true)
            ->text('Title')
            ->bold(false)
            ->alignLeft()
            ->rule(48)
            ->feed(2)
            ->getBytes();

        $this->assertNotEmpty($bytes);
        $this->assertStringContainsString('Title', $bytes);
    }

    // ── No cutter, no drawer ──────────────────────────────────────────────────

    public function test_no_cutter_command_in_output(): void
    {
        $bytes = $this->esc->initialize()->text('Test')->feed(5)->getBytes();
        // GS V (cutter) = 0x1D 0x56
        $this->assertStringNotContainsString("\x1D\x56", $bytes);
    }

    public function test_no_cash_drawer_command_in_output(): void
    {
        $bytes = $this->esc->initialize()->text('Test')->feed(5)->getBytes();
        // ESC p (cash drawer) = 0x1B 0x70
        $this->assertStringNotContainsString("\x1B\x70", $bytes);
    }
}
