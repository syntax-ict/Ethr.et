{{--
    Questions the public actually asks.

    <details>/<summary> rather than a JavaScript accordion: the page ships no
    script at all and its CSP has no `script-src`, so the browser's own
    disclosure widget is the only accessible option — and it is keyboard
    operable and screen-reader announced without any help from us.
--}}
<div class="faq">
    @foreach ($section->items as $item)
        <details class="faq__item">
            <summary class="faq__question">{{ $item->title }}</summary>
            @foreach ($item->paragraphs() as $paragraph)
                <p class="faq__answer">{{ $paragraph }}</p>
            @endforeach
        </details>
    @endforeach
</div>
