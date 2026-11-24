<?php

namespace App\Services\Printing;

/**
 * Builds raw ESC/POS byte sequences for 80mm thermal printers.
 *
 * Scope: text, alignment, bold, line feeds.
 * Intentionally excluded: paper cutter (GS V), cash drawer (ESC p).
 */
class EscPosBuilder
{
    private string $buffer = '';

    // ── ESC/POS control constants ─────────────────────────────────────────────

    private const ESC = "\x1B";

    private const GS = "\x1D";

    private const CMD_INIT = self::ESC.'@';

    private const CMD_ALIGN_LEFT = self::ESC.'a'."\x00";

    private const CMD_ALIGN_CENTER = self::ESC.'a'."\x01";

    private const CMD_ALIGN_RIGHT = self::ESC.'a'."\x02";

    private const CMD_BOLD_ON = self::ESC.'E'."\x01";

    private const CMD_BOLD_OFF = self::ESC.'E'."\x00";

    private const CMD_DOUBLE_HEIGHT_ON = self::GS.'!'."\x01";

    private const CMD_DOUBLE_HEIGHT_OFF = self::GS.'!'."\x00";

    // ── Public interface ──────────────────────────────────────────────────────

    public function initialize(): static
    {
        $this->buffer .= self::CMD_INIT;

        return $this;
    }

    public function alignLeft(): static
    {
        $this->buffer .= self::CMD_ALIGN_LEFT;

        return $this;
    }

    public function alignCenter(): static
    {
        $this->buffer .= self::CMD_ALIGN_CENTER;

        return $this;
    }

    public function alignRight(): static
    {
        $this->buffer .= self::CMD_ALIGN_RIGHT;

        return $this;
    }

    public function bold(bool $on = true): static
    {
        $this->buffer .= $on ? self::CMD_BOLD_ON : self::CMD_BOLD_OFF;

        return $this;
    }

    public function doubleHeight(bool $on = true): static
    {
        $this->buffer .= $on ? self::CMD_DOUBLE_HEIGHT_ON : self::CMD_DOUBLE_HEIGHT_OFF;

        return $this;
    }

    /**
     * Append sanitized text followed by a line break.
     * Non-ASCII characters are transliterated to ASCII safe equivalents.
     */
    public function text(string $text): static
    {
        $this->buffer .= $this->sanitize($text)."\n";

        return $this;
    }

    /**
     * Append raw text without a trailing newline.
     */
    public function raw(string $text): static
    {
        $this->buffer .= $this->sanitize($text);

        return $this;
    }

    /**
     * Append a line of repeated characters (default dash ruler).
     */
    public function rule(int $cols = 48, string $char = '-'): static
    {
        $this->buffer .= str_repeat($char, $cols)."\n";

        return $this;
    }

    /**
     * Feed N blank lines.
     */
    public function feed(int $lines = 1): static
    {
        $this->buffer .= self::ESC.'d'.chr(min($lines, 255));

        return $this;
    }

    /**
     * Append a two-column formatted line (label left, value right).
     */
    public function twoCol(string $label, string $value, int $cols = 48): static
    {
        $label = $this->sanitize($label);
        $value = $this->sanitize($value);
        $space = $cols - mb_strlen($label) - mb_strlen($value);
        if ($space < 1) {
            $space = 1;
        }
        $this->buffer .= $label.str_repeat(' ', $space).$value."\n";

        return $this;
    }

    /**
     * Return the accumulated byte sequence.
     */
    public function getBytes(): string
    {
        return $this->buffer;
    }

    /**
     * Reset the buffer (useful when building multiple documents sequentially).
     */
    public function reset(): static
    {
        $this->buffer = '';

        return $this;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Sanitize a string for ESC/POS: strip control chars except LF/CR,
     * transliterate common Unicode to ASCII (Rp prefix, en/em dashes, etc.).
     */
    private function sanitize(string $input): string
    {
        // Common Unicode → ASCII substitutions
        $map = [
            // Quotes
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            // Dashes
            "\u{2013}" => '-', "\u{2014}" => '-',
            // Spaces
            "\u{00A0}" => ' ',
            // Ellipsis
            "\u{2026}" => '...',
            // Rupiah sign
            "\u{20A8}" => 'Rp',
        ];

        $output = strtr($input, $map);

        // Strip any remaining non-printable characters except LF (0x0A) and CR (0x0D)
        $output = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $output);

        // Strip multi-byte characters not transliterated above (keep ASCII printable range)
        $output = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', '?', $output);

        return $output;
    }
}
