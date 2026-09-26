<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

/** Just enough iCalendar/vCard reading to file an object away — we store the rest verbatim. */
final class Vobject
{
    public static function extractUid(string $data): ?string
    {
        // Unfold "folded" lines (a leading space/tab continues the previous line) before matching.
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $data) ?? $data;
        if (preg_match('/^UID:(.+)$/mi', $unfolded, $m)) {
            return trim($m[1]) ?: null;
        }
        return null;
    }
}
