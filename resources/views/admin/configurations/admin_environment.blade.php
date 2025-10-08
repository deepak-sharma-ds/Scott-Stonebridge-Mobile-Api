{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4>Configuration</h4>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.configurations.admin_index') }}">Configuration</a></li>
                <li class="breadcrumb-item active"><a href="javascript:void(0)">{{ __('common.view') }}</a></li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('common.environment_configuration') }}</h4>
                </div>
                <div class="card-body">
                    <!-- Nav tabs -->
                    <div class="custom-tab-1">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item mr-2">
                                <a class="nav-link active" data-bs-toggle="tab" href="#general">{{ __('common.general') }}</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#broadcast">{{ __('common.broadcast') }}</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#redis_driver">{{ __('common.redis_driver') }}</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#mail">{{ __('common.mail') }}</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#aws">{{ __('common.aws') }}</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#pusher">{{ __('common.pusher') }}</a>
                            </li>
                        </ul>
                        <form action="{{ route('admin.configurations.save_config', $prefix) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                            <div class="tab-content">
                                <div class="tab-pane fade active show" id="general" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group ">
                                                    <label for="app_name">{{ __('common.app_name') }}</label>
                                                    <input type="text" class="form-control" name="app_name" id="app_name" value="{{ old('app_name', config('app.name')) }}" placeholder="{{ __('common.app_name') }}">
                                                    @if ($errors->has('app_name'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('app_name') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="environment">{{ __('common.app_environment') }}</label>
                                                    <select name="environment" class="form-control default-select" id="environment">
                                                        <option value="development" {{ old('environment', config('app.env')) == 'development' ? 'selected' : '' }}>{{ __('common.development') }}</option>
                                                        <option value="local" {{ old('environment', config('app.env')) == 'local' ? 'selected' : '' }}>{{ __('common.local') }}</option>
                                                        <option value="qa" {{ old('environment', config('app.env')) == 'qa' ? 'selected' : '' }}>{{ __('common.qa') }}</option>
                                                        <option value="production" {{ old('environment', config('app.env')) == 'production' ? 'selected' : '' }}>{{ __('common.production') }}</option>
                                                        <option value="other" {{ old('environment', config('app.env')) == 'other' ? 'selected' : '' }}>{{ __('common.other') }}</option>
                                                    </select>
                                                    @if ($errors->has('environment'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('environment') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="app_debug">{{ __('common.app_debug') }}</label>
                                                    <div class="form-check">
                                                        <input type="radio" class="form-check-input" name="app_debug" id="app_debug_true" value="true" {{ old('app_debug', config('app.debug')) == true ? 'checked' : '' }}>
                                                        <label for="app_debug_true" class="form-check-label">{{ __('common.true') }}</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input type="radio" class="form-check-input" name="app_debug" id="app_debug_false" value="false" {{ old('app_debug', config('app.debug')) == false ? 'checked' : '' }}>
                                                        <label for="app_debug_false" class="form-check-label">{{ __('common.false') }}</label>
                                                    </div>
                                                    @if ($errors->has('app_debug'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('app_debug') }}
                                                        </span>
                                                    @endif
                                                </div>  
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="app_log_level">{{ __('common.app_log_level') }}</label>
                                                    <select name="app_log_level" class="form-control default-select" id="app_log_level">
                                                        <option value="debug" {{ old('app_log_level', env('LOG_LEVEL')) == 'debug' ? 'selected' : '' }}>{{ __('common.debug') }}</option>
                                                        <option value="info" {{ old('app_log_level', env('LOG_LEVEL')) == 'info' ? 'selected' : '' }}>{{ __('common.Info') }}</option>
                                                        <option value="notice" {{ old('app_log_level', env('LOG_LEVEL')) == 'notice' ? 'selected' : '' }}>{{ __('common.Notice') }}</option>
                                                        <option value="warning" {{ old('app_log_level', env('LOG_LEVEL')) == 'warning' ? 'selected' : '' }}>{{ __('common.Warning') }}</option>
                                                        <option value="error" {{ old('app_log_level', env('LOG_LEVEL')) == 'error' ? 'selected' : '' }}>{{ __('common.Error') }}</option>
                                                        <option value="critical" {{ old('app_log_level', env('LOG_LEVEL')) == 'critical' ? 'selected' : '' }}>{{ __('common.Critical') }}</option>
                                                        <option value="alert" {{ old('app_log_level', env('LOG_LEVEL')) == 'alert' ? 'selected' : '' }}>{{ __('common.Alert') }}</option>
                                                        <option value="emergency" {{ old('app_log_level', env('LOG_LEVEL')) == 'emergency' ? 'selected' : '' }}>{{ __('common.Emergency') }}</option>
                                                    </select>
                                                    @if ($errors->has('app_log_level'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('app_log_level') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="app_url">{{ __('common.app_url') }}</label>
                                                    <input type="url" name="app_url"  class="form-control" id="app_url" value="{{ old('app_url', DzHelper::GetBaseUrl() ) }}" placeholder="{{ __('common.app_url') }}">
                                                    @if ($errors->has('app_url'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('app_url') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="asset_url">{{ __('common.asset_url') }}</label>
                                                    <input type="url" name="asset_url" class="form-control" id="asset_url" value="{{ old('app_url', DzHelper::GetBaseUrl('AssetUrl')) }}" placeholder="{{ __('common.asset_url') }}">
                                                    @if ($errors->has('asset_url'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('asset_url') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
								<div class="tab-pane fade" id="broadcast" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="mb-3 col-md-6">
                                                <div class="form-group ">
                                                    <label for="broadcast_driver">{{ __('common.broadcast_driver') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/broadcasting" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="broadcast_driver" id="broadcast_driver" value="{{ old('broadcast_driver', 'log') }}" placeholder="{{ __('common.broadcast_driver') }}">
                                                    @if ($errors->has('broadcast_driver'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('broadcast_driver') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="cache_driver">{{ __('common.cache_driver') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/cache" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="cache_driver" id="cache_driver" value="{{ old('cache_driver', 'file') }}" placeholder="{{ __('common.cache_driver') }}">
                                                    @if ($errors->has('cache_driver'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('cache_driver') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="filesystem_driver">{{ __('common.filesystem_driver_driver') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/cache" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="filesystem_driver" id="filesystem_driver" value="{{ old('filesystem_driver', 'local') }}" placeholder="{{ __('common.filesystem_driver_driver') }}">
                                                     @if ($errors->has('filesystem_driver'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('filesystem_driver') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="queue_connection">{{ __('common.queue_connection') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/queues" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="queue_connection" id="queue_connection" value="{{ old('queue_connection', 'sync') }}" placeholder="{{ __('common.queue_connection') }}">
                                                    @if ($errors->has('queue_connection'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('queue_connection') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="session_driver">{{ __('session_driver') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/session" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="session_driver" id="session_driver" value="{{ old('session_driver', 'file') }}" placeholder="{{ __('session_driver') }}">
                                                    @if ($errors->has('session_driver'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('session_driver') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="redis_driver" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="mb-3 col-md-6">
                                                <div class="form-group ">
                                                    <label for="redis_hostname">
                                                        {{ __('common.redis_host') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/redis" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="redis_hostname" id="redis_hostname" value="{{ old('redis_hostname', '127.0.0.1') }}" placeholder="{{ __('common.redis_host') }}">
                                                    @if ($errors->has('redis_hostname'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('redis_hostname') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="redis_password">{{ __('common.redis_password') }}</label>
                                                    <input type="password" class="form-control" name="redis_password" id="redis_password" value="{{ old('redis_password', 'null') }}" placeholder="{{ __('common.redis_password') }}">
                                                    @if ($errors->has('redis_password'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('redis_password') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group ">
                                                    <label for="redis_port">{{ __('common.redis_port') }}</label>
                                                    <input type="number" class="form-control" name="redis_port" id="redis_port" value="{{ old('redis_port', '6379') }}" placeholder="{{ __('common.redis_port') }}">
                                                    @if ($errors->has('redis_port'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('redis_port') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="mail" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="mb-3 col-md-6">
                                                <div class="form-group ">
                                                    <label for="mail_driver">
                                                        {{ __('common.mail_driver') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/mail" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="mail_driver" id="mail_driver" value="{{ old('mail_driver', 'smtp') }}" placeholder="{{ __('common.mail_driver') }}">
                                                    @if ($errors->has('mail_driver'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_driver') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="mail_host">{{ __('common.mail_host') }}</label>
                                                    <input type="text" class="form-control" name="mail_host" id="mail_host" value="{{ old('mail_host', 'smtp.mailtrap.io') }}" placeholder="{{ __('common.mail_host') }}">
                                                    @if ($errors->has('mail_host'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_host') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="mail_port">{{ __('common.mail_port') }}</label>
                                                    <input type="number" class="form-control" name="mail_port" id="mail_port" value="{{ old('mail_port', '25') }}" placeholder="{{ __('common.mail_port') }}">
                                                    @if ($errors->has('mail_port'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_port') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="mail_username">{{ __('common.mail_username') }}</label>
                                                    <input type="text" class="form-control" name="mail_username" id="mail_username" value="{{ old('mail_username', 'null') }}" placeholder="{{ __('common.mail_username') }}">
                                                    @if ($errors->has('mail_username'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_username') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="mail_password">{{ __('common.mail_password') }}</label>
                                                    <input type="text" class="form-control" name="mail_password" id="mail_password" value="{{ old('mail_password', 'null') }}" placeholder="{{ __('common.mail_password') }}">
                                                    @if ($errors->has('mail_password'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_password') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="mail_encryption">{{ __('common.mail_encryption') }}</label>
                                                    <input type="text" class="form-control" name="mail_encryption" id="mail_encryption" value="{{ old('mail_encryption', 'null') }}" placeholder="{{ __('common.mail_encryption') }}">
                                                     @if ($errors->has('mail_encryption'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('mail_encryption') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="aws" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="mb-3 col-md-6">
                                                <div class="form-group ">
                                                    <label for="aws_access_key">
                                                        {{ __('common.aws_access_key_id') }}
                                                        <sup>
                                                            <a href="https://laravel.com/docs/5.4/mail" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="aws_access_key" id="aws_access_key" value="{{ old('aws_access_key') }}" placeholder="{{ __('common.aws_access_key_id') }}">
                                                    @if ($errors->has('aws_access_key'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('aws_access_key') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="aws_secret_key">{{ __('common.aws_access_key') }}</label>
                                                    <input type="text" class="form-control" name="aws_secret_key" id="aws_secret_key" value="{{ old('aws_secret_key') }}" placeholder="{{ __('common.aws_access_key') }}">
                                                     @if ($errors->has('aws_secret_key'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('aws_secret_key') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="aws_default_region">{{ __('common.aws_default_region') }}</label>
                                                    <input type="text" class="form-control" name="aws_default_region" id="aws_default_region" value="{{ old('aws_default_region', 'us-east-1') }}" placeholder="{{ __('common.aws_default_region') }}">
                                                    @if ($errors->has('aws_default_region'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('aws_default_region') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="aws_bucket">{{ __('common.aws_bucket') }}</label>
                                                    <input type="text" class="form-control" name="aws_bucket" id="aws_bucket" value="{{ old('aws_bucket') }}" placeholder="{{ __('common.aws_bucket') }}">
                                                     @if ($errors->has('aws_bucket'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('aws_bucket') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="aws_endpoint">{{ __('common.aws_use_path_style_endpoint') }}</label>
                                                    <input type="text" class="form-control" name="aws_endpoint" id="aws_endpoint" value="{{ old('aws_endpoint', 'false') }}" placeholder="{{ __('common.aws_use_path_style_endpoint') }}">
                                                    @if ($errors->has('aws_endpoint'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('aws_endpoint') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="pusher" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="mb-3 col-md-6">
                                                <div class="form-group ">
                                                    <label for="pusher_app_id">
                                                        {{ __('common.pusher_app_id') }}
                                                        <sup>
                                                            <a href="https://pusher.com/docs/server_api_guide" target="_blank" title="{{ __('common.more_info') }}">
                                                                <i class="fa fa-info-circle fa-fw" aria-hidden="true"></i>
                                                                <span class="sr-only">{{ __('common.more_info') }}</span>
                                                            </a>
                                                        </sup>
                                                    </label>
                                                    <input type="text" class="form-control" name="pusher_app_id" id="pusher_app_id" value="{{ old('pusher_app_id') }}" placeholder="{{ __('common.pusher_app_id') }}">
                                                    @if ($errors->has('pusher_app_id'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('pusher_app_id') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="pusher_app_key">{{ __('common.pusher_app_key') }}</label>
                                                    <input type="text" class="form-control" name="pusher_app_key" id="pusher_app_key" value="{{ old('pusher_app_key') }}" placeholder="{{ __('common.pusher_app_key') }}">
                                                    @if ($errors->has('pusher_app_key'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('pusher_app_key') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="form-group ">
                                                    <label for="pusher_app_secret">{{ __('common.pusher_app_secret') }}</label>
                                                    <input type="password" class="form-control" name="pusher_app_secret" id="pusher_app_secret" value="{{ old('pusher_app_secret') }}" placeholder="{{ __('common.pusher_app_secret') }}">
                                                    @if ($errors->has('pusher_app_secret'))
                                                        <span class="error-block">
                                                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                                                            {{ $errors->first('pusher_app_secret') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-12 d-flex justify-content-start align-items-center">
                                        <button type="submit" class="btn btn-primary">Save</button>
                                        <p class="ms-2 m-0"><strong>{{ __('common.note') }}: </strong>{{ __('common.logout_after_save_changes') }}</p>
                                    </div>
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