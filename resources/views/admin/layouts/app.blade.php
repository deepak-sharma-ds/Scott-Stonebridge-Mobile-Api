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
    <link href="{{ asset('/admin') }}/css/styles.css" rel="stylesheet" />

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link href="{{ asset('/admin') }}/css/custom_styles.css" rel="stylesheet" />
    <link href="{{ asset('/admin') }}/css/selecttwo.css" rel="stylesheet" />
    <link href="{{ asset('/admin') }}/css/custom.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="{{ asset('js/deleteconfirm.js') }}"></script>
    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    <style>
        /* Ensure visibility of toastr messages */
        #toast-container>.toast {
            color: #fff !important;
            /* White text */
            background-color: #333 !important;
            /* Dark background for contrast */
            font-weight: 500;
        }

        #toast-container>.toast-success {
            background-color: #28a745 !important;
        }

        #toast-container>.toast-error {
            background-color: #dc3545 !important;
        }

        #toast-container>.toast-info {
            background-color: #17a2b8 !important;
        }

        #toast-container>.toast-warning {
            background-color: #ffc107 !important;
            color: #000 !important;
        }

        .navbar a.navbar-brand img {
            width: 100%;
            height: 60px;
            object-fit: contain;
        }
    </style>

</head>

<body class="sb-nav-fixed">
    <div id="loaderOverlay">
        <div class="loader"></div>
        <p>Processing...</p>
    </div>
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
    @include('admin.elements.js_scripts')
    @yield('custom_js_scripts')
    <!-- JS dependencies -->
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

    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

    <!-- ✅ Then toastr -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        toastr.options = {
            closeButton: false,
            progressBar: true,
            positionClass: 'toast-top-right',
            timeOut: 5000
        };
    </script>
    <script>
        // Show the loader
        function showLoader() {
            document.getElementById('loaderOverlay').style.display = 'flex';
        }

        // Hide the loader
        function hideLoader() {
            document.getElementById('loaderOverlay').style.display = 'none';
        }
    </script>
</body>

</html>
