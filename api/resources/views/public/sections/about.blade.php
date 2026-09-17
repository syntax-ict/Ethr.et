{{--
    Prose about the organisation, from the profile's description.

    Paragraphs are split on blank lines by the view-model, never by `nl2br` on
    raw output — no public view is allowed an unescaped echo outside the
    JSON-LD block, and a test enforces it.
--}}
@foreach ($page->descriptionParagraphs() as $paragraph)
    <p>{{ $paragraph }}</p>
@endforeach
