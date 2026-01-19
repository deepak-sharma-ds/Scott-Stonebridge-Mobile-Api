<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="" />
    <meta name="author" content="" />

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('Site.title') ? config('Site.title') : 'Coniq Shopify' }}</title>
    <link rel="icon" type="image/png" href="{{ asset('admin/images/favicon.png') }}">

    @include('admin.elements.css_links')
    @yield('custom_css_links')

</head>

<body class="sb-nav-fixed">

    <!-- Loader Overlay -->
    <div id="loaderOverlay">
        <div class="loader"></div>
        <p>Processing...</p>
    </div>

    <!-- Navbar -->
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <!-- Navbar Brand-->
        <a class="navbar-brand ps-3" href="{{ url('/admin/dashboard') }}">
            <img src="{{ asset('storage/configuration-images/' . config('Site.logo')) }}" alt="Logo">
        </a>
        <!-- Sidebar Toggle-->
        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" href="#!"><i
                class="fas fa-bars"></i></button>

        <!-- Navbar Search-->
        <form class="d-none d-md-inline-block form-inline ms-auto me-0 me-md-3 my-2 my-md-0">
            <div class="input-group">
            </div>
        </form>
        <!-- Navbar-->

        <ul class="navbar-nav ms-auto ms-md-0 me-3 me-lg-4">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" id="navbarDropdown" href="#" role="button"
                    data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-user fa-fw"></i>Hi
                    {{ auth()->user()->name }}</a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                    <li><a class="dropdown-item" href="{{ route('admin.profile.edit') }}">Profile</a></li>
                    <li>
                        <hr class="dropdown-divider" />
                    </li>
                    <li>
                        <a class="dropdown-item" href="#"
                            onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                            {{ __('Logout') }}
                        </a>
                    </li>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>
                </ul>
            </li>
        </ul>
    </nav>

    <!-- Side Navigation & Content -->
    <div id="layoutSidenav">
        @include('admin.elements.header')
        <div id="layoutSidenav_content">
            <main class="mt-5">
                @include('admin.elements.alert_message')
                @yield('content')
            </main>
            @include('admin.elements.footer')
        </div>
    </div>

</body>

<!-- JS dependencies -->
@include('admin.elements.js_scripts')
@yield('custom_js_scripts')

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                const submitButtons = form.querySelectorAll('button[type="submit"]');
                submitButtons.forEach(function(btn) {
                    btn.disabled = true;
                    // btn.innerText = 'Submitting...'; // Optional: change button text
                });
            });
        });
    });
</script>

<script>
    toastr.options = {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: 5000,
        extendedTimeOut: 1000,
        showMethod: 'fadeIn',
        hideMethod: 'fadeOut'
    };
</script>

</html>
