<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;

/**
 * Laravel's Markdown renderer, with a plain-text part that notices a changed
 * template.
 *
 * The HTML part compiles a view under a non-default echo format, which gives it
 * its own compiled file; the text part compiles the same source under the default
 * format into another. The view engine remembers "checked this request" by SOURCE
 * path, so once the HTML part has rendered, the text part skips its expiry check
 * and runs whatever compiled file it finds, however old. After a template change
 * the text part of every email stayed on the previous wording until compiled
 * views were cleared (seen on production 2026-11-28, after the greeting change in
 * 935). Forgetting the memory first makes the text part check its own file.
 */
class FreshTextMarkdown extends Markdown
{
    /**
     * @param  string  $view
     */
    public function renderText($view, array $data = []): HtmlString
    {
        $engine = $this->view->getEngineResolver()->resolve('blade');

        if (method_exists($engine, 'forgetCompiledOrNotExpired')) {
            $engine->forgetCompiledOrNotExpired();
        }

        return parent::renderText($view, $data);
    }
}
