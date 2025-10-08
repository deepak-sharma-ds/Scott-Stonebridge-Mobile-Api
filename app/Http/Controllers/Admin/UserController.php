<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Prescriber;
use Spatie\Permission\Models\Role;
use DB;
use Hash;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Mail\SendMail;
use Illuminate\Support\Facades\Mail;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserController extends Controller
{


    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!auth()->check() || !auth()->user()->hasRole('Admin')) {
                abort(403, 'Access denied');
            }
            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = User::query();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }
        $data = $query->latest()->paginate(config('Reading.nodes_per_page'))->appends($request->all());
        $roles = \Spatie\Permission\Models\Role::pluck('name', 'name');

        return view('rbac.users.index', compact('data', 'roles'))
            ->with('i', ($request->input('page', 1) - 1) * config('Reading.nodes_per_page'));
    }

    public function create(): View
    {

        $roles = Role::pluck('name', 'name')->all();

        return view('rbac.users.create', compact('roles'));
    }

    // public function store(Request $request): RedirectResponse
    // {

    //     $this->validate($request, [
    //         'name' => 'required',
    //         'email' => 'required|email|unique:users,email',
    //         'password' => 'required|same:confirm-password',
    //         'roles' => 'required'
    //     ]);

    //     // Create the user first
    //     $user = User::create([
    //         'name' => $request->name,
    //         'email' => $request->email,
    //         'password' => Hash::make($request->password),
    //     ]);
    //     // Assign roles to the user
    //     $user->assignRole($request->input('roles'));

    //     // Handle signature image upload
    //     $signatureImage = null;
    //     if ($request->hasFile('signature')) {
    //         $signatureImage = $this->imageSave($request);
    //     }
    //     // Create the prescriber record
    //     Prescriber::create([
    //         'user_id' => $user->id,
    //         'gphc_number' => $request->gphc_number ?? '',
    //         'signature_image' => $signatureImage,
    //     ]);

    //     $token = Password::createToken($user);

    //     $resetLink = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));


    //     $template = EmailTemplate::where('identifier', 'register_mail')->first();

    //     $data = [
    //         'name' => $user->name,
    //         'email' => $user->email,
    //         'signature_image' => asset('admin/signature-images/' . $signatureImage),
    //         'gphc_number' => $request->gphc_number,
    //         'role' => $request->roles[0] ?? '',
    //         // add more keys as needed
    //     ];

    //     // Replace all {key} with actual values
    //     $parsedSubject = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($data) {
    //         return $data[$matches[1]] ?? ''; // Return empty string if key not found
    //     }, $template->subject ?? '');

    //     $parsedBody = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($data) {
    //         return $data[$matches[1]] ?? ''; // Return empty string if key not found
    //     }, $template->body ?? '');

    //     Mail::to($user->email)->send(new SendMail([
    //         'subject' => $parsedSubject,
    //         'body' => $parsedBody,
    //     ]));

    //     return redirect()->route('users.index')
    //         ->with('success', 'User created successfully');
    // }


    // public function store(Request $request): RedirectResponse
    // {
    //     $this->validate($request, [
    //         'name' => 'required',
    //         'email' => 'required|email|unique:users,email',
    //         'password' => [
    //             'required',
    //             'same:confirm-password',
    //             'min:8',
    //             'max:16',
    //             'regex:/^(?=.*[\W_]).{8,16}$/'
    //         ],
    //         'roles' => 'required'
    //     ]);
    //     // Create the user first
    //     $user = User::create([
    //         'name' => $request->name,
    //         'email' => $request->email,
    //         'password' => Hash::make($request->password),
    //     ]);

    //     Log::info('User created with ID: ' . $user->id);

    //     // Assign roles to the user
    //     $user->assignRole($request->input('roles'));

    //     // Handle signature image upload
    //     $signatureImage = null;
    //     if ($request->hasFile('signature')) {
    //         $signatureImage = $this->imageSave($request);
    //         Log::info('Signature image uploaded: ' . $signatureImage);
    //     }

    //     // Create the prescriber record
    //     Prescriber::create([
    //         'user_id' => $user->id,
    //         'gphc_number' => $request->gphc_number ?? '',
    //         'signature_image' => $signatureImage,
    //     ]);

    //     // Generate password reset token and link
    //     $token = Password::createToken($user);
    //     $resetLink = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));

    //     // Load email template
    //     $template = EmailTemplate::where('identifier', 'register_mail')->first();

    //     if (!$template) {
    //         Log::error('Email template with identifier "register_mail" not found.');
    //         return redirect()->route('users.index')->with('error', 'Email template not found.');
    //     }

    //     // Prepare data for email
    //     $data = [
    //         'name' => $user->name,
    //         'email' => $user->email,
    //         'signature_image' => asset('admin/signature-images/' . $signatureImage),
    //         'gphc_number' => $request->gphc_number,
    //         'role' => $request->roles[0] ?? '',
    //         'reset_link' => '<a href="' . $resetLink . '" target="_blank">Click Here</a>',
    //     ];

    //     // Replace placeholders in subject and body
    //     $parsedSubject = preg_replace_callback('/\{(\w+)\}/', fn($m) => $data[$m[1]] ?? '', $template->subject ?? 'Welcome');
    //     $parsedBody = preg_replace_callback('/\{(\w+)\}/', fn($m) => $data[$m[1]] ?? '', $template->body ?? '');

    //     Log::info('Parsed email subject: ' . $parsedSubject);

    //     // Send the email
    //     try {
    //         Mail::to($user->email)->send(new SendMail([
    //             'subject' => $parsedSubject,
    //             'body' => $parsedBody,
    //         ]));
    //         Log::info('Registration email sent to ' . $user->email);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to send registration email to ' . $user->email . ': ' . $e->getMessage());
    //     }

    //     return redirect()->route('users.index')
    //         ->with('success', 'User created successfully. Password reset link sent.');
    // }
    public function store(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'roles' => 'required'
        ]);

        // Generate random password (8-12 characters with special characters)
        $generatedPassword = Str::random(rand(8, 12)) . '!';

        // Create the user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($generatedPassword),
        ]);
        // dd($request->all());

        Log::info('User created with ID: ' . $user->id);

        // Assign roles to the user
        $user->assignRole($request->input('roles'));

        // Handle signature image upload
        $signatureImage = null;
        if ($request->hasFile('signature')) {
            $signatureImage = $this->imageSave($request);
            Log::info('Signature image uploaded: ' . $signatureImage);
        }

        // Create the prescriber record
        Prescriber::create([
            'user_id' => $user->id,
            'gphc_number' => $request->gphc_number ?? '',
            'signature_image' => $signatureImage,
            'registration_number' => $request->registration_number ?? ''
        ]);

        // Load email template
        $template = EmailTemplate::where('identifier', 'register_mail')->first();

        if (!$template) {
            Log::error('Email template with identifier "register_mail" not found.');
            return redirect()->route('users.index')->with('error', 'Email template not found.');
        }

        // Prepare data for email
        $data = [
            'name' => $user->name,
            'email' => $user->email,
            'signature_image' => asset('admin/signature-images/' . $signatureImage),
            'gphc_number' => $request->gphc_number,
            'role' => $request->roles[0] ?? '',
            'generated_password' => $generatedPassword, // Include plain password in mail
            'base_url' => '<a href="' . url('/') . '">' . url('/') . '</a>',
        ];

        // Replace placeholders in subject and body
        $parsedSubject = preg_replace_callback('/\{(\w+)\}/', fn($m) => $data[$m[1]] ?? '', $template->subject ?? 'Welcome');
        $parsedBody = preg_replace_callback('/\{(\w+)\}/', fn($m) => $data[$m[1]] ?? '', $template->body ?? '');

        Log::info('Parsed email subject: ' . $parsedSubject);

        // Send the email
        try {
            Mail::to($user->email)->send(new SendMail([
                'subject' => $parsedSubject,
                'body' => $parsedBody,
            ]));
            Log::info('Registration email sent to ' . $user->email);
        } catch (\Exception $e) {
            Log::error('Failed to send registration email to ' . $user->email . ': ' . $e->getMessage());
        }

        return redirect()->route('users.index')
            ->with('success', 'User created successfully. Credentials sent via email.');
    }




    public function edit($id): View
    {
        $user = User::find($id);
        $roles = Role::pluck('name', 'name')->all();
        $prescriber = Prescriber::where('user_id', $id)->first();
        $userRole = $user->roles->pluck('name', 'name')->all();

        return view('rbac.users.edit', compact('user', 'roles', 'userRole', 'prescriber'));
    }


    public function update(Request $request, $id): RedirectResponse
    {

        $this->validate($request, [
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . $id,
            'roles' => 'required',
            'status' => 'required',
        ]);

        $input = $request->all();
        if (isset($input['gphc_number']) && isset($input['signature']) && !empty($input['gphc_number']) && !empty($input['signature'])) {
            unset($input['gphc_number']);
            unset($input['signature']);
        }
        if (isset($input['registration_number']) && !empty($input['registration_number'])) {
            unset($input['registration_number']);
        }

        $user = User::find($id);
        $user->update($input);
        \DB::table('model_has_roles')->where('model_id', $id)->delete();

        $user->assignRole($request->input('roles'));

        if ($request->has('gphc_number') || $request->hasFile('signature') || $request->has('registration_number')) {
            $data = [];

            if ($request->filled('gphc_number')) {
                $data['gphc_number'] = $request->gphc_number;
            }

            if ($request->hasFile('signature')) {
                $data['signature_image'] = $this->imageSave($request);
            }

            if ($request->filled('registration_number')) {
                $data['registration_number'] = $request->registration_number;
            }
            if (!empty($data)) {
                Prescriber::updateOrCreate(
                    ['user_id' => $user->id],
                    $data
                );
            }
        }

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully');
    }

    public function destroy($id): RedirectResponse
    {
        User::find($id)->delete();
        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully');
    }

    private function imageSave($request)
    {
        $fileName = '';

        // Check if file exists in request
        if (!$request->hasFile('signature')) {
            return $fileName;
        }

        $image = $request->file('signature');

        // Generate a unique filename
        $originalName = $image->getClientOriginalName();
        $extension = $image->getClientOriginalExtension();
        $name = pathinfo($originalName, PATHINFO_FILENAME); // Get filename without extension

        $fileName = $name . '-' . time() . '.' . $extension;

        // Define upload path (inside public folder)
        // $uploadPath = public_path('admin/signature-images');

        $directory = 'signature-images';
        $uploadPath = Storage::disk('public')->path($directory);
        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        Storage::disk('public')->putFileAs($directory, $request->file('signature'), $fileName);

        $filePath = "signature-images/{$fileName}";

        // Create directory if it doesn't exist
        // if (!file_exists($uploadPath)) {
        //     mkdir($uploadPath, 0755, true); // 0755 = directory permissions
        // }

        // // Move the file to the public path
        // $image->move($uploadPath, $fileName);

        // Return the relative path (e.g., 'admin/signature-images/filename.jpg')
        return  $fileName;
    }
}
