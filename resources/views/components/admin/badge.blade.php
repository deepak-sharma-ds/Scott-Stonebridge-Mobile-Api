{{--
    Blade Component: x-admin.badge
    Usage:
        <x-admin.badge type="success">Active</x-admin.badge>
        <x-admin.badge type="danger">Suspended</x-admin.badge>

    Props:
        type — primary | success | warning | danger | info | secondary
--}}

@props(['type' => 'primary'])

<span {{ $attributes->merge(['class' => 'badge badge-' . $type]) }}>
    {{ $slot }}
</span>
