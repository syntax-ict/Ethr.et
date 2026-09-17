{{--
    Figures the organisation has chosen to publish.

    Every number here was typed by an administrator. Nothing on this page is
    derived from an HR table — no headcount from Employee::count(), no payroll
    total — and `stats` is where that temptation is strongest, which is why it
    is worth saying in the template as well as in the enum.
--}}
<dl class="stats">
    @foreach ($section->items as $item)
        <div class="stat">
            <dt class="stat__label">{{ $item->title }}</dt>
            <dd class="stat__value">{{ $item->meta['value'] ?? $item->body }}</dd>
        </div>
    @endforeach
</dl>
