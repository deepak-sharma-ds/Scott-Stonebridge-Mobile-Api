{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4>Configuration</h4>
                <span>{{ Str::ucfirst($prefix) }} Configuration</span>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.configurations.admin_index') }}">Configuration</a></li>
                <li class="breadcrumb-item active"><a href="javascript:void(0)">{{ Str::ucfirst($prefix) }}</a></li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ Str::ucfirst($prefix) }} Configuration</h4>
                </div>
                <div class="card-body">
                    <div class="basic-form">
                        <form action="{{ route('admin.configurations.save_config', $prefix) }}" method="post" enctype="multipart/form-data">
                            @csrf
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteTitle">Title</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[title]" id="SiteTitle" class="form-control" value="{{ config('Site.title') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteTagline">Tagline</label>
                                <div class="col-sm-6 form-group">
                                    <textarea name="Site[tagline]" id="SiteTagline" class="form-control h-100" rows="4">{{ config('Site.tagline') }}</textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteEmail">{{ __('common.email') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[email]" id="SiteEmail" class="form-control" value="{{ config('Site.email') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteDefaultRole">{{ __('common.default_role_new_user') }}</label>
                                <div class="col-sm-6 form-group">
                                    <select name="Site[default_role]" id="SiteDefaultRole" class="form-control">
                                        @forelse($roles as $role)
                                            <option value="{{ $role->id }}" {{ config('Site.default_role') == $role->id ? 'selected="selected"' : '' }}>{{ $role->name }}</option>
                                        @empty
                                        @endforelse
                                    </select>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteW3cmsLocal">{{ __('common.select_language') }}</label>
                                <div class="col-sm-6 form-group">
                                    <select name="Site[w3cms_locale]" id="SiteW3cmsLocal" class="form-control">
                                        <option value="en" {{ config('Site.w3cms_locale') == 'en' ? 'selected="selected"' : '' }}>{{ __('English') }}</option>
                                        <option value="hi" {{ config('Site.w3cms_locale') == 'hi' ? 'selected="selected"' : '' }}>{{ __('Hindi') }}</option>
                                        <option value="fr" {{ config('Site.w3cms_locale') == 'fr' ? 'selected="selected"' : '' }}>{{ __('French') }}</option>
                                        <option value="ru" {{ config('Site.w3cms_locale') == 'ru' ? 'selected="selected"' : '' }}>{{ __('Russian') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteW3cmsLocal">{{ __('common.date_format') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="radio">
                                        <label><input type="radio" name="Site[date_format]" class="ChangeDateFormat" value="F j, Y" @checked(config('Site.date_format') == 'F j, Y') data-date="{{ date('F j, Y') }}"> {{ date('F j, Y') }} <span class="badge badge-info ms-5">F j, Y</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[date_format]" class="ChangeDateFormat" value="Y-m-d" @checked(config('Site.date_format') == 'Y-m-d') data-date="{{ date('Y-m-d') }}"> {{ date('Y-m-d') }} <span class="badge badge-info ms-5">Y-m-d</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[date_format]" class="ChangeDateFormat" value="m/d/Y" @checked(config('Site.date_format') == 'm/d/Y') data-date="{{ date('m/d/Y') }}"> {{ date('m/d/Y') }} <span class="badge badge-info ms-5">m/d/Y</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[date_format]" class="ChangeDateFormat" value="d/m/Y" @checked(config('Site.date_format') == 'd/m/Y') data-date="{{ date('d/m/Y') }}"> {{ date('d/m/Y') }} <span class="badge badge-info ms-5">d/m/Y</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[date_format]" value="custom" @checked(config('Site.date_format') == 'custom')> {{ __('common.custom') }}: <input type="text" name="Site[custom_date_format]" class="form-control" value="{{ config('Site.custom_date_format') }}" data-link="{{ route('admin.configurations.date_time_format') }}"> </label>
                                    </div>
                                    <small>{{ __('common.preview') }}: <span class="DateFormatContainer">{{ date('F j, Y') }}</span></small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteW3cmsLocal">{{ __('common.time_format') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="radio">
                                        <label><input type="radio" name="Site[time_format]" class="ChangeTimeFormat" value="g:i a" @checked(config('Site.time_format') == 'g:i a') data-time="{{ date('g:i a') }}"> {{ date('g:i a') }} <span class="badge badge-info ms-5">g:i a</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[time_format]" class="ChangeTimeFormat" value="g:i A" @checked(config('Site.time_format') == 'g:i A') data-time="{{ date('g:i A') }}"> {{ date('g:i A') }} <span class="badge badge-info ms-5">g:i A</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[time_format]" class="ChangeTimeFormat" value="H:i" @checked(config('Site.time_format') == 'H:i') data-time="{{ date('H:i') }}"> {{ date('H:i') }} <span class="badge badge-info ms-5">H:i</span></label>
                                    </div>
                                    <div class="radio">
                                        <label><input type="radio" name="Site[time_format]" class="ChangeTimeFormat" value="custom" @checked(config('Site.time_format') == 'F j, Y') data-time="{{ date('g:i a') }}"> {{ __('common.custom') }}: <input type="text" name="Site[custom_time_format]" class="form-control" value="{{ config('Site.custom_time_format') }}" data-link="{{ route('admin.configurations.date_time_format') }}"> </label>
                                    </div>
                                    <small>{{ __('common.preview') }}: <span class="TimeFormatContainer">{{ date('g:i a') }}</span></small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteCopyright">{{ __('common.copyright_text') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[copyright]" id="SiteCopyright" class="form-control" value="{{ config('Site.copyright') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteFooterText">{{ __('common.footer_text') }}</label>
                                <div class="col-sm-6 form-group">
                                    <textarea name="Site[footer_text]" id="SiteFooterText" class="form-control h-100" rows="4">{{ config('Site.footer_text') }}</textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteContact">{{ __('common.contact') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[contact]" id="SiteContact" class="form-control" value="{{ config('Site.contact') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteLogo">{{ __('common.logo') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="img-parent-box">
                                        @if(config('Site.logo'))
                                            <img src="{{ asset('storage/configuration-images/'.config('Site.logo')) }}" alt="{{ config('Site.logo') }}" class="configurationPrefixImg img-for-onchange">
                                        @endif
                                        <div class="form-file d-inline-block">
                                            <input type="file" accept=".png, .jpg, .jpeg, .svg" name="Site[logo]" id="SiteLogo" class="form-file-input img-input-onchange ps-2 form-control">
                                        </div>
                                    </div>
                                    <small class="d-block">{{ __('common.site_logo') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteLogoWhite">{{ __('common.logo_white') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="img-parent-box">
                                        @if(config('Site.logo_white'))
                                            <img src="{{ asset('storage/configuration-images/'.config('Site.logo_white')) }}" alt="{{ config('Site.logo_white') }}" class="configurationPrefixImg img-for-onchange">
                                        @endif
                                        <div class="form-file d-inline-block">
                                            <input type="file" accept=".png, .jpg, .jpeg, .svg" name="Site[logo_white]" id="SiteLogoWhite" class="form-file-input img-input-onchange ps-2 form-control">
                                        </div>
                                    </div>
                                    <small class="d-block">{{ __('common.site_logo') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteFavicon">{{ __('common.site_favicon') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="img-parent-box">
                                        @if(config('Site.favicon'))
                                            <img src="{{ asset('storage/configuration-images/'.config('Site.favicon')) }}" alt="{{ config('Site.favicon') }}" class="configurationPrefixImg img-for-onchange">
                                        @endif
                                        <div class="form-file d-inline-block">
                                            <input type="file" accept=".png, .jpg, .jpeg, .svg" name="Site[favicon]" id="SiteFavicon" class="form-file-input img-input-onchange ps-2 form-control">
                                        </div>
                                    </div>
                                    <small class="d-block">{{ __('common.site_favicon') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteTextLogo">{{ __('common.text_logo') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="img-parent-box">
                                        @if(config('Site.text_logo'))
                                            <img src="{{ asset('storage/configuration-images/'.config('Site.text_logo')) }}" alt="{{ config('Site.text_logo') }}" class="configurationPrefixImg img-for-onchange">
                                        @endif
                                        <div class="form-file d-inline-block">
                                            <input type="file" accept=".png, .jpg, .jpeg, .svg" name="Site[text_logo]" id="SiteTextLogo" class="form-file-input img-input-onchange ps-2 form-control">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteIconLogo">{{ __('common.icon_logo') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="img-parent-box">
                                        @if(config('Site.icon_logo'))
                                            <img src="{{ asset('storage/configuration-images/'.config('Site.icon_logo')) }}" alt="{{ config('Site.icon_logo') }}" class="configurationPrefixImg img-for-onchange">
                                        @endif
                                        <div class="form-file d-inline-block">
                                            <input type="file" accept=".png, .jpg, .jpeg, .svg" name="Site[icon_logo]" id="SiteIconLogo" class="form-file-input img-input-onchange ps-2 form-control">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteStatus">{{ __('common.status') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="hidden" name="Site[status]" value="0">
                                    <input type="checkbox" name="Site[status]" id="SiteStatus" {{ config('Site.status') == 1 ? 'checked="checked"' : '' }} class="form-check-input" value="1">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteMaintenanceMessage">{{ __('common.maintenance_message') }}</label>
                                <div class="col-sm-6 form-group">
                                    <textarea name="Site[maintenance_message]" id="SiteMaintenanceMessage" class="form-control h-100" rows="4">{{ config('Site.maintenance_message') }}</textarea>
                                    <small class="d-block">{{ __('common.maintenance_message_description') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteComingSoon">{{ __('common.coming_soon') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="hidden" name="Site[coming_soon]" value="0">
                                    <input type="checkbox" name="Site[coming_soon]" id="SiteComingSoon" {{ config('Site.coming_soon') == 1 ? 'checked="checked"' : '' }} class="form-check-input" value="1">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteComingSoonMessage">{{ __('common.coming_soon_message') }}</label>
                                <div class="col-sm-6 form-group">
                                    <textarea name="Site[comingsoon_message]" id="SiteComingSoonMessage" class="form-control h-100" rows="4">{{ config('Site.comingsoon_message') }}</textarea>
                                    <small class="d-block">{{ __('common.coming_soon_message_description') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteComingSoonDate">{{ __('common.coming_soon_date') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="date" class=" form-control" name="Site[comingsoon_date]" id="SiteComingSoonDate" value="{{ config('Site.comingsoon_date') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteBiography">{{ __('common.biography') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[biography]" id="SiteBiography" class="form-control" value="{{ config('Site.biography') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteLocation">{{ __('common.location') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[location]" id="SiteLocation" class="form-control" value="{{ config('Site.location') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="SiteOfficeTime">{{ __('common.office_time') }}</label>
                                <div class="col-sm-6 form-group">
                                    <input type="text" name="Site[office_time]" id="SiteOfficeTime" class="form-control" value="{{ config('Site.office_time') }}">
                                    <small class="d-block">{{ __('Ex. : "Time 06:00 AM To 08:00 PM"') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-sm-3"></div>
                                <div class="col-sm-6">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection