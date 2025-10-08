@extends('admin.layouts.app')

@section('content')
    <style>
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive :is(td, th) {
            white-space: nowrap;
        }
    </style>
    <div class="container">
        @if ($errors->has('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ $errors->first('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Availability Slots</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Availability Slots List</li>
                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Availability Slots</strong>
                        <a href="{{ route('admin.availability.create') }}" class="btn btn-primary">Add</a>
                    </div>
                    <div class="pe-4 ps-4 pt-2 pb-2">
                        <div class="table-responsive" style="overflow-x:auto;">
                            <table class="table table-bordered table-hover table-striped align-middle mb-0">
                                <thead class="text-secondary">
                                    <tr>
                                        <th>S.No</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($availability_dates as $index => $availabilityDate)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $availabilityDate->date->format('F j, Y') }}</td>  
                                            <td>
                                                <a href="{{ route('admin.availability.edit', $availabilityDate->id) }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i></a>

                                                <!-- Delete form with confirmation -->
                                                <form action="{{ route('admin.availability.delete-date', $availabilityDate->id) }}" method="post" style="display:inline;" class="delete-form">
                                                    @csrf
                                                    <button type="button" class="btn btn-danger btn-sm delete-btn" data-id="{{ $availabilityDate->id }}">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center">No availability slots available.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-1">
            {!! $availability_dates->appends(request()->query())->links('pagination::bootstrap-5') !!}
        </div>
    </div>

    <script>
        // Add event listener to delete buttons
        const deleteButtons = document.querySelectorAll('.delete-btn');
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const availabilityId = this.getAttribute('data-id');
                const form = this.closest('form');
                // Show confirmation dialog
                const confirmation = confirm('Are you sure you want to delete this availability slot?');
                
                if (confirmation) {
                    console.log('ok')
                    form.submit();
                }
            });
        });
    </script>
@endsection
