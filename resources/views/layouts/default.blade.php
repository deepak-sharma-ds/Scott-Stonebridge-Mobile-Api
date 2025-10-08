<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="keywords" content="" />
    <meta name="author" content="" />
    <meta name="robots" content="" />
    <meta name="description" content="@yield('page_description', $page_description ?? '')" />
    <meta name="format-detection" content="telephone=no">
    
    <title>{{ config('Site.title') ? config('Site.title') : config('dz.name') ; }} | @yield('title', $page_title ?? '')</title>
    <!-- Favicon icon -->
    @if(config('Site.favicon'))
        <link rel="icon" type="image/png" href="{{ asset('storage/configuration-images/'.config('Site.favicon')) }}">
    @else 
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}">
    @endif
    <link href="https://cdn.lineicons.com/2.0/LineIcons.css" rel="stylesheet" type="text/css"/>

    @php
        $action = DzHelper::controller().'_'.DzHelper::action();
        $admin_layout_options = json_decode(config('Settings.admin_layout_options', json_encode(config('constants.dezThemeSet0'))));
    @endphp
    @if(isset($action) && !empty(config('dz.public.pagelevel.css.'.$action))) 
        @foreach(config('dz.public.pagelevel.css.'.$action) as $style)
            <link href="{{ asset($style) }}" rel="stylesheet" type="text/css"/>
        @endforeach
    @endif  

    {{-- Global Theme Styles (used by all pages) --}}
    @if(!empty(config('dz.public.global.css')))
        @foreach(config('dz.public.global.css') as $style)
            @if($admin_layout_options->direction == 'rtl' && str_contains('css/style.css', $style))
                @php
                    $style = str_replace('css/style.css', 'css/rtl-style.css', $style);
                @endphp
            @endif
            <link href="{{ asset($style) }}" {!! str_contains('css/style.css', $style) ? 'id="ChangeStyleRtl"' : '' !!} rel="stylesheet" type="text/css"/>
        @endforeach
    @endif

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap">
    

</head>

<body>

    <!--*******************
        Preloader start
    ********************-->
    <div id="preloader">
        <div class="sk-three-bounce">
            <div class="sk-child sk-bounce1"></div>
            <div class="sk-child sk-bounce2"></div>
            <div class="sk-child sk-bounce3"></div>
        </div>
    </div>
    <!--*******************
        Preloader end
    ********************-->

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <!--**********************************
            Nav header start
        ***********************************-->
        <div class="nav-header">
            {{-- <a href="{!! url('/admin'); !!}" class="brand-logo">
                @if (!empty(config('Site.icon_logo')) && !empty(config('Site.text_logo')))
                    <img class="logo-abbr" src="{{ asset('storage/configuration-images/'.config('Site.icon_logo')) }}">
                    <img class="brand-title" src="{{ asset('storage/configuration-images/'.config('Site.text_logo')) }}">
                @else
                    <img class="logo-abbr" src="{{ asset('images/logo.png') }}">
                    <img class="brand-title" src="{{ asset('images/logo-text.png') }}">
                @endif
            </a> --}}

            <div class="nav-control">
                <div class="hamburger">
                    <span class="line"></span><span class="line"></span><span class="line"></span>
                </div>
            </div>
        </div>
        <!--**********************************
            Nav header end
        ***********************************-->

        <!--**********************************
            Header start
        ***********************************-->
        
          @include('admin.elements.header')
        
        
        <!--**********************************
            Header end ti-comment-alt
        ***********************************-->
        
        <!--**********************************
            Sidebar start
        ***********************************-->
        @include('admin.elements.sidebar')
        <!--**********************************
            Sidebar end
        ***********************************-->
        
        <!--**********************************
            Content body start kri
        ***********************************-->
        <div class="content-body">
            @include('admin.elements.alert_message')
            <!-- row -->
            @yield('content')
        </div>
        <!--**********************************
            Content body end
        ***********************************-->


        <!--**********************************
            Footer start
        ***********************************-->
        
        @include('admin.elements.footer')
        
        <!--**********************************
            Footer end
        ***********************************-->

        <!--**********************************
           Support ticket button start
        ***********************************-->

        <!--**********************************
           Support ticket button end
        ***********************************-->


    </div>
    <!--**********************************
        Main wrapper end
    ***********************************-->

    <!--**********************************
        Role, User Permissions Model Start
    ***********************************-->
    <div class="modal fade" id="AssignRevokePermissionsModal">
        <div class="modal-dialog modal-dialog-centered  modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('common.permissions') }}</h5>
                    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="AssignRevokePermissionsModalBody">
                </div>
            </div>
        </div>
    </div>
    <!--**********************************
        Role, User Permissions Model End
    ***********************************-->

    <!--**********************************
        Theme Demo Data Import Model Start
    ***********************************-->
    <div class="modal fade" id="ImportDataForm">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form action="{{ route('themes.admin.import_theme') }}" method="post">
                @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('common.import_demo_data') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal">
                        </button>
                    </div>
                    <div class="modal-body">
                            <input type="hidden" name="db_file" id="DBFileUrl">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="import_type" value="draft" id="draft">
                                        <label class="form-check-label" for="draft">{{ __('common.draft_all_data_option') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="import_type" value="delete" id="delete">
                                        <label class="form-check-label" for="delete">{{ __('common.delete_all_data_option') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="import_type" value="only_import" id="only_import" checked>
                                        <label class="form-check-label" for="only_import">{{ __('common.only_import_data_option') }}</label>
                                    </div>
                                </div>
                            </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger light" data-bs-dismiss="modal">{{ __('common.close') }}</button>
                        <button type="submit" class="btn btn-primary" id="importBtn">{{ __('common.save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!--**********************************
        Theme Demo Data Import Model End
    ***********************************-->

    @stack('inline-modals')

    <!--**********************************
        Scripts
    ***********************************-->
    <script>
        'use strict';
        var baseUrl = "{{ url('/') }}";
        var enableCkeditor = '{!! config('Writing.editable') !!}';
        var makeSlugUrl = '<?php echo route('configurations.make_slug') ?>';
        var direction = '<?php echo config('Site.direction', 'ltr') ?>';
        var admin_theme = '<?php echo config('Setting.admin_layout', "0") ?>';
        var dezSettingsOptions = JSON.parse('<?php echo config('Settings.admin_layout_options', json_encode(config('constants.dezThemeSet0'))) ?>');
        var uploadFilesRoute = '<?php echo route('configurations.upload_files') ?>';
        var removeFilesRoute = '<?php echo route('configurations.remove_files') ?>';
    </script>


    @if(!empty(config('dz.public.global.js.top')))
        @foreach(config('dz.public.global.js.top') as $script)
            <script src="{{ asset($script) }}" type="text/javascript"></script>
        @endforeach
    @endif
    @if(!empty(config('dz.public.pagelevel.js.'.$action)))
        @foreach(config('dz.public.pagelevel.js.'.$action) as $script)
            <script src="{{ asset($script) }}" type="text/javascript"></script>
        @endforeach
    @endif
    @if(!empty(config('dz.public.global.js.bottom')))
        @foreach(config('dz.public.global.js.bottom') as $script)
            <script src="{{ asset($script) }}" type="text/javascript"></script>
        @endforeach
    @endif
    
    @stack('inline-scripts')
    
</body>
</html>