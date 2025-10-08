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
					<h4 class="card-title">Preset Theme</h4>
				</div>
				<div class="card-body">
					<div class="basic-form-configuration">
						<form action="{{ route('admin.configurations.admin_settings') }}" method="post">
							@csrf
							<div class="form-group row">
								@for($i = 0; $i < 8; $i++)
								<div class="col-sm-3 form-group">	                                					
									<label class="theme-media">
										<input type="radio" name="admin_layout" class="form-check-input m-0 me-1 AdminLayout" value="{{ $i }}" @checked($i == config('Settings.admin_layout'))>
										<img src="{{ asset('images/admin-themes-layouts/layout'.$i.'.jpg') }}" alt="layout{{ $i }}.jpg" class="w-100 h-100">
									</label>
								</div>
								@endfor
							</div>
							<div class="form-group row">
								<div class="col-sm-6">
									<button type="submit" class="btn btn-primary">Save</button>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('common.change_theme_style') }}</h4>
                </div>
                <div class="card-body">
                    <div class="basic-form-configuration">
                        <form action="{{ route('admin.configurations.admin_settings') }}" method="post">
	                        @csrf
			                <input type="hidden" name="submit_type" value="theme_style">
	                        <div class="row">
	                        	<div class="col-lg-6">
									<div class="form-wrapper">
			                            <h5>{{ __('common.theme') }}</h5>
			                            <hr>
			                            <div class="row ThemeStyle">
			                                <div class="col-md-6 form-group mb-4">                                                      
			                                	<label class="d-block">{{ __('common.backgroud') }}</label>
			                                	<select name="theme_version" class="form-control default-select" id="theme_version">
			                                		<option value="light" @selected($admin_layout_options->version == 'light')>{{ __('common.light') }}</option>
			                                		<option value="dark" @selected($admin_layout_options->version == 'dark')>{{ __('common.dark') }}</option>
			                                	</select>
			                                </div>
			                                <div class="col-md-6 form-group">  </div>                                                   
			                                <div class="col-md-6 form-group">
			                                	<label class="d-block">{{ __('common.primary_color') }}</label>
			                                	@for($i=1; $i <= 15; $i++)
			                                	<span>
												    <input class="chk-col-primary filled-in" id="primary_color_{{ $i }}" name="primary_bg" type="radio" value="color_{{ $i }}" @checked($admin_layout_options->primary == 'color_'.$i)>
												    <label for="primary_color_{{ $i }}" class="bg-label-pattern"></label>
												</span>
												@endfor
			                                </div>
			                                <div class="col-md-6 form-group">
			                                	<label class="d-block">{{ __('common.navigation_header') }}</label>
			                                	@for($i=1; $i <= 15; $i++)
			                                	<span>
												    <input class="chk-col-primary filled-in" id="nav_header_color_{{ $i }}" name="navigation_header" type="radio" value="color_{{ $i }}" @checked($admin_layout_options->navheaderBg == 'color_'.$i)>
												    <label for="nav_header_color_{{ $i }}" class="bg-label-pattern"></label>
												</span>
												@endfor
			                                </div>
			                                <div class="col-md-6 form-group">
			                                	<label class="d-block">{{ __('common.header') }}</label>
			                                	@for($i=1; $i <= 15; $i++)
			                                	<span>
												    <input class="chk-col-primary filled-in" id="header_color_{{ $i }}" name="header_bg" type="radio" value="color_{{ $i }}" @checked($admin_layout_options->headerBg == 'color_'.$i)>
												    <label for="header_color_{{ $i }}" class="bg-label-pattern"></label>
												</span>
												@endfor
			                                </div>
			                                <div class="col-md-6 form-group">
			                                	<label class="d-block">{{ __('common.sidebar') }}</label>
			                                	@for($i=1; $i <= 15; $i++)
			                                	<span>
												    <input class="chk-col-primary filled-in" id="sidebar_color_{{ $i }}" name="sidebar_bg" type="radio" value="color_{{ $i }}" @checked($admin_layout_options->sidebarBg == 'color_'.$i)>
												    <label for="sidebar_color_{{ $i }}" class="bg-label-pattern"></label>
												</span>
												@endfor
			                                </div>
			                            </div>
			                            
		                        	</div>
	                        	</div>
	                        	<div class="col-lg-6">
	                        		<div class="row">
	                        			<div class="col-lg-12">
	                        				<div class="form-wrapper">
					                            <h5>{{ __('common.header') }}</h5>
					                            <hr>
					                            <div class="row">
					                                <div class="col-md-6 form-group mb-3">                                                      
					                                	<label>{{ __('common.layout') }}</label>
					                                	<select name="theme_layout" class="form-control default-select" id="theme_layout">
					                                		<option value="vertical" @selected($admin_layout_options->layout == 'vertical')>{{ __('common.vertical') }}</option>
					                                		<option value="horizontal" @selected($admin_layout_options->layout == 'horizontal')>{{ __('common.horizontal') }}</option>
					                                	</select>
					                                </div>
					                                <div class="col-md-6 form-group mb-3">                                                      
					                                	<label>{{ __('common.header_position') }}</label>
					                                	<select name="header_position" class="form-control default-select" id="header_position">
					                                		<option value="static" @selected($admin_layout_options->headerPosition == 'static')>{{ __('common.static') }}</option>
					                                		<option value="fixed" @selected($admin_layout_options->headerPosition == 'fixed')>{{ __('common.fixed') }}</option>
					                                	</select>
					                                </div>
					                                <div class="col-md-6 form-group mb-3">                                                      
					                                	<label>{{ __('common.sidebar') }}</label>
					                                	<select name="sidebar_style" class="form-control default-select" id="sidebar_style">
					                                		<option value="full" @selected($admin_layout_options->sidebarStyle == 'full')>{{ __('common.full') }}</option>
					                                		<option value="mini" @selected($admin_layout_options->sidebarStyle == 'mini')>{{ __('common.mini') }}</option>
					                                		<option value="compact" @selected($admin_layout_options->sidebarStyle == 'compact')>{{ __('common.compact') }}</option>
					                                		<option value="modern" @selected($admin_layout_options->sidebarStyle == 'modern')>{{ __('common.modern') }}</option>
					                                		<option value="overlay" @selected($admin_layout_options->sidebarStyle == 'overlay')>{{ __('common.overlay') }}</option>
					                                		<option value="icon-hover" @selected($admin_layout_options->sidebarStyle == 'icon-hover')>{{ __('common.icon-hover') }}</option>
					                                	</select>
					                                </div>
					                                <div class="col-md-6 form-group mb-3">                                                      
					                                	<label>{{ __('common.sidebar_position') }}</label>
					                                	<select name="sidebar_position" class="form-control default-select" id="sidebar_position">
					                                		<option value="static" @selected($admin_layout_options->sidebarPosition == 'static')>{{ __('common.static') }}</option>
					                                		<option value="fixed" @selected($admin_layout_options->sidebarPosition == 'fixed')>{{ __('common.fixed') }}</option>
					                                	</select>
					                                </div>
					                            </div>
					                        </div>
	                        			</div>
	                        			<div class="col-lg-12">
	                        				<div class="form-wrapper">
					                            <h5>{{ __('common.content') }}</h5>
					                            <hr>
					                            <div class="row">
					                                <div class="col-md-6 form-group">                                                      
					                                	<label>{{ __('common.container') }}</label>
					                                	<select name="container_layout" class="form-control default-select" id="container_layout">
					                                		<option value="wide" @selected($admin_layout_options->containerLayout == 'wide')>{{ __('common.wide') }}</option>
					                                		<option value="boxed" @selected($admin_layout_options->containerLayout == 'boxed')>{{ __('common.boxed') }}</option>
					                                		<option value="wide-boxed" @selected($admin_layout_options->containerLayout == 'wide-boxed')>{{ __('common.wide_boxed') }}</option>
					                                	</select>
					                                </div>
					                                <div class="col-md-6 form-group">                                                      
					                                	<label>{{ __('common.direction') }}</label>
					                                	<select name="theme_direction" class="form-control default-select" id="theme_direction">
					                                		<option value="ltr" @selected($admin_layout_options->direction == 'ltr')>{{ __('common.ltr') }}</option>
					                                		<option value="rtl" @selected($admin_layout_options->direction == 'rtl')>{{ __('common.rtl') }}</option>
					                                	</select>
					                                </div>
					                                <div class="col-md-6 form-group">                                                      
					                                	<label>{{ __('common.body_font') }}</label>
					                                	<select name="typography" class="form-control default-select" id="typography">
					                                		<option value="roboto" @selected($admin_layout_options->typography == 'roboto')>{{ __('common.roboto') }}</option>
					                                		<option value="poppins" @selected($admin_layout_options->typography == 'poppins')>{{ __('common.poppins') }}</option>
					                                		<option value="opensans" @selected($admin_layout_options->typography == 'opensans')>{{ __('common.opensans') }}</option>
					                                		<option value="HelveticaNeue" @selected($admin_layout_options->typography == 'HelveticaNeue')>{{ __('common.helveticaneue') }}</option>
					                                	</select>
					                                </div>
					                            </div>
					                        </div>

	                        			</div>
	                        		</div>
	                        	</div>
	                        </div>

                            <div class="form-group row">
                                <div class="col-sm-6">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
		<div class="col-xl-12">
			<div class="card">
				<div class="card-header">
					<h4 class="card-title">{{ __('common.settings') }}</h4>
				</div>
				<div class="card-body">
					<div class="basic-form">
						<form action="{{ route('admin.configurations.admin_settings') }}" method="post">
							@csrf
							<div class="form-group row">
								<div class="col-sm-12 form-group">                                                      
									<div class="form-check">
										<input type="checkbox" class="form-check-input" name="storage_link" id="storage_link">
										<label class="form-check-label" for="storage_link">{{ __('common.storage_link_description') }}</label>
									</div>
																																		
									<div class="form-check">
										<input type="checkbox" class="form-check-input" name="clear_cache" id="clear_cache">
										<label class="form-check-label" for="clear_cache">{{ __('common.clear_cache') }}</label>
									</div>
								</div>
							</div>
							<div class="form-group row ">
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