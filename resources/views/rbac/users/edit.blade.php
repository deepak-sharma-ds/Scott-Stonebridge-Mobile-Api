@extends('admin.layouts.app')
@role('Admin')
    @section('content')
        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>User</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('users.index') }}">User</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </div>
        </div>


        @if (count($errors) > 0)
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif



        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Edit</h4>
                    </div>
                    <div class="card-body">
                        <!-- Nav tabs -->
                        <div class="default-tab">

                            <form method="POST" action="{{ route('users.update', $user->id) }}" enctype="multipart/form-data" id="edit_form">
                                @csrf
                                @method('PUT')

                                <div class="row">
                                    <div class="col-xs-12 col-sm-12 col-md-6">
                                        <div class="form-group">
                                            <strong>Name:</strong>
                                            <input type="text" name="name" placeholder="Name" class="form-control"
                                                value="{{ old('name', $user->name) }}" required>
                                        </div>
                                    </div>
                                    <div class="col-xs-12 col-sm-12 col-md-6">
                                        <div class="form-group">
                                            <strong>Email:</strong>
                                            <input type="email" name="email" placeholder="Email" class="form-control"
                                                value="{{ old('email', $user->email) }}" required>
                                        </div>
                                    </div>
                                    <div class="col-xs-12 col-sm-12 col-md-6">
                                        <div class="form-group">
                                            <strong>Role:</strong>
                                            <select name="roles[]" class="form-control" required>
                                                <option value="">Select Role</option>
                                                @foreach ($roles as $value => $label)
                                                    <option value="{{ $value }}"
                                                        {{ in_array($value, old('roles', array_keys($userRole))) ? 'selected' : '' }}>
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @if ($prescriber && $prescriber->gphc_number)
                                        <div class="col-xs-12 col-sm-12 col-md-6">
                                            <div class="form-group">
                                                <strong>GPhC Number</strong>
                                                <input type="text" name="gphc_number" id="gphc_number"
                                                    placeholder="GPhC Number" class="form-control"
                                                    value="{{ $prescriber->gphc_number }}" required>

                                            </div>
                                        </div>
                                    @endif
                                       @if ($prescriber && $prescriber->registration_number)
                                        <div class="col-xs-12 col-sm-12 col-md-6">
                                            <div class="form-group">
                                                <strong>Registration Number</strong>
                                                <input type="text" name="registration_number" id="reg_number"
                                                    placeholder="Registration Number" class="form-control"
                                                    value="{{ $prescriber->registration_number }}" required>

                                            </div>
                                        </div>
                                    @endif
                                    @if ($prescriber && $prescriber->signature_image)
                                        <div class="col-xs-12 col-sm-12 col-md-6">
                                            <div class="form-group">
                                                <strong>Signature</strong>
                                                <input type="file" name="signature" id="signature" placeholder="Signature"
                                                    class="form-control">

                                                <div class="current-signature mt-3">
                                                    <p>Current Signature:</p>
                                                    @php 
                                                    $filePath = "signature-images/{$prescriber->signature_image}";
		                                            $imageUrl = rtrim(config('app.url'), '/') . '/' . ltrim(Storage::url($filePath), '/');
                                                    @endphp 
                                                    <img src="{{ $imageUrl ?? '' }}"
                                                        alt="Current Signature"
                                                        style="max-height: 150px; border: 1px solid #ddd;">
                                                </div>

                                            </div>
                                        </div>
                                    @endif
                                   
                                  
                                    <div class="col-xs-12 col-sm-12 col-md-6">
                                        <div class="form-group">
                                            <strong>Status:</strong>
                                            <select name="status" class="form-control" required>
                                                <option value="1"
                                                    {{ old('status', $user->status) == '1' ? 'selected' : '' }}>Active</option>
                                                <option value="0"
                                                    {{ old('status', $user->status) == '0' ? 'selected' : '' }}>Inactive
                                                </option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xs-12 col-sm-12 col-md-12 text-center">
                                        <button type="submit" class="btn btn-primary btn-md mt-2 mb-3">
                                            Update
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endsection
    @section('custom_js_scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.21.0/jquery.validate.min.js"
            integrity="sha512-KFHXdr2oObHKI9w4Hv1XPKc898mE4kgYx58oqsc/JqqdLMDI4YjOLzom+EMlW8HFUd0QfjfAvxSL6sEq/a42fQ=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script>
            jQuery('#edit_form').validate({
                rules: {},
                ignore: [],
            });
        </script>
    @endsection
@endrole
