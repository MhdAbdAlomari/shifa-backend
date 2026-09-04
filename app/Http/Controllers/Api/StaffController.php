<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index()
    {
        return UserResource::collection(
            User::whereIn('role', ['coordinator', 'surgeon'])->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:coordinator,surgeon'],
            'specialty' => ['nullable', 'string', 'max:255', 'required_if:role,surgeon'],
        ]);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $staff)
    {
        return new UserResource($staff);
    }

    public function update(Request $request, User $staff)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($staff->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'in:coordinator,surgeon'],
            'specialty' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $staff->update($data);
        return new UserResource($staff);
    }

    public function destroy(User $staff)
    {
        $staff->delete();
        return response()->json(['message' => 'Staff deleted']);
    }
}
