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
                    <li class="breadcrumb-item active">User List</li>
                </ol>
            </div>
        </div>
      
        <div class="row mb-5">
            <!-- Column starts -->
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Search</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('users.index') }}" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                                    placeholder="Filter by name or email">
                            </div>

                            <div class="col-md-2">
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="1" {{ request('status') == '1' ? 'selected' : '' }}>Active</option>
                                    <option value="0" {{ request('status') == '0' ? 'selected' : '' }}>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <select name="role" class="form-select">
                                    <option value="">All Roles</option>
                                    @foreach ($roles as $role)
                                        <option value="{{ $role }}" {{ request('role') == $role ? 'selected' : '' }}>
                                            {{ $role }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-1 d-grid">
                                <button type="submit" class="btn btn-primary">Filter</button>
                            </div>

                            <div class="col-md-1 d-grid ms-2">
                                <a href="{{ route('users.index') }}" class="btn btn-secondary">Clear</a>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

        </div>

        <div class="row">
            <!-- Column starts -->
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">User</h4>
                        <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fa fa-plus"></i> Create New User </a>
                    </div>
                    <div class="pe-4 ps-4 pt-2 pb-2">
                        <div class="table-responsive">
                            <table class="table table-responsive-lg mb-0">
                                <tr>
                                    <th>S.No</th>
                                    <th width="200px">Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Roles</th>
                                    <th width="280px">Action</th>
                                </tr>
                                @forelse ($data as $key => $user)
                                    <tr>
                                        <td>{{ ++$i }}</td>
                                        <td>{{ $user->name }}</td>
                                        <td>{{ $user->email }}</td>
                                        <td>
                                            @if ($user->status == 1)
                                                <span class="badge bg-success">Active</span>
                                            @else
                                                <span class="badge bg-danger">Inactive</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if (!empty($user->getRoleNames()))
                                                @foreach ($user->getRoleNames() as $v)
                                                    <label class="badge bg-info">{{ $v }}</label>
                                                @endforeach
                                            @endif
                                        </td>
                                        <td>

                                            <a class="btn btn-primary btn-sm" href="{{ route('users.edit', $user->id) }}"><i
                                                    class="fa-solid fa-pen-to-square" style="width: 100px"></i> </a>

                                            <form method="POST" action="{{ route('users.destroy', $user->id) }}"
                                                style="display:inline" class="delete-confirm">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" class="btn btn-danger btn-sm delete-confirm ms-2"
                                                    data-title="Are You Sure?" data-text="You won't be able to restore it!"
                                                    data-confirm-button="Yes, delete it!" data-cancel-button="Cancel"><i
                                                        class="fa-solid fa-trash"></i></button>
                                            </form>

                                        </td>
                                    </tr>
                                        @empty
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <p>Records Not Found</p>
                                            </td>
                                        </tr>
                                    @endforelse
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <div class="mt-1">
        {!! $data->links('pagination::bootstrap-5') !!}
    </div>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                attachDeleteConfirm('.delete-confirm', {});
            });
        </script>
    @endsection
@endrole
