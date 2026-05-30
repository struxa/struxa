<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Where a block type may be used in the admin builder.
 *
 * Plugin definitions may set {@code hosts} on each block to limit availability
 * (e.g. {@code ['content_entry']} only). When omitted, blocks appear on all hosts.
 */
final class BlockBuilderHost
{
    public const PAGE = 'page';

    public const CONTENT_ENTRY = 'content_entry';

    /** @var list<string> */
    public const ALL = [self::PAGE, self::CONTENT_ENTRY];
}
