<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

/** Wraps a string that is already valid XML, so Xml::propTag() won't escape it again. */
final class Raw
{
    public function __construct(public readonly string $xml)
    {
    }
}
