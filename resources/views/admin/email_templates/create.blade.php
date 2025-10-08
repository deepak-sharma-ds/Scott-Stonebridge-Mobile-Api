@extends('admin.layouts.app')

@section('content')
    <style>
        .ck .ck-powered-by {
            display: none;
        }
        label{
            font-weight: 600;
        }   
        /* #identifier{
            background: #c7c7c7;
        }
        #identifier:focus{
            background: #c7c7c7;
        } */
    </style>    
    <div class="card">
        <div class="card-header">
            <h3>{{ !empty($template) ? 'Edit' : 'Create' }} Email Template</h3>
        </div>
        <div class="card-body">
            {{-- <form method="POST" action="{{ route('admin.email-templates.store') }}"> --}}
              
               <form method="POST" action="{{ !empty($template) ? route('admin.email-templates.update',['key' => $template['identifier']]) : route('admin.email-templates.store') }}">
                @csrf
                <div>
                    <label>Key</label>
                    {{-- <input type="text" name="identifier" class="form-control" value="{{ $template['identifier'] ?? '' }}" required> --}}
                    <input type="text" name="identifier" class="form-control" value="{{ old('identifier', $template['identifier'] ?? '') }}" required>
                    @error('identifier') <span class="text-danger">{{ $message }}</span> @enderror
                </div><br>
                <div>
                    <label>Subject</label>
                    {{-- <input type="text" name="subject" class="form-control" value="{{ $template['subject'] ?? '' }}" required> --}}
                    <input type="text" name="subject" class="form-control" value="{{ old('subject', $template['subject'] ?? '') }}" required>
                    @error('subject') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
                <br>
                <div>   
                    <label>Body</label>
                    <textarea name="body" id="editor" class="form-control" rows="10">{!! old('body',$template['body'] ?? '') !!}</textarea>
                     @error('body') <span class="text-danger">{{ $message }}</span> @enderror
                    <br>
                    <p style="font-weight: 600">Available Shortcodes:
                        <code>{name}</code>,
                        <code>{email}</code>,
                        <code>{gphc_number}</code>,
                        <code>{signature_image}</code>,
                        <code>{role}</code>
                    </p>
                </div>

                <button type="submit" class="btn btn-primary mt-2">Save</button>
            </form>
        </div>
    </div>
    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        ClassicEditor
            .create(document.querySelector('#editor'))
            .catch(error => {
                console.error(error);
            });
    </script>
@endsection
