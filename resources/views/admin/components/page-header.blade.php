{{--
    Component: Page Header
    Usage: @include('admin.components.page-header', [
        'title'    => 'Page Title',
        'subtitle' => 'Optional description',    (optional)
        'action'   => '<a href="...">Button</a>', (optional, raw HTML)
    ])
--}}
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:1rem;position:relative;z-index:1;">
        <div>
            <h1>{{ $title }}</h1>
            @if(!empty($subtitle))
                <p>{{ $subtitle }}</p>
            @endif
        </div>
        @if(!empty($action))
            <div>{!! $action !!}</div>
        @endif
    </div>
</div>
