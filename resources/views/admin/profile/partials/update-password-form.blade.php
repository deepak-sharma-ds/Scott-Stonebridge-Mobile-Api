<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div class="row">
            <div class="col-md-12">
                <div class="row">
                    <label for="update_password_current_password" class="col-sm-3 col-form-label">Current Password</label>
                    <div class="col-sm-6 form-group">
                        <input id="update_password_current_password" name="current_password" placeholder="Current Password" type="password" class="form-control" autocomplete="current-password" />
                        <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2 text-danger" />
                    </div>
                </div>
            </div>

            <div class="col-md-12">
                <div class="row">
                    <label for="update_password_password" class="col-sm-3 col-form-label">New Password</label>

                    <div class="col-sm-6 form-group">
                        <input id="update_password_password" name="password" type="password" placeholder="New Password" class="form-control" autocomplete="new-password" />
                        <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2 text-danger" />
                    </div>
                </div>
            </div>

            <div class="col-md-12">
                <div class="row">
                    <label for="update_password_password_confirmation" class="col-sm-3 col-form-label">Confirm Password</label>

                    <div class="col-sm-6 form-group">
                        <input id="update_password_password_confirmation" name="password_confirmation" placeholder="Confirm Password" type="password" class="form-control" autocomplete="new-password" />
                        <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2 text-danger" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4">
        <button class="btn btn-primary">{{ __('Save') }}</button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
