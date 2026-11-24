<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Announcement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What a story looks like as data, for every reader: the public feed, the
 * news list, an article, and the signed-in list and reading pane. One excerpt
 * length and one rule for the body, so the same story reads the same wherever
 * it is opened.
 *
 * The title cut is the public card's rule only. The signed-in resource puts
 * the stored title back after the summary, because the editor saves what it
 * is given.
 */
final class Story
{
    /** The longest title a public card shows; a longer one ends in an ellipsis. */
    public const TITLE_MAX = 150;

    /** The longest excerpt a card or list row is given. */
    public const EXCERPT_MAX = 280;

    /** The longest body a reader is given, which is the longest one can be written. */
    public const BODY_MAX = 10000;

    /**
     * @return array{public_id: string, title: string, excerpt: string, category: string|null, featured: bool, priority: string, published_at: string|null, cover: array{url: string, srcset: string, width: int, height: int}|null}
     */
    public static function summary(Announcement $story, string $tenantPublicId): array
    {
        return [
            'public_id' => $story->public_id,
            'title' => Str::limit($story->title, self::TITLE_MAX),
            'excerpt' => PlainExcerpt::of($story->body, self::EXCERPT_MAX),
            'category' => $story->category,
            'featured' => (bool) $story->featured,
            'priority' => $story->priority,
            'published_at' => Carbon::make($story->published_at)?->toIso8601String(),
            'cover' => $story->coverPresented($tenantPublicId),
        ];
    }

    /**
     * The summary and the whole story: its body and the public address of
     * each document.
     *
     * @return array{public_id: string, title: string, excerpt: string, category: string|null, featured: bool, priority: string, published_at: string|null, cover: array{url: string, srcset: string, width: int, height: int}|null, body: string, attachments: list<array{id: string, name: string, size: int, url: string|null}>}
     */
    public static function article(Announcement $story, string $tenantPublicId): array
    {
        return [
            ...self::summary($story, $tenantPublicId),
            'body' => self::body($story),
            'attachments' => $story->attachmentsPresented($tenantPublicId),
        ];
    }

    /** The body as plain text, markup removed: the one rule, for every reader. */
    public static function body(Announcement $story): string
    {
        return PlainExcerpt::of($story->body, self::BODY_MAX);
    }
}
