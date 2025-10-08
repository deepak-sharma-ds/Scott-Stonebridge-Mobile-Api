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
                        <form action="{{ route('admin.configurations.save_permalink', $prefix) }}" method="post" enctype="multipart/form-data">
                        @csrf
							<div class="form-group mb-3">
								<div class="radio">
									<label><input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="Plain" value="" {{ empty($configuration->value) ? 'checked="checked"' : '' }}> {{ __('common.plain') }}</label>
									<code> {{ url('/') }}/?p=123</code>
								</div>
								<div class="radio">
									<label><input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="DayName" value="/%year%/%month%/%day%/%slug%/" {{ ($configuration->value == '/%year%/%month%/%day%/%slug%/') ? 'checked="checked"' : '' }}> {{ __('common.day_and_name') }}</label>
									<code> {{ url('/') }}/{{ date("Y") }}/{{ date("m") }}/{{ date("d") }}/sample-post/</code>
								</div>
								<div class="radio">
									<label><input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="MonthName" value="/%year%/%month%/%slug%/" {{ ($configuration->value == '/%year%/%month%/%slug%/') ? 'checked="checked"' : '' }}> {{ __('common.month_and_name') }}</label>
									<code> {{ url('/') }}/{{ date("Y") }}/{{ date("m") }}/sample-post/</code>
								</div>
								<div class="radio">
									<label><input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="Numeric" value="/archives/%post_id%" {{ ($configuration->value == '/archives/%post_id%') ? 'checked="checked"' : '' }}> {{ __('common.numeric') }}</label>
									<code> {{ url('/') }}/archives/123</code>
								</div>
								<div class="radio">
									<label><input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="PostName" value="/%slug%/" {{ ($configuration->value == '/%slug%/') ? 'checked="checked"' : '' }}> {{ __('common.post_name') }}</label>
									<code> {{ url('/') }}/sample-post/</code>
								</div>
								<div class="radio">
									<label>
										<input type="radio" name="permalink_selection" class="permalink_selection form-check-input m-0 me-1" id="CustomeStructure" value="custom" {{ (!empty($configuration->value) && !in_array($configuration->value, $routesType)) ? 'checked="checked"' : '' }}> {{ __('common.custom_structure') }}
									</label>
									<code> {{ url('/') }} </code> <input name="permalink_structure" id="permalink_structure" type="text" value="{{ $configuration->value }}" class="form-control col-md-6">
								</div>
							</div>
							<ul role="list">
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%year%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%year%" data-label="{{ __('common.permalink_year_description') }}">
									%{{ __('common.year') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%month%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%month%" data-label="{{ __('common.permalink_month_description') }}">
									%{{ __('common.month') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%day%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%day%" data-label="{{ __('common.permalink_day_description') }}">
									%{{ __('common.day') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%hour%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%hour%" data-label="{{ __('common.permalink_hour_description') }}">
									%{{ __('common.hour') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%minute%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%minute%" data-label="{{ __('common.permalink_minute_description') }}">
									%{{ __('common.minute') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%second%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%second%" data-label="{{ __('common.permalink_second_description') }}">
									%{{ __('common.second') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%post_id%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%post_id%" data-label="{{ __('common.permalink_post_id_description') }}">
									%{{ __('common.post_id') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%slug%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%slug%" data-label="{{ __('common.permalink_postname_description') }}">
									%{{ __('common.slug') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%category%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%category%" data-label="{{ __('common.permalink_category_description') }}">
									%{{ __('common.category') }}%</button>
								</li>
								<li class="d-inline-block mb-1">
									@php
										$activeClass = (strpos($configuration->value, '%author%') > -1) ? 'active' : '';
									@endphp
									<button type="button" class="btn btn-sm btn-outline-dark permas_type {{ $activeClass }}" value="%author%" data-label="{{ __('common.permalink_author_description') }}">
									%{{ __('common.author') }}%</button>
								</li>
							</ul>
							<button type="submit" class="btn btn-primary mt-3 py-2">Save</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection