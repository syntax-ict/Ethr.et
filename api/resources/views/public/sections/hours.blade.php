{{--
    Opening hours as a table, structured rather than free text.

    The same rows feed `openingHoursSpecification` in the JSON-LD, which is why
    they are a day plus two times instead of a sentence: a sentence looks
    identical to a visitor and is invisible to a search engine, and being found
    is the point of the page.
--}}
<table class="hours">
    <tbody>
        @foreach ($section->items as $item)
            <tr class="hours__row">
                <th scope="row" class="hours__day">{{ $item->title }}</th>
                <td class="hours__time">
                    @if (($item->meta['closed'] ?? '') === '1' || ! isset($item->meta['opens']))
                        {{ __('public.closed') }}
                    @else
                        <time>{{ $item->meta['opens'] }}</time>–<time>{{ $item->meta['closes'] ?? '' }}</time>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
