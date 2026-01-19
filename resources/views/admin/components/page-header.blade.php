{{--
    Component: Page Header
    Usage: @include('admin.components.page-header', ['title' => 'Page Title', 'subtitle' => 'Description', 'action' => optional])
--}}

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-size: 2rem; font-weight: 900; color: #ffffff; margin: 0;">
                {{ $title }}
            </h1>
            @if (isset($subtitle))
                <p style="color: rgba(255, 255, 255, 0.9); font-size: 1rem; margin-top: 0.5rem; margin-bottom: 0;">
                    {{ $subtitle }}
                </p>
            @endif
        </div>
        @if (isset($action))
            <div>
                {!! $action !!}
            </div>
        @endif
    </div>
</div>
