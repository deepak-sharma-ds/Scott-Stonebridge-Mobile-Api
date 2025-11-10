<div id="layoutSidenav_nav">
    <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
        <div class="main-profile">
            <!--<div class="image-bx">
                <img src="https://demo.w3cms.in/lemars/public/images/no-user.png" alt="User profile">
                <a href="#"><i class="fa fa-cog" aria-hidden="true"></i></a>
            </div>-->
            <h5 class="name"><span class="font-w400">Hello,</span> {{ auth()->user()->name }} </h5>
            <p class="role"></p>
            <p class="email">{{ auth()->user()->email }}</p>
        </div>
        <div class="sb-sidenav-menu">
            <div class="nav">
                <div class="sb-sidenav-menu-heading">Core</div>
                <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                    href="{{ url('/admin/dashboard') }}">
                    <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                    Dashboard
                </a>
                <a class="nav-link {{ request()->routeIs('admin.scheduled-meetings') ? 'active' : '' }}"
                    href="{{ url('/admin/booking-inquiries') }}">
                    <div class="sb-nav-link-icon"><i class="fa-solid fa-book"></i></div>
                    Booking Inquiries
                </a>
                <a class="nav-link {{ request()->routeIs('admin.availability.index') ? 'active' : '' }}"
                    href="{{ url('/admin/availability') }}">
                    <div class="sb-nav-link-icon"><i class="fas fa-calendar"></i></div>
                    Availability Slots
                </a>
                @role('Admin')
                    <div class="sb-sidenav-menu-heading">Audio Subscriptions</div>

                    @php
                        $isAudioSectionActive = request()->routeIs('packages.*') || request()->routeIs('audios.*');
                    @endphp

                    <a class="nav-link {{ $isAudioSectionActive ? '' : 'collapsed' }}" href="#"
                        data-bs-toggle="collapse" data-bs-target="#collapseAudioSubscriptions"
                        aria-expanded="{{ $isAudioSectionActive ? 'true' : 'false' }}"
                        aria-controls="collapseAudioSubscriptions">
                        <div class="sb-nav-link-icon"><i class="fa-solid fa-gear"></i></div>
                        Audio Subscriptions
                        <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                    </a>

                    <div class="collapse {{ $isAudioSectionActive ? 'show' : '' }}" id="collapseAudioSubscriptions"
                        data-bs-parent="#sidenavAccordion">

                        <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link {{ request()->routeIs('packages.*') ? 'active' : '' }}"
                                href="{{ route('packages.index') }}">
                                <div class="sb-nav-link-icon"><i class="fa-solid fa-box"></i></div>Packages
                            </a>

                            <a class="nav-link {{ request()->routeIs('audios.*') ? 'active' : '' }}"
                                href="{{ route('audios.index') }}">
                                <div class="sb-nav-link-icon"><i class="fa-solid fa-audio-description"></i></div>Audios
                            </a>
                        </nav>
                    </div>
                @endrole


                @role('Admin')
                    <div class="sb-sidenav-menu-heading">Configurations</div>
                    @php
                        $configuration_menu = getConfigurationMenu();
                        $collapsed = 'collapsed';
                        $prefix = '';
                        if (request()->routeIs('admin.configurations.admin_prefix')) {
                            $collapsed = '';
                            $prefix = \Illuminate\Support\Facades\Request::segment(4);
                        }
                    @endphp

                    @if (!empty($configuration_menu))
                        <a class="nav-link {{ $collapsed }}" href="#" data-bs-toggle="collapse"
                            data-bs-target="#Configurations" aria-expanded="false" aria-controls="collapseLayouts">
                            <div class="sb-nav-link-icon"><i class="fa-solid fa-gear"></i></div>
                            Global Configurations
                            <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse {{ request()->routeIs('admin.configurations.admin_prefix') ? 'show' : '' }}"
                            id="Configurations" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                            <nav class="sb-sidenav-menu-nested nav">
                                @forelse($configuration_menu as $config_menu)
                                    <a class="nav-link {{ $prefix == $config_menu ? 'active' : '' }}"
                                        href="{{ route('admin.configurations.admin_prefix', $config_menu) }}">{{ $config_menu }}</a>
                                @empty
                                @endforelse
                            </nav>
                        </div>
                    @endif
                @endrole
            </div>
        </div>
    </nav>
</div>
