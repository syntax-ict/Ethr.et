{{--
    The heading every section shares.

    Rendered as <h2> without exception. The hero owns the page's only <h1>, so
    a section that promoted itself would break the document outline for anyone
    navigating by headings — which on a page built to be read by the public is
    a real defect rather than a lint warning. PublicSectionStructureTest asserts
    exactly one h1 and no skipped levels.

    `$headingId` ties the heading to its section's aria-labelledby, so a screen
    reader announces which region it has entered.
--}}
<h2 class="section__title" id="{{ $headingId }}">{{ $section->heading }}</h2>

@foreach ($section->introParagraphs() as $paragraph)
    <p class="section__intro">{{ $paragraph }}</p>
@endforeach
