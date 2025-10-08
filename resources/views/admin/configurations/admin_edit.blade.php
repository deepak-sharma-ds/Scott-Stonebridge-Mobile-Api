{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4>Configuration</h4>
                <span>Edit</span>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.configurations.admin_index') }}">Configuration</a></li>
                <li class="breadcrumb-item active"><a href="javascript:void(0)">Edit</a></li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Edit</h4>
                </div>
                <div class="card-body">
                    <!-- Nav tabs -->
                    <div class="default-tab">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item mr-2">
                                <a class="nav-link active" data-bs-toggle="tab" href="#setting">Setting</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#misc">Misc</a>
                            </li>
                        </ul>
                        <form action="{{ route('admin.configurations.admin_edit', $configuration->id) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                            <div class="tab-content">
                                <div class="tab-pane fade active show" id="setting" role="tabpanel">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label for="ConfigurationName">Name</label>
                                                <input type="text" name="Configuration[name]" id="ConfigurationName" class="form-control" maxlength="64" value="{{ old('Configuration[name]', $configuration->name) }}">
                                                <small>Config Title</small>
                                                @error('Configuration.name')
                                                    <p class="text-danger">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label for="ConfigurationValue">Value</label>
                                                <textarea name="Configuration[value]" id="ConfigurationValue" class="form-control" cols="30" rows="6">{{ old('Configuration[value]', $configuration->value) }}</textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="misc">
                                    <div class="pt-4">
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label for="title">Title</label>
                                                <input type="text" name="Configuration[title]" id="title" class="form-control" maxlength="255" value="{{ old('Configuration[title]', $configuration->title) }}">
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label for="ConfigurationInputType">Input Type</label>
                                                <select name="Configuration[input_type]" id="ConfigurationInputType" class="form-control default-select">
                                                    <option value="">Select Input Type</option>
                                                    <option value="text" {{ (old('Configuration[input_type]', $configuration->input_type) == 'text') ? 'selected="selected"':'' }}>Text</option>
                                                    <option value="textarea" {{ (old('Configuration[input_type]', $configuration->input_type) == 'textarea') ? 'selected="selected"':'' }}>Textarea</option>
                                                    <option value="file" {{ (old('Configuration[input_type]', $configuration->input_type) == 'file') ? 'selected="selected"':'' }}>File</option>
                                                    <option value="multiple_file" {{ (old('Configuration[input_type]', $configuration->input_type) == 'multiple_file') ? 'selected="selected"':'' }}>Multiple File</option>
                                                    <option value="checkbox" {{ (old('Configuration[input_type]', $configuration->input_type) == 'checkbox') ? 'selected="selected"':'' }}>Checkbox</option>
                                                    <option value="multiple_checkbox" {{ (old('Configuration[input_type]', $configuration->input_type) == 'multiple_checkbox') ? 'selected="selected"':'' }}>Multiple Checkbox</option>
                                                    <option value="radio" {{ (old('Configuration[input_type]', $configuration->input_type) == 'radio') ? 'selected="selected"':'' }}>Radio</option>
                                                    <option value="button" {{ (old('Configuration[input_type]', $configuration->input_type) == 'button') ? 'selected="selected"':'' }}>Button</option>
                                                    <option value="select" {{ (old('Configuration[input_type]', $configuration->input_type) == 'select') ? 'selected="selected"':'' }}>Select</option>
                                                    <option value="date" {{ (old('Configuration[input_type]', $configuration->input_type) == 'date') ? 'selected="selected"':'' }}>Date</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label for="ConfigurationDescription">Description</label>
                                                <textarea name="Configuration[description]" id="ConfigurationDescription" class="form-control">{{ old('Configuration[description]', $configuration->description) }}</textarea>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label for="ConfigurationParams">Params</label>
                                                <textarea name="Configuration[params]" id="ConfigurationParams" class="form-control">{{ old('Configuration[params]', $configuration->params) }}</textarea>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" name="Configuration[editable]" id="ConfigurationEditable" class="custom-control-input form-check-input" {{ (old('Configuration[editable]', $configuration->editable) == 1) ? 'checked="checked"':'' }}>
                                                    <label class="custom-control-label form-check-label" for="ConfigurationEditable">Editable</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-12 text-right">
                                        <button type="submit" class="btn btn-primary">Save</button>
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