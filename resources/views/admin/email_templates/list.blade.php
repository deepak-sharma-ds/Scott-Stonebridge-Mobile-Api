@extends('admin.layouts.app')

@section('content')
    <style>
        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }
    </style>
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center" style="width: -webkit-fill-available;">
                <h4>Email Templates</h4>
                <a href="{{ route('admin.email-templates.create') }}" class="btn btn-primary">Add Template</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sr. No.</th>
                            <th>Key</th>
                            <th>Subject</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $i=1; @endphp
                        @foreach ($template as $key => $value)
                            <tr>
                                <td>{{ $i }}</td>
                                <td>{{ $value['identifier'] }}</td>
                                <td>{{ $value['subject'] }}</td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center">
                                        {{-- <a href=""><i class="fa fa-eye"></i></a> --}}
                                        <a href="{{ route('admin.email-templates.edit', ['key' => $value['identifier']]) }}"
                                            class="btn btn-primary"><i class="fa fa-edit"></i></a>
                                        <form method="POST" action="{{ route('admin.email-templates.delete') }}" class="email-template-delete-form">
                                            @csrf
                                            <input type="hidden" value="{{ $value['identifier'] }}" name="identifier">
                                            <button type="submit" class="btn btn-danger">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @php $i++; @endphp
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
@section('custom_js_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteForms = document.querySelectorAll('form.email-template-delete-form');

            deleteForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevent actual submission

                    Swal.fire({
                        title: 'Are you sure?',
                        text: "You won't be able to revert this!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Yes, delete it!'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit(); // Submit the form if confirmed
                        }
                    });
                });
            });
        });
    </script>
@endsection
